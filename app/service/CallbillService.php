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
     *
     * 修复：新增 channelId 参数，Order 查询时增加 channel_id 过滤，
     * 防止 A 通道收到的金额错误核销 B 通道的同等金额待付订单
     *
     * @param  string $appName   应用类型 (alipay_asst/wxpay_asst)
     * @param  string $deviceId  挂机设备唯一标识
     * @param  float  $money     到账金额
     * @param  string $remark    账单备注/备注单号
     * @param  int    $channelId 来源通道 ID（0 表示未知通道，降级为全局匹配）
     */
    public function processPush(
        string $appName,
        string $deviceId,
        float  $money,
        string $remark,
        int    $channelId = 0
    ): array {
        // 1. 写入原始账单日志（记录 channel_id 便于追溯来源通道）
        $bill = Callbill::create([
            'app_name'    => $appName,
            'device_id'   => $deviceId,
            'money'       => $money,
            'remark'      => $remark,
            'channel_id'  => $channelId,
            'status'      => 0,
            'create_time' => time(),
        ]);

        // 2. 匹配"待支付"且在有效期内、实际支付金额（price）一致的单据
        //    注意：助手上报的是到账金额，对应 price 字段（含微浮动），而非原始 amount
        $now          = time();
        $priceFormatted = number_format($money, 2, '.', '');

        $query = Order::where('status', 0)
            ->where('price', $priceFormatted)
            ->where('expire_time', '>', $now);

        // 若上报了有效 channel_id，则限定通道范围，防止跨通道误匹配
        if ($channelId > 0) {
            $query->where('channel_id', $channelId);
        }

        $order = $query->orderBy('id', 'desc')->first();

        if (!$order) {
            // 若指定通道无匹配，且 channelId > 0，降级为全局匹配（兼容通道配置变更场景）
            if ($channelId > 0) {
                $order = Order::where('status', 0)
                    ->where('price', $priceFormatted)
                    ->where('expire_time', '>', $now)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            if (!$order) {
                $bill->status = 2; // 无匹配订单
                $bill->save();
                return ['success' => false, 'msg' => '暂无匹配的待支付订单'];
            }
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
