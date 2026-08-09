<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Order;
use app\service\OrderService;
use support\AuditLog;

/**
 * 管理员后台订单高级查询、强制补单与手动退款控制器
 */
class OrderAdminController
{
    private OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 订单高级检索 (支持多条件筛选)
     */
    public function list(\support\Request $request): string
    {
        $tradeNo    = trim((string)($request->get('trade_no') ?? ''));
        $merchantId = trim((string)($request->get('merchant_id') ?? ''));
        $status     = $request->get('status') ?? '';
        $pageSize   = max(1, min(100, (int)$request->get('page_size', 20)));

        if (strlen($tradeNo) > 64 || ($merchantId !== '' && !ctype_digit($merchantId))) {
            return json_encode(['code' => -1, 'msg' => '订单检索条件不合法'], JSON_UNESCAPED_UNICODE);
        }
        if ($status !== '' && !in_array((string)$status, ['0', '1', '2', '3'], true)) {
            return json_encode(['code' => -1, 'msg' => '订单状态筛选值不合法'], JSON_UNESCAPED_UNICODE);
        }

        $query = Order::query()
            ->leftJoin('cx_merchant', 'cx_order.merchant_id', '=', 'cx_merchant.id')
            ->select('cx_order.*', 'cx_merchant.pid as merchant_pid');

        if (!empty($tradeNo)) {
            $escaped = addcslashes($tradeNo, '%_\\');
            $query->where(function ($builder) use ($escaped): void {
                $builder->where('cx_order.trade_no', 'like', "%{$escaped}%")
                    ->orWhere('cx_order.out_trade_no', 'like', "%{$escaped}%");
            });
        }

        if (!empty($merchantId)) {
            $query->where('cx_order.merchant_id', (int)$merchantId);
        }

        if ($status !== '') {
            $query->where('cx_order.status', (int)$status);
        }

        $orders = $query->orderBy('cx_order.id', 'desc')->paginate($pageSize);

        return json_encode([
            'code' => 1,
            'data' => $orders
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 手动关闭 / 作废订单
     */
    public function close(\support\Request $request): string
    {
        $tradeNo = $request->post('trade_no') ?? '';
        $order   = Order::where('trade_no', $tradeNo)->first();

        if (!$order) {
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }

        if (!$this->orderService->closePendingOrder((string)$order->trade_no, '管理员关闭订单')) {
            return json_encode(['code' => -1, 'msg' => '仅待支付订单可以关闭，当前状态已变化'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['code' => 1, 'msg' => '订单已成功手动作废关闭'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 管理员强制补单
     */
    public function forceNotifyOrder(\support\Request $request): string
    {
        $params   = $request->post();
        $tradeNo  = $params['trade_no'] ?? '';
        $operator = AuditLog::currentOperator();
        $ip       = AuditLog::currentIp();

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            AuditLog::record($operator, 'force_pay', ['trade_no' => $tradeNo, 'reason' => '订单不存在'], 'fail', $ip);
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }

        if ((int)$order->status === 1) {
            AuditLog::record($operator, 'resend_notify', ['trade_no' => $tradeNo], 'success', $ip);
            return json_encode($this->orderService->resendNotify((string)$order->trade_no), JSON_UNESCAPED_UNICODE);
        }

        $success = $this->orderService->markAsPaid(
            (string)$order->trade_no,
            'MANUAL_' . time(),
            (float)$order->price,
            (int)$order->channel_id,
            false
        );

        AuditLog::record($operator, 'force_pay', [
            'trade_no'   => $tradeNo,
            'amount'     => (string)$order->price,
            'channel_id' => (int)$order->channel_id,
        ], $success ? 'success' : 'fail', $ip);

        if (!$success) {
            return json_encode(['code' => -1, 'msg' => '补单失败，订单状态不允许核销'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'code' => 1,
            'msg'  => '订单已按统一结算流程补单，商户通知已进入队列',
        ], JSON_UNESCAPED_UNICODE);
    }
}
