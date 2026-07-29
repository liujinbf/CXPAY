<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayOrder as OrderModel;
use app\common\model\PayChannel;
use app\common\model\PayCtype;
use app\common\model\PayCallbill;
use app\admin\model\User;
use app\core\SettlementService;
use app\core\CallbackService;

/**
 * 订单管理（只读为主：订单由下单接口产生，后台不新增）
 * 提供：手动补单/重发回调、订单详情
 */
class Order extends Backend
{
    protected object $model;

    protected string|array $quickSearchField = ['trade_no', 'out_trade_no'];

    protected string|array $preExcludeFields = ['create_time', 'update_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new OrderModel();
    }

    /**
     * 订单列表：附加商户对接PID
     *   - 普通订单：order.pid(=user.id) → 该会员的对接PID
     *   - 在线充值订单(param=recharge:{uid})：取付款商户的对接PID
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }
        [$where, $alias, $limit, $order] = $this->queryBuilder();
        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        $items = $res->items();
        $uids  = [];
        foreach ($items as $it) {
            $uids[] = $this->orderMerchantUid($it);
        }
        $uids   = array_values(array_filter(array_unique($uids)));
        $pidMap = $uids ? User::whereIn('id', $uids)->column('pid', 'id') : [];
        // 所属通道名称：channel_id → PayChannel.c_type → PayCtype.name
        $chIds      = array_values(array_filter(array_unique(array_column($items, 'channel_id'))));
        $chTypes    = $chIds ? PayChannel::whereIn('id', $chIds)->column('c_type', 'id') : [];
        $ctypeNames = PayCtype::column('name', 'c_type');
        foreach ($items as $it) {
            $it->merchant_pid = (string) ($pidMap[$this->orderMerchantUid($it)] ?? '');
            $cType = $chTypes[$it['channel_id']] ?? '';
            $it->channel_name = $ctypeNames[$cType] ?? ($cType ?: '-');
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 解析订单归属商户的 user.id（充值订单取付款商户，普通订单取 pid）
     */
    protected function orderMerchantUid($order): int
    {
        $param = (string) ($order['param'] ?? '');
        if (strpos($param, 'recharge:') === 0) {
            return (int) substr($param, strlen('recharge:'));
        }
        return (int) ($order['pid'] ?? 0);
    }

    /**
     * 禁止后台手动新增订单
     */
    public function add(): void
    {
        $this->error('订单由下单接口产生，不支持后台新增');
    }

    /**
     * 订单详情
     */
    public function detail(): void
    {
        $tradeNo = $this->request->param('trade_no', '');
        $order   = OrderModel::where('trade_no', $tradeNo)->find();
        if (!$order) {
            $this->error('订单不存在');
        }
        $arr = $order->toArray();
        $arr['status_text']  = ['未支付', '已支付', '已超时'][(int) $order->status] ?? '未知';
        $arr['merchant_pid'] = (string) (User::where('id', $this->orderMerchantUid($arr))->value('pid') ?: '');
        $arr['is_recharge']  = strpos((string) ($arr['param'] ?? ''), 'recharge:') === 0;
        $this->success('', ['row' => $arr]);
    }

    /**
     * 手动补单 / 重发回调
     *   - 未支付订单：插入匹配账单 → 走结算(置态+扣费+回调)；结算未匹配则强制置态+回调兜底
     *   - 已支付订单：仅重发商户回调
     */
    public function callback(): void
    {
        $tradeNo = $this->request->param('trade_no', '');
        $order   = OrderModel::where('trade_no', $tradeNo)->find();
        if (!$order) {
            $this->error('订单不存在');
        }
        $user = User::find($order->pid); // pid = ba_user.id
        if (!$user) {
            $this->error('商户不存在');
        }

        // 已支付 → 重发回调
        if ((int) $order->status === 1) {
            $co = $order->toArray();
            $co['pid'] = $user->pid; // 回调对外用商户对接PID
            $notified = (new CallbackService())->notifyMerchant($co, $user->pay_key);
            $this->success($notified ? '回调已重发并成功' : '回调已重发（商户未返回成功）', ['notified' => $notified]);
        }

        // 未支付 → 强制补单（必扣手续费）+ 回调
        $ok = (new SettlementService())->forceSettle($order->toArray());
        if (!$ok) {
            $this->error('补单失败，订单状态异常');
        }
        $co = $order->toArray();
        $co['pid'] = $user->pid; // 回调对外用商户对接PID
        $notified = (new CallbackService())->notifyMerchant($co, $user->pay_key);
        $this->success('补单成功（已扣手续费）' . ($notified ? '，回调已通知' : '，回调未成功'), ['notified' => $notified]);
    }
}
