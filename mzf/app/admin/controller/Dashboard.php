<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\admin\model\User;
use app\common\model\PayOrder;
use app\common\model\PayChannel;
use think\facade\Config;
use think\facade\Db;

class Dashboard extends Backend
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function index(): void
    {
        $now = time();

        // ==== 概览统计 ====
        $totalUsers   = User::count();
        $memberCount  = User::where('packvip_time', '>', $now)->count();
        $channelCount = PayChannel::count();
        $successMoney = (string) (PayOrder::where('status', 1)->sum('money') ?: '0.00');
        $totalOrders  = PayOrder::count();

        // ==== 各通道收入（成功订单按 type 聚合）====
        $incomeRows = PayOrder::where('status', 1)->field('type, SUM(money) money')->group('type')->select()->toArray();
        $incomeMap  = [];
        foreach ($incomeRows as $r) $incomeMap[$r['type']] = (string) ($r['money'] ?: '0.00');
        $income = [
            'wxpay'  => $incomeMap['wxpay']  ?? '0.00',
            'alipay' => $incomeMap['alipay'] ?? '0.00',
            'qqpay'  => $incomeMap['qqpay']  ?? '0.00',
        ];

        // ==== 三网收入趋势（近 13 天）====
        $trend = $this->buildTrend($now);

        // ==== 充值金额 / 手续费收入（今日/昨日/本周/本月）====
        $todayStart     = strtotime(date('Y-m-d'));
        $yesterdayStart = $todayStart - 86400;
        $weekStart      = strtotime('monday this week');
        $monthStart     = strtotime(date('Y-m-01'));

        // 充值：param LIKE 'recharge:%' 且成功，按 pay_time
        $rechargeSum = function (int $from, ?int $to = null): string {
            $q = PayOrder::where('status', 1)->whereLike('param', 'recharge:%')->where('pay_time', '>=', $from);
            if ($to) $q->where('pay_time', '<', $to);
            return (string) ($q->sum('money') ?: '0.00');
        };
        $recharge = [
            'today'     => $rechargeSum($todayStart),
            'yesterday' => $rechargeSum($yesterdayStart, $todayStart),
            'week'      => $rechargeSum($weekStart),
            'month'     => $rechargeSum($monthStart),
        ];

        // 手续费：user_money_log 负额 + memo 含“手续费”，取绝对值(分→元)
        $feeSum = function (int $from, ?int $to = null): string {
            $q = Db::name('user_money_log')->where('money', '<', 0)->whereLike('memo', '%手续费%')->where('create_time', '>=', $from);
            if ($to) $q->where('create_time', '<', $to);
            $cents = (int) ($q->sum('money') ?: 0); // 负数
            return number_format(abs($cents) / 100, 2, '.', '');
        };
        $fee = [
            'today'     => $feeSum($todayStart),
            'yesterday' => $feeSum($yesterdayStart, $todayStart),
            'week'      => $feeSum($weekStart),
            'month'     => $feeSum($monthStart),
        ];

        // ==== 商户流水排行（总，Top10）====
        $rankRows = PayOrder::where('status', 1)
            ->field('pid, SUM(money) total, COUNT(*) count')
            ->group('pid')->order('total', 'desc')->limit(10)->select()->toArray();
        $rankUids = array_column($rankRows, 'pid');
        $rankNames = $rankUids ? User::whereIn('id', $rankUids)->column('nickname', 'id') : [];
        $merchantRanking = [];
        foreach ($rankRows as $r) {
            $merchantRanking[] = [
                'pid'      => (int) $r['pid'],
                'nickname' => $rankNames[$r['pid']] ?? ('用户' . $r['pid']),
                'total'    => (string) ($r['total'] ?: '0.00'),
                'count'    => (int) $r['count'],
            ];
        }

        // ==== 最近订单（Top10）====
        $recentRows = PayOrder::order('create_time', 'desc')->limit(10)
            ->field('trade_no, pid, type, money, status, create_time')->select()->toArray();
        $recentUids = array_column($recentRows, 'pid');
        $recentNames = $recentUids ? User::whereIn('id', $recentUids)->column('nickname', 'id') : [];
        $recentOrders = [];
        foreach ($recentRows as $r) {
            $recentOrders[] = [
                'trade_no'    => $r['trade_no'],
                'nickname'    => $recentNames[$r['pid']] ?? ('用户' . $r['pid']),
                'type'        => $r['type'],
                'money'       => (string) $r['money'],
                'status'      => (int) $r['status'],
                'create_time' => (int) $r['create_time'],
            ];
        }

        // ==== 系统 / 云端信息 ====
        $cloud   = $this->cloudCheck();
        $cloudFallback = $cloud['msg'] ?? '无法连接云端';
        $system  = [
            'version'      => (string) (get_sys_config('version') ?: Config::get('buildadmin.version', '-')),
            'php_version'  => PHP_VERSION,
            'cloud_msg'    => isset($cloud['cloud_msg']) ? trim(strip_tags((string) $cloud['cloud_msg'])) : trim(strip_tags((string) $cloudFallback)),
            'auth_endtime' => isset($cloud['auth_endtime']) ? trim(strip_tags((string) $cloud['auth_endtime'])) : trim(strip_tags((string) $cloudFallback)),
            'agent_qq'     => $cloud['agent_qq'] ?? '-',
        ];

        $this->success('', [
            'remark'           => get_route_remark(),
            'total_users'      => $totalUsers,
            'member_count'     => $memberCount,
            'channel_count'    => $channelCount,
            'success_money'    => $successMoney,
            'total_orders'     => $totalOrders,
            'income'           => $income,
            'recharge'         => $recharge,
            'fee'              => $fee,
            'trend'            => $trend,
            'merchant_ranking' => $merchantRanking,
            'recent_orders'    => $recentOrders,
            'system'           => $system,
        ]);
    }

    /**
     * 近 13 天三网收入趋势
     */
    protected function buildTrend(int $now): array
    {
        $days = 13;
        $start = strtotime(date('Y-m-d', strtotime("-" . ($days - 1) . " day")));
        $rows = PayOrder::where('status', 1)->where('pay_time', '>=', $start)
            ->field("FROM_UNIXTIME(pay_time, '%Y-%m-%d') d, type, SUM(money) m")
            ->group('d, type')->select()->toArray();
        $bucket = [];
        foreach ($rows as $r) {
            $bucket[$r['d']][$r['type']] = (float) $r['m'];
        }
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $md = date('m-d', strtotime("-$i day"));
            $trend[] = [
                'date'   => $md,
                'alipay' => number_format($bucket[$d]['alipay'] ?? 0, 2, '.', ''),
                'wxpay'  => number_format($bucket[$d]['wxpay'] ?? 0, 2, '.', ''),
                'qqpay'  => number_format($bucket[$d]['qqpay'] ?? 0, 2, '.', ''),
            ];
        }
        return $trend;
    }

    /**
     * 云端授权/版本检测（走 cloud 新协议，读缓存授权包 + 版本检查，失败不阻塞面板）
     */
    protected function cloudCheck(): array
    {
        try {
            $grant = \app\core\CloudAuth::grant(); // 读缓存(心跳已刷新)，不阻塞面板
            if (!$grant) {
                return [];
            }
            // 云端版本：优先取授权包的 cloud_vers（主系统最新版本），并附带更新提示
            $cloudVers = trim((string) ($grant['cloud_vers'] ?? ''));
            $updateTip = '';
            try {
                $upd = \app\core\CloudUpdate::check();
                $u   = $upd['data'] ?? [];
                if (!empty($u['has_update'])) {
                    $updateTip = '（有新版本 ' . ($u['version'] ?? '') . '）';
                }
            } catch (\Throwable $e) {
            }
            $cloudMsg = $cloudVers !== '' ? ($cloudVers . $updateTip) : '未知';

            $authExpire = (int) ($grant['features']['auth']['expire'] ?? ($grant['expire_time'] ?? 0));
            return [
                'cloud_msg'    => $cloudMsg,
                'auth_endtime' => $authExpire > 0 ? date('Y-m-d H:i:s', $authExpire) : '永久授权',
                'agent_qq'     => (string) ($grant['agent_qq'] ?? ($grant['qq'] ?? 'XLPAY')),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
