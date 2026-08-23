<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * 管理员后台交易统计报表 API Controller
 *
 * 提供多维度数据统计，支持按日/周/月聚合，以及 CSV 数据导出。
 * 所有查询结果均使用 Redis 缓存（60秒），降低大数据量下频繁全表扫描压力。
 */
class ReportController
{
    /** 报表最大查询天数（防止超大范围扫描） */
    private const MAX_DAYS = 90;

    /**
     * 全平台交易趋势统计（按日/周/月聚合）
     *
     * GET /api/admin/report/trend
     * 参数：
     *   period  : day|week|month（默认 day）
     *   start   : Y-m-d（默认 30 天前）
     *   end     : Y-m-d（默认今日）
     */
    public function trend(\support\Request $request): string
    {
        [$startTs, $endTs, $period, $err] = $this->parsePeriodParams($request);
        if ($err) {
            return json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE);
        }

        $cacheKey = 'cx:report:admin_trend:' . md5("{$period}:{$startTs}:{$endTs}");
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return json_encode(['code' => 1, 'data' => $cached], JSON_UNESCAPED_UNICODE);
        }

        $dateFormat = $this->dateFormat($period);
        $rows = DB::table('cx_order')
            ->selectRaw("
                DATE_FORMAT(FROM_UNIXTIME(create_time), '{$dateFormat}') AS period_label,
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN status = 1 THEN price ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN status = 1 THEN fee_amount ELSE 0 END) AS fee_amount
            ")
            ->whereBetween('create_time', [$startTs, $endTs])
            ->groupByRaw("DATE_FORMAT(FROM_UNIXTIME(create_time), '{$dateFormat}')")
            ->orderByRaw("period_label ASC")
            ->get()
            ->map(function ($row) {
                $settled     = (int)$row->paid_count + (int)$row->closed_count;
                $successRate = $settled > 0
                    ? round((int)$row->paid_count / $settled * 100, 2)
                    : 100.0;
                return [
                    'period'       => $row->period_label,
                    'total'        => (int)$row->total_count,
                    'paid'         => (int)$row->paid_count,
                    'closed'       => (int)$row->closed_count,
                    'amount'       => number_format((float)$row->paid_amount, 2, '.', ''),
                    'fee'          => number_format((float)$row->fee_amount, 2, '.', ''),
                    'success_rate' => $successRate,
                ];
            })
            ->all();

        $this->setCache($cacheKey, $rows, 60);
        return json_encode(['code' => 1, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 支付通道分布统计（按通道汇总笔数与金额）
     *
     * GET /api/admin/report/channel_dist
     * 参数：start, end（Y-m-d）
     */
    public function channelDist(\support\Request $request): string
    {
        [$startTs, $endTs, , $err] = $this->parsePeriodParams($request);
        if ($err) {
            return json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE);
        }

        $cacheKey = 'cx:report:admin_channel:' . md5("{$startTs}:{$endTs}");
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return json_encode(['code' => 1, 'data' => $cached], JSON_UNESCAPED_UNICODE);
        }

        $rows = DB::table('cx_order AS o')
            ->leftJoin('cx_pay_channel AS c', 'o.channel_id', '=', 'c.id')
            ->selectRaw('
                COALESCE(c.title, "未知通道") AS channel_title,
                c.c_type,
                COUNT(*) AS total_count,
                SUM(CASE WHEN o.status = 1 THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN o.status = 1 THEN o.price ELSE 0 END) AS paid_amount
            ')
            ->whereBetween('o.create_time', [$startTs, $endTs])
            ->groupBy('o.channel_id', 'c.title', 'c.c_type')
            ->orderByRaw('paid_amount DESC')
            ->get()
            ->map(fn($r) => [
                'channel' => $r->channel_title,
                'c_type'  => $r->c_type,
                'total'   => (int)$r->total_count,
                'paid'    => (int)$r->paid_count,
                'amount'  => number_format((float)$r->paid_amount, 2, '.', ''),
            ])
            ->all();

        $this->setCache($cacheKey, $rows, 60);
        return json_encode(['code' => 1, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户排行榜（按成交金额 TOP20）
     *
     * GET /api/admin/report/merchant_rank
     * 参数：start, end（Y-m-d）
     */
    public function merchantRank(\support\Request $request): string
    {
        [$startTs, $endTs, , $err] = $this->parsePeriodParams($request);
        if ($err) {
            return json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE);
        }

        $cacheKey = 'cx:report:admin_merchant:' . md5("{$startTs}:{$endTs}");
        $cached   = $this->getCache($cacheKey);
        if ($cached !== null) {
            return json_encode(['code' => 1, 'data' => $cached], JSON_UNESCAPED_UNICODE);
        }

        $rows = DB::table('cx_order AS o')
            ->leftJoin('cx_merchant AS m', 'o.merchant_id', '=', 'm.id')
            ->selectRaw('
                COALESCE(m.pid, "未知") AS pid,
                COALESCE(m.name, "未知商户") AS name,
                COUNT(*) AS total_count,
                SUM(CASE WHEN o.status = 1 THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN o.status = 1 THEN o.price ELSE 0 END) AS paid_amount,
                SUM(CASE WHEN o.status = 1 THEN o.fee_amount ELSE 0 END) AS fee_amount
            ')
            ->whereBetween('o.create_time', [$startTs, $endTs])
            ->where('o.business_type', 'payment')
            ->groupBy('o.merchant_id', 'm.pid', 'm.name')
            ->orderByRaw('paid_amount DESC')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'pid'    => $r->pid,
                'name'   => $r->name,
                'total'  => (int)$r->total_count,
                'paid'   => (int)$r->paid_count,
                'amount' => number_format((float)$r->paid_amount, 2, '.', ''),
                'fee'    => number_format((float)$r->fee_amount, 2, '.', ''),
            ])
            ->all();

        $this->setCache($cacheKey, $rows, 60);
        return json_encode(['code' => 1, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 导出交易统计 CSV 文件（最大 90 天）
     *
     * GET /api/admin/report/export_csv
     * 参数：start, end（Y-m-d）
     */
    public function exportCsv(\support\Request $request): \Webman\Http\Response
    {
        [$startTs, $endTs, , $err] = $this->parsePeriodParams($request);
        if ($err) {
            return response(
                json_encode(['code' => -1, 'msg' => $err], JSON_UNESCAPED_UNICODE),
                400,
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }

        $rows = DB::table('cx_order AS o')
            ->leftJoin('cx_merchant AS m', 'o.merchant_id', '=', 'm.id')
            ->leftJoin('cx_pay_channel AS c', 'o.channel_id', '=', 'c.id')
            ->selectRaw('
                o.trade_no,
                o.out_trade_no,
                COALESCE(m.pid, "") AS merchant_pid,
                COALESCE(m.name, "") AS merchant_name,
                o.pay_type,
                COALESCE(c.title, "") AS channel_title,
                o.amount,
                o.price,
                o.fee_amount,
                o.status,
                FROM_UNIXTIME(o.create_time, "%Y-%m-%d %H:%i:%s") AS create_time,
                IF(o.pay_time > 0, FROM_UNIXTIME(o.pay_time, "%Y-%m-%d %H:%i:%s"), "") AS pay_time
            ')
            ->whereBetween('o.create_time', [$startTs, $endTs])
            ->orderBy('o.id', 'asc')
            ->limit(50000) // 单次最多导出5万条
            ->get();

        $statusMap = [0 => '待支付', 1 => '已支付', 2 => '已关闭'];

        $csv  = "\xEF\xBB\xBF"; // UTF-8 BOM，兼容 Excel 打开中文
        $csv .= "平台流水号,商户订单号,商户PID,商户名称,支付类型,通道名称,下单金额,实付金额,手续费,状态,创建时间,支付时间\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row->trade_no,
                $row->out_trade_no,
                $row->merchant_pid,
                '"' . str_replace('"', '""', $row->merchant_name) . '"',
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

        $filename = 'cxpay_orders_' . date('Ymd', $startTs) . '_' . date('Ymd', $endTs) . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ─── 私有辅助方法 ──────────────────────────────────────────────────────

    /**
     * 解析并校验时间区间参数，返回 [$startTs, $endTs, $period, $error]
     */
    private function parsePeriodParams(\support\Request $request): array
    {
        $period = (string)($request->get('period', 'day'));
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            return [0, 0, 'day', 'period 参数只允许 day/week/month'];
        }

        $startStr = (string)($request->get('start', date('Y-m-d', strtotime('-30 days'))));
        $endStr   = (string)($request->get('end', date('Y-m-d')));

        $startTs = strtotime($startStr . ' 00:00:00');
        $endTs   = strtotime($endStr   . ' 23:59:59');

        if ($startTs === false || $endTs === false || $startTs > $endTs) {
            return [0, 0, $period, '时间参数格式不合法或开始日期大于结束日期'];
        }
        $days = (int)(($endTs - $startTs) / 86400);
        if ($days > self::MAX_DAYS) {
            return [0, 0, $period, '查询范围最大不超过 ' . self::MAX_DAYS . ' 天'];
        }

        return [$startTs, $endTs, $period, null];
    }

    private function dateFormat(string $period): string
    {
        return match ($period) {
            'week'  => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };
    }

    private function getCache(string $key): ?array
    {
        try {
            $redis   = \Webman\Redis\Client::connection();
            $cached  = $redis->get($key);
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
