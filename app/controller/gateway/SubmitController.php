<?php

declare(strict_types=1);

namespace app\controller\gateway;

use app\service\OrderService;
use support\Response;
use Throwable;

/**
 * 易支付 Submit 下单聚合网关与收银台控制器
 */
class SubmitController
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 易支付标准下单入口 /submit.php & /mapi.php
     */
    public function submit(\support\Request $request): Response
    {
        try {
            $params = $request->get() + $request->post();
            
            // 1. 创建订单 (包含验签/余额扣费/金额去重)
            $orderResult = $this->orderService->createOrder(
                $params,
                $this->baseUrl($request),
                'payment',
                $request->getRemoteIp()
            );

            // 2. 如果是 mapi.php 返回 JSON 响应
            $path = $request->path();
            if (str_contains($path, 'mapi')) {
                return json([
                    'code' => 1,
                    'msg'  => '下单成功',
                    'trade_no' => $orderResult['trade_no'],
                    'payurl'   => $orderResult['pay_url'],
                    'qrcode'   => $orderResult['pay_mode'] === 'qrcode' ? $orderResult['pay_url'] : '',
                ]);
            }

            // 3. 页面跳转至 H5/PC 自适应收银台
            $cashierUrl = "/cashier/index.html?trade_no={$orderResult['trade_no']}";
            return response("<script>location.href='{$cashierUrl}';</script>");
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function baseUrl(\support\Request $request): string
    {
        $configured = (string)config('app.url', '');
        if (filter_var($configured, FILTER_VALIDATE_URL)) {
            return rtrim($configured, '/');
        }
        $forwarded = strtolower((string)$request->header('x-forwarded-proto'));
        $scheme = in_array($forwarded, ['http', 'https'], true)
            ? $forwarded
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        return $scheme . '://' . $request->host();
    }
}
