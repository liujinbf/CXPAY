<?php

declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\Merchant;
use support\Sign;
use Exception;

/**
 * 异步重试商户通知与多线程守护进程服务
 */
class MerchantNotifyService
{
    /**
     * 向商户 notify_url 发起 HTTP 异步回调通知 (含 MD5 验签与 HTTP 响应匹配)
     *
     * @param Order $order 订单模型
     * @return bool 是否通知成功
     */
    public function notifyMerchant(Order $order): bool
    {
        if (empty($order->notify_url)) {
            return false;
        }

        $merchant = Merchant::find($order->merchant_id);
        if (!$merchant) {
            return false;
        }

        // 1. 使用 Sign 库构建发给商户的易支付规范回调包 (金额严格保留2位小数 "1.00")
        $notifyParams = Sign::buildMerchantNotifyData($order->toArray(), $merchant->key);

        // 2. 发起 HTTP GET/POST 异步回调请求
        try {
            $url = $order->notify_url;
            $queryString = http_build_query($notifyParams);
            $fullUrl = str_contains($url, '?') ? "{$url}&{$queryString}" : "{$url}?{$queryString}";

            $opts = [
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 5.0, // 5秒超时
                    'header'  => "User-Agent: CXPAY-Notify-Worker/2.0\r\n",
                ]
            ];
            $context = stream_context_create($opts);
            $response = @file_get_contents($fullUrl, false, $context);

            if ($response !== false && Sign::callbackNotify($response)) {
                // 回调成功
                $order->notify_status = 1;
                $order->save();
                return true;
            }
        } catch (\Throwable $e) {
            // 忽略临时网络波动，进入重试队
        }

        return false;
    }
}
