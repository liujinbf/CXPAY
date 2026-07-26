<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Order;
use app\model\Callbill;
use app\service\OrderService;
use support\Response;
use Throwable;

/**
 * 管理员人工补单与流水强插控制 API
 */
class CallbillAdminController
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 管理员人工补单冲销订单 /api/admin/order/manual_pay
     */
    public function manualPay(object $request): Response
    {
        try {
            $tradeNo = (string)($request->post('trade_no') ?? '');
            $remark  = (string)($request->post('remark') ?? '管理员手工补单强插核销');

            if (empty($tradeNo)) {
                return json(['code' => -1, 'msg' => '订单号 (trade_no) 不能为空']);
            }

            $order = Order::where('trade_no', $tradeNo)->first();
            if (!$order) {
                return json(['code' => -1, 'msg' => '未找到对应的订单']);
            }

            if ((int)$order->status === 1) {
                return json(['code' => 1, 'msg' => '该订单已经是已支付状态']);
            }

            // 触发人工标记成功
            $success = $this->orderService->markAsPaid($order->out_trade_no, 'MANUAL_' . time(), (float)$order->amount);

            if ($success) {
                // 记录补单日志
                Callbill::create([
                    'order_id'    => $order->id,
                    'trade_no'    => $order->trade_no,
                    'money'       => $order->amount,
                    'remark'      => $remark,
                    'create_time' => time(),
                ]);

                return json(['code' => 1, 'msg' => '手工补单成功，已向商户扣费并触发异步回调']);
            }

            return json(['code' => -1, 'msg' => '手工补单失败']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }
}
