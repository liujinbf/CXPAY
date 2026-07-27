<?php

declare(strict_types=1);

namespace app\service;

use app\model\Callbill;
use app\model\Order;
use app\service\OrderService;

/**
 * 挂机助手账单监听与自动挂账匹配服务 (含数据库匹配与幂等回调)
 */
class CallbillService
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 接收挂机助手推送的账单消息
     */
    public function processPush(string $appName, string $deviceId, float $money, string $remark): array
    {
        // 1. 写入原始账单日志
        $bill = Callbill::create([
            'app_name'    => $appName,
            'device_id'   => $deviceId,
            'money'       => $money,
            'remark'      => $remark,
            'status'      => 0,
            'create_time' => time(),
        ]);

        // 2. 匹配"待支付"且在有效期内、实际支付金额（price）一致的单据
        //    注意：助手上报的是到账金额，对应 price 字段（含微浮动），而非原始 amount
        $now = time();
        $order = Order::where('status', 0)
            ->where('price', number_format($money, 2, '.', ''))
            ->where('expire_time', '>', $now)
            ->orderBy('id', 'desc')
            ->first();

        if (!$order) {
            $bill->status = 2; // 无匹配订单
            $bill->save();
            return ['success' => false, 'msg' => '暂无匹配的待支付订单'];
        }

        // 3. 自动挂账并标记订单已支付
        $bill->trade_no = $order->trade_no;
        $bill->status   = 1;
        $bill->save();

        $this->orderService->markAsPaid($order->out_trade_no, 'ASST_' . $bill->id, $money);

        return [
            'success'          => true,
            'matched_trade_no' => $order->trade_no,
        ];
    }
}
