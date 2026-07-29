<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayOrder as OrderModel;
use app\common\model\PayChannel;
use app\common\model\PayCtype;
use app\core\SettlementService;
use app\admin\model\User;

/**
 * 充值订单（只读）——只展示后台/商户在线充值产生的订单（param 以 recharge: 开头）。
 * 充值商户 = 付款方(param="recharge:{user.id}")，订单归属 pid = 平台收款账号。
 */
class Recharge extends Backend
{
    protected object $model;

    protected string|array $quickSearchField = ['trade_no', 'out_trade_no'];

    protected string|array $preExcludeFields = ['create_time', 'update_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new OrderModel();
    }

    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }
        [$where, $alias, $limit, $order] = $this->queryBuilder();
        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->whereLike('param', 'recharge:%') // 仅充值订单
            ->order($order)
            ->paginate($limit);

        $items = $res->items();

        // 通道名称
        $chIds   = array_values(array_filter(array_unique(array_column($items, 'channel_id'))));
        $chTypes = $chIds ? PayChannel::whereIn('id', $chIds)->column('c_type', 'id') : [];
        $ctypeNames = PayCtype::column('name', 'c_type');
        // 充值商户（付款方）：param=recharge:{uid}
        $payerUids = [];
        foreach ($items as $it) {
            $payerUids[] = $this->payerUid($it);
        }
        $payerUids = array_values(array_filter(array_unique($payerUids)));
        $payerInfo = $payerUids ? User::whereIn('id', $payerUids)->column('nickname,pid', 'id') : [];

        foreach ($items as $it) {
            $cType = $chTypes[$it['channel_id']] ?? '';
            $it->channel_name = $ctypeNames[$cType] ?? ($cType ?: '-');
            $pu = $this->payerUid($it);
            $it->payer_nickname = $payerInfo[$pu]['nickname'] ?? ('用户' . $pu);
            $it->payer_pid      = (string) ($payerInfo[$pu]['pid'] ?? '');
            $it->status_text    = ['未支付', '已支付', '已超时'][(int) $it['status']] ?? '未知';
        }

        $this->success('', [
            'list'   => $items,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /** 禁止手动新增 */
    public function add(): void
    {
        $this->error('充值订单由充值流程产生，不支持新增');
    }

    /**
     * 手动补单：将未支付的充值订单置为已支付，并给付款商户余额充值（不扣费、不外发回调）。
     * 复用结算的 forceSettle —— 充值订单命中 creditRecharge 加余额。
     */
    public function recover(): void
    {
        $tradeNo = (string) $this->request->param('trade_no', '');
        $order   = $this->model->where('trade_no', $tradeNo)->find();
        if (!$order) {
            $this->error('订单不存在');
        }
        if (strpos((string) $order->param, 'recharge:') !== 0) {
            $this->error('非充值订单，不能在此补单');
        }
        if ((int) $order->status === 1) {
            $this->error('该订单已支付，无需补单');
        }
        $ok = (new SettlementService())->forceSettle($order->toArray(), '后台充值补单');
        if (!$ok) {
            $this->error('补单失败，订单状态异常');
        }
        $this->success('补单成功，已为商户余额充值');
    }

    /**
     * 删除：仅限充值订单（param 以 recharge: 开头），避免误删普通订单。
     */
    public function del(): void
    {
        $ids = $this->request->param('ids/a', []);
        if (!$ids) {
            $this->error('请选择要删除的订单');
        }
        $rows = $this->model->whereLike('param', 'recharge:%')->where('trade_no', 'in', $ids)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($rows as $row) {
                $count += $row->delete();
            }
            $this->model->commit();
        } catch (\Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
        $count ? $this->success('删除成功') : $this->error('没有删除任何行');
    }

    protected function payerUid($order): int
    {
        $param = (string) ($order['param'] ?? '');
        return strpos($param, 'recharge:') === 0 ? (int) substr($param, strlen('recharge:')) : 0;
    }
}
