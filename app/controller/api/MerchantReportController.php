<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Merchant;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 商户端交易统计报表 API Controller
 *
 * 只返回当前登录商户自己的数据，无法跨商户查询。
 * 所有查询结果使用 Redis 缓存（30秒）。
 */
class MerchantReportController
{
    /** 报表最大查询天数 */
    private const MAX_DAYS = 30;

    /**
     * 商户交易趋势统计（按日聚合）
     *
     * GET /api/merchant/report/trend
     * 参数：
     *   start : Y-m-d（默认 7 天前）
     *   end   : Y-m-d（默认今日）
     */
    public function trend(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }

        [$startTs, $endTs, $err] = $this->parseDateParams($request, 7);
        if ($err) {
            return json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE);
        }

        $cacheKey = 'cx:report:merchant_trend:' . $merchant->id . ':' . md5("{$startTs}:{$endTs}");
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return json_encode(['code' => 1, 'data' => $cached], JSON_UNESCAPED_UNICODE);
        }

        $rows = DB::table('cx_order')
            ->selectRaw("
                DATE_FORMAT(FROM_UNIXTIME(create_time), '%Y-%m-%d') AS period_label,
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN status = 1 THEN price ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN status = 1 THEN fee_amount ELSE 0 END) AS fee_amount
            ")
            ->where('merchant_id', $merchant->id)
            ->where('business_type', 'payment')
            ->whereBetween('create_time', [$startTs, $endTs])
            ->groupByRaw("DATE_FORMAT(FROM_UNIXTIME(create_time), '%Y-%m-%d')")
            ->orderByRaw('period_label ASC')
            ->get()
            ->map(function ($row) {
                $settled     = (int)$row->paid_count + (int)$row->closed_count;
                $successRate = $settled > 0
                    ? round((int)$row->paid_count / $settled * 100, 2)
                    : 100.0;
                return [
                    'date'         => $row->period_label,
                    'total'        => (int)$row->total_count,
                    'paid'         => (int)$row->paid_count,
                    'closed'       => (int)$row->closed_count,
                    'amount'       => number_format((float)$row->paid_amount, 2, '.', ''),
                    'fee'          => number_format((float)$row->fee_amount, 2, '.', ''),
                    'success_rate' => $successRate,
                ];
            })
            ->all();

        $this->setCache($cacheKey, $rows, 30);
        return json_encode(['code' => 1, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户支付类型分布（微信/支付宝/QQ 各占比）
     *
     * GET /api/merchant/report/pay_type_dist
     * 参数：start, end（Y-m-d）
     */
    public function payTypeDist(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }

        [$startTs, $endTs, $err] = $this->parseDateParams($request, 7);
        if ($err) {
            return json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE);
        }

        $cacheKey = 'cx:report:merchant_paytype:' . $merchant->id . ':' . md5("{$startTs}:{$endTs}");
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return json_encode(['code' => 1, 'data' => $cached], JSON_UNESCAPED_UNICODE);
        }

        $rows = DB::table('cx_order')
            ->selectRaw('
                pay_type,
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = 1 THEN price ELSE 0 END) AS paid_amount
            ')
            ->where('merchant_id', $merchant->id)
            ->where('business_type', 'payment')
            ->whereBetween('create_time', [$startTs, $endTs])
            ->groupBy('pay_type')
            ->get()
            ->map(fn($r) => [
                'pay_type' => $r->pay_type,
                'total'    => (int)$r->total_count,
                'paid'     => (int)$r->paid_count,
                'amount'   => number_format((float)$r->paid_amount, 2, '.', ''),
            ])
            ->all();

        $this->setCache($cacheKey, $rows, 30);
        return json_encode(['code' => 1, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户导出订单 CSV（仅自己的订单，最大 30 天 / 1万条）
     *
     * GET /api/merchant/report/export_csv
     * 参数：start, end（Y-m-d）
     */
    public function exportCsv(\support\Request $request): \Webman\Http\Response
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return response(
                json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE),
                401,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }

        [$startTs, $endTs, $err] = $this->parseDateParams($request, 7);
        if ($err) {
            return response(
                json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE),
                400,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }

        $rows = DB::table('cx_order AS o')
            ->leftJoin('cx_pay_channel AS c', 'o.channel_id', '=', 'c.id')
            ->selectRaw('
                o.trade_no,
                o.out_trade_no,
                o.pay_type,
                COALESCE(c.title, "") AS channel_title,
                o.amount,
                o.price,
                o.fee_amount,
                o.status,
                FROM_UNIXTIME(o.create_time, "%Y-%m-%d %H:%i:%s") AS create_time,
                IF(o.pay_time > 0, FROM_UNIXTIME(o.pay_time, "%Y-%m-%d %H:%i:%s"), "") AS pay_time
            ')
            ->where('o.merchant_id', $merchant->id)
            ->whereBetween('o.create_time', [$startTs, $endTs])
            ->orderBy('o.id', 'asc')
            ->limit(10000)
            ->get();

        $statusMap = [0 => '待支付', 1 => '已支付', 2 => '已关闭'];

        $csv  = "\xEF\xBB\xBF";
        $csv .= "平台流水号,商户订单号,支付类型,通道名称,下单金额,实付金额,手续费,状态,创建时间,支付时间\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->trade_no,
                $row->out_trade_no,
                $row->pay_type,
                '"' . str_replace('"', '""', $row->channel_title) . '"',
                number_format((float)$row->amount, 2, '.', ''),
                number_format((float)$row->price, 2, '.', ''),
                number_format((float)$row->fee_amount, 2, '.', ''),
                $statusMap[(int)$row->status] ?? '未知',
                $row->create_time,
                $row->pay_time,
            ]) . "\n";
        }

        $filename = 'orders_' . ($merchant->pid ?? $merchant->id) . '_'
            . date('Ymd', $startTs) . '_' . date('Ymd', $endTs) . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ─── 私有辅助方法 ──────────────────────────────────────────────────────

    private function parseDateParams(\support\Request $request, int $defaultDays): array
    {
        $startStr = (string)($request->get('start', date('Y-m-d', strtotime("-{$defaultDays} days"))));
        $endStr   = (string)($request->get('end', date('Y-m-d')));

        $startTs = strtotime($startStr . ' 00:00:00');
        $endTs   = strtotime($endStr   . ' 23:59:59');

        if ($startTs === false || $endTs === false || $startTs > $endTs) {
            return [0, 0, '时间参数格式不合法或开始日期大于结束日期'];
        }
        $days = (int)(($endTs - $startTs) / 86400);
        if ($days > self::MAX_DAYS) {
            return [0, 0, '商户报表查询范围最大不超过 ' . self::MAX_DAYS . ' 天'];
        }

        return [$startTs, $endTs, null];
    }

    private function currentMerchant(\support\Request $request): ?Merchant
    {
        $merchant = $request->context['merchant'] ?? null;
        if ($merchant instanceof Merchant) {
            return $merchant;
        }
        $merchantId = (int)$request->session()->get('merchant_id', 0);
        return $merchantId > 0 ? Merchant::find($merchantId) : null;
    }

    private function getCache(string $key): ?array
    {
        try {
            $redis  = \Webman\Redis\Client::connection();
            $cached = $redis->get($key);
            if ($cached) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function setCache(string $key, array $data, int $ttl): void
    {
        try {
            \Webman\Redis\Client::connection()->setex($key, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
        }
    }
}
