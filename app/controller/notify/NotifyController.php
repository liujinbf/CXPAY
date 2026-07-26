<?php

declare(strict_types=1);

namespace app\controller\notify;

use app\service\OrderService;
use app\payment\PaymentManager;
use support\Response;
use Throwable;

/**
 * 上游支付通道异步回调监听控制器
 */
class NotifyController
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 处理上游回调
     */
    public function index(object $request, string $cType): Response
    {
        try {
            $params = $request->get() + $request->post();

            // 1. 实例化上游驱动
            $driver = PaymentManager::make($cType);
            
            // 2. 调用驱动进行验签与状态解析
            $result = $driver->notify($params, []);

            if (!empty($result['success']) && !empty($result['out_trade_no'])) {
                // 3. 驱动验签成功，更新系统订单状态并向商户发送回调通知
                $this->orderService->markAsPaid(
                    $result['out_trade_no'],
                    $result['trade_no'] ?? '',
                    (float)($result['amount'] ?? 0)
                );

                return response('success');
            }

            return response('fail');
        } catch (Throwable $e) {
            return response('error: ' . $e->getMessage());
        }
    }
}
