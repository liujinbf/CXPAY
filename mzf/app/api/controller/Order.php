<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use app\common\model\PayOrder;
use app\common\model\PayChannel;
use app\common\model\PayCallbill;
use app\admin\model\User;
use app\common\service\OrderService;
use app\core\SettlementService;
use app\core\CallbackService;
use app\common\service\PayException;

/**
 * 商户中心 - 订单查询（仅本商户）
 */
class Order extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 本商户订单列表（分页）
     */
    public function index(): void
    {
        $uid      = $this->auth->id;
        // 商户即会员：pid = uid
        $limit  = (int) $this->request->param('limit', 15);
        $status = $this->request->param('status', '');
        $keyword = $this->request->param('keyword', '');

        $query = PayOrder::where('pid', $uid);
        if ($status !== '') {
            $query->where('status', (int) $status);
        }
        if ($keyword !== '') {
            $query->where(function ($w) use ($keyword) {
                $w->whereLike('trade_no', "%{$keyword}%")->whereOr('out_trade_no', 'like', "%{$keyword}%");
            });
        }

        $res = $query->field('trade_no,out_trade_no,name,type,money,price,status,channel_id,create_time,pay_time')
            ->order('create_time desc')
            ->paginate($limit);

        // 解析支付通道名称（channel_id → 通道类型名 / 备注）
        $items = $res->items();
        $chIds = array_values(array_filter(array_unique(array_column($items, 'channel_id'))));
        $chMap = [];
        if ($chIds) {
            foreach (PayChannel::whereIn('id', $chIds)->field('id,c_type,notes')->select()->toArray() as $c) {
                $chMap[$c['id']] = $c;
            }
        }
        $ctypeNames = \app\common\model\PayCtype::column('name', 'c_type');
        foreach ($items as &$it) {
            $ch = $chMap[$it['channel_id']] ?? null;
            if ($ch) {
                $nm = $ctypeNames[$ch['c_type']] ?? $ch['c_type'];
                $it['channel_name'] = $ch['notes'] ? ($nm . ' · ' . $ch['notes']) : $nm;
            } else {
                $it['channel_name'] = '-';
            }
        }
        unset($it);

        $this->success('', [
            'list'  => $items,
            'total' => $res->total(),
        ]);
    }

    /**
     * 发起支付测试：用本商户 pid + 通道走真实下单，返回收银台地址。
     * 回调地址由系统自动分配（本系统 /gateway/Submit/testNotify，自收自验），
     * 支付成功后收银台自动跳回「收款通道」页。
     */
    public function test(): void
    {
        if (!$this->request->isPost()) {
            $this->error('参数错误');
        }
        $type      = $this->request->param('type', '');
        $amount    = $this->request->param('amount', '');
        $channelId = (int) $this->request->param('channel_id', 0); // 指定通道(单独测试某条通道)
        if (!$type) {
            $this->error('请选择支付类型');
        }
        $domain    = $this->request->scheme() . '://' . $this->request->host(true);
        $notifyUrl = $domain . '/gateway/Submit/testNotify';       // 系统自动分配的回调地址
        $returnUrl = $domain . '/user/merchant/channels';          // 支付成功跳转到收款通道页
        try {
            $res = (new OrderService())->createTestOrderWith($this->auth->id, $type, (string) $amount, $notifyUrl, $returnUrl, $channelId);
        } catch (PayException $e) {
            $this->error($e->getMessage());
        } catch (\Throwable $e) {
            $this->error('系统繁忙，请稍后再试');
        }
        $res['pay_url'] = $this->request->scheme() . '://' . $this->request->host(true) . $res['pay_url'];
        $this->success('测试订单已创建', $res);
    }

    /**
    /**
     * 本商户手动补单 / 重发回调（仅本商户订单）
     *   - 未支付：补单(插账单走结算→置态+扣费+回调)，无通道/未匹配则强制置态+回调
     *   - 已支付：仅重发回调
     */
    public function callback(): void
    {
        if (!$this->request->isPost()) {
            $this->error('参数错误');
        }
        $uid     = $this->auth->id;
        $tradeNo = $this->request->param('trade_no', '');
        $order   = PayOrder::where(['trade_no' => $tradeNo, 'pid' => $uid])->find();
        if (!$order) {
            $this->error('订单不存在');
        }
        $user = User::find($uid);

        // 已支付 → 重发回调
        if ((int) $order->status === 1) {
            $notified = (new CallbackService())->notifyMerchant($order->toArray(), $user->pay_key);
            $this->success($notified ? '回调已重发并成功' : '回调已重发（下游未返回成功）', ['notified' => $notified]);
        }

        // 未支付 → 强制补单（必扣手续费）+ 回调
        $ok = (new SettlementService())->forceSettle($order->toArray());
        if (!$ok) {
            $this->error('补单失败，订单状态异常');
        }
        $notified = (new CallbackService())->notifyMerchant($order->toArray(), $user->pay_key);
        $this->success('补单成功（已扣手续费）' . ($notified ? '，回调已通知' : '，回调未成功'), ['notified' => $notified]);
    }

    /**
     * 本商户统计（今日/累计 成功笔数与金额）
     */
    public function stats(): void
    {
        $uid = $this->auth->id;
        $pid = $uid;
        $range = (string) $this->request->param('range', 'today');

        // 时间范围起点
        $now = time();
        switch ($range) {
            case 'week':  $start = strtotime('monday this week'); break;
            case 'month': $start = strtotime(date('Y-m-01')); break;
            case 'year':  $start = strtotime(date('Y-01-01')); break;
            case 'all':   $start = 0; break;
            default:      $range = 'today'; $start = strtotime(date('Y-m-d')); break;
        }

        // 成功订单基础查询（范围内）
        $paidBase = function () use ($pid, $start) {
            $q = PayOrder::where(['pid' => $pid, 'status' => 1]);
            if ($start > 0) $q->where('pay_time', '>=', $start);
            return $q;
        };
        // 范围内全部订单（用于成功率）
        $allInRange = function () use ($pid, $start) {
            $q = PayOrder::where('pid', $pid);
            if ($start > 0) $q->where('create_time', '>=', $start);
            return $q;
        };

        $sales      = (string) ($paidBase()->sum('money') ?: '0.00');
        $orderCount = $paidBase()->count();
        $totalInRange = $allInRange()->count();
        $paidInRange  = (clone $allInRange())->where('status', 1)->count();
        $successRate  = $totalInRange > 0 ? round($paidInRange / $totalInRange * 100, 1) : 0;

        // 通道数 / 在线数
        $channelCount = PayChannel::where('uid', $uid)->count();
        $onlineCount  = PayChannel::where(['uid' => $uid, 'status' => 1])->count();

        // 各支付方式收入：范围内(顶部) + 累计(底部)
        $typeList = ['wxpay' => '微信', 'alipay' => '支付宝', 'qqpay' => 'QQ'];
        $payIncome = [];
        foreach ($typeList as $t => $n) {
            $rq = PayOrder::where(['pid' => $pid, 'status' => 1, 'type' => $t]);
            if ($start > 0) $rq->where('pay_time', '>=', $start);
            $payIncome[$t] = [
                'type'  => $t,
                'name'  => $n,
                'range' => (string) ($rq->sum('money') ?: '0.00'),
                'total' => (string) (PayOrder::where(['pid' => $pid, 'status' => 1, 'type' => $t])->sum('money') ?: '0.00'),
            ];
        }

        // 收款趋势（按范围分桶）
        [$trend, $dist] = $this->buildTrendAndDist($pid, $range, $start, $now);

        $this->success('', [
            'range'                 => $range,
            'sales'                 => $sales,
            'order_count'           => $orderCount,
            'channel_count'         => $channelCount,
            'online_channel_count'  => $onlineCount,
            'success_rate'          => $successRate,
            'paid_in_range'         => $paidInRange,
            'total_in_range'        => $totalInRange,
            'pay_income'            => $payIncome,
            'trend'                 => $trend,
            'pay_dist'              => $dist,
            // 兼容旧字段
            'today_count' => PayOrder::where(['pid' => $pid, 'status' => 1])->where('pay_time', '>=', strtotime(date('Y-m-d')))->count(),
            'today_money' => (string) (PayOrder::where(['pid' => $pid, 'status' => 1])->where('pay_time', '>=', strtotime(date('Y-m-d')))->sum('money') ?: '0.00'),
            'total_count' => PayOrder::where(['pid' => $pid, 'status' => 1])->count(),
            'total_money' => (string) (PayOrder::where(['pid' => $pid, 'status' => 1])->sum('money') ?: '0.00'),
        ]);
    }

    /**
     * 构建收款趋势序列 + 支付方式分布
     * @return array{0:array,1:array} [trend, dist]
     */
    protected function buildTrendAndDist(int $pid, string $range, int $start, int $now): array
    {
        // 分桶：today=24小时；week=7天；month=按天；year=12月；all=按月(近12月)
        $buckets = [];
        if ($range === 'today') {
            for ($h = 0; $h < 24; $h++) {
                $bs = strtotime(date('Y-m-d')) + $h * 3600;
                $buckets[] = ['label' => sprintf('%02d:00', $h), 'start' => $bs, 'end' => $bs + 3600];
            }
        } elseif ($range === 'week') {
            for ($d = 0; $d < 7; $d++) {
                $bs = strtotime('monday this week') + $d * 86400;
                $buckets[] = ['label' => date('m-d', $bs), 'start' => $bs, 'end' => $bs + 86400];
            }
        } elseif ($range === 'month') {
            $days = (int) date('t');
            for ($d = 1; $d <= $days; $d++) {
                $bs = strtotime(date('Y-m-') . sprintf('%02d', $d));
                $buckets[] = ['label' => sprintf('%02d', $d), 'start' => $bs, 'end' => $bs + 86400];
            }
        } else { // year / all → 近12个月
            for ($m = 11; $m >= 0; $m--) {
                $bs = strtotime(date('Y-m-01', strtotime("-$m month")));
                $be = strtotime(date('Y-m-01', strtotime('-' . ($m - 1) . ' month')));
                if ($m === 0) $be = $now + 1;
                $buckets[] = ['label' => date('Y-m', $bs), 'start' => $bs, 'end' => $be];
            }
        }

        $trend = [];
        foreach ($buckets as $b) {
            $q = PayOrder::where(['pid' => $pid, 'status' => 1])
                ->where('pay_time', '>=', $b['start'])->where('pay_time', '<', $b['end']);
            $trend[] = [
                'label' => $b['label'],
                'money' => (string) ($q->sum('money') ?: '0.00'),
                'count' => (clone $q)->count(),
            ];
        }

        // 支付方式分布（范围内成功订单，按 type 聚合金额）
        $distQ = PayOrder::where(['pid' => $pid, 'status' => 1]);
        if ($start > 0) $distQ->where('pay_time', '>=', $start);
        $rows = $distQ->field('type, SUM(money) money, COUNT(*) count')->group('type')->select()->toArray();
        $typeName = ['alipay' => '支付宝', 'wxpay' => '微信', 'qqpay' => 'QQ钱包'];
        $dist = [];
        foreach ($rows as $r) {
            $dist[] = [
                'type'  => $r['type'],
                'name'  => $typeName[$r['type']] ?? $r['type'],
                'money' => (string) ($r['money'] ?: '0.00'),
                'count' => (int) $r['count'],
            ];
        }

        return [$trend, $dist];
    }
}
