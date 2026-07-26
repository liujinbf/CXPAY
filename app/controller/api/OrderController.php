<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Order;

/**
 * 商户 API 订单查询接口控制器
 */
class OrderController
{
    /**
     * 商户主动单据查询
     */
    public function query(object $request): string
    {
        $params = $request->get() + $request->post();
        $outTradeNo = $params['out_trade_no'] ?? '';
        $pid = (int)($params['pid'] ?? 0);

        if (empty($outTradeNo)) {
            return json_encode(['code' => -1, 'msg' => 'out_trade_no 不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $order = Order::where('merchant_id', $pid)
            ->where('out_trade_no', $outTradeNo)
            ->first();

        if (!$order) {
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'code'         => 1,
            'msg'          => '查询成功',
            'status'       => $order->status,
            'trade_no'     => $order->trade_no,
            'out_trade_no' => $order->out_trade_no,
            'amount'       => number_format((float)$order->amount, 2, '.', ''),
            'pay_time'     => $order->pay_time,
        ], JSON_UNESCAPED_UNICODE);
    }
}
