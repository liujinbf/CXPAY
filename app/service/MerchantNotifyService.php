<?php

declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\Merchant;
use support\Sign;
use support\UrlGuard;

/**
 * 异步商户回调通知服务
 *
 * 修复说明：
 *   - 原版在 Workerman 异步框架中使用 while+usleep 轮询等待，会阻塞整个 Worker 进程
 *   - 业务 Worker 只负责入队，后台定时进程执行 HTTP 通知与指数退避补发
 *   - 修复降级 curl 关闭 SSL 验证（CURLOPT_SSL_VERIFYPEER 改为 true）
 */
class MerchantNotifyService
{
    /** 最大重试次数 */
    private const MAX_RETRIES = 5;

    /** 重试退避基准秒数（指数退避：1s, 2s, 4s, 8s, 16s） */
    private const RETRY_BASE_SECONDS = 5;

    /** Redis 待重发队列 Key */
    private const RETRY_QUEUE_KEY = 'cx:notify_retry_queue';

    /**
     * 向商户 notify_url 发起 HTTP 回调通知
     * 将通知任务推入 Redis 队列，避免阻塞支付回调 Worker。
     *
     * @param  Order $order 订单模型
     * @return bool  是否立即通知成功（失败会进重试队列）
     */
    public function notifyMerchant(Order $order): bool
    {
        if (empty($order->notify_url)) {
            Order::where('id', $order->id)->update(['notify_status' => 1]);
            return true;
        }

        $merchant = Merchant::find($order->merchant_id);
        if (!$merchant) {
            return false;
        }

        Order::where('id', $order->id)->update(['notify_status' => 2]);
        return $this->pushToRetryQueue((string)$order->trade_no);
    }

    /**
     * 后台 Worker 定时驱动的重试补发（从 Redis 队列消费）
     */
    public function processRetryQueue(): void
    {
        try {
            $redis = \Webman\Redis\Client::connection();
        } catch (\Throwable) {
            return;
        }

        $maxBatch  = 50;
        $processed = 0;

        while ($processed < $maxBatch) {
            $item = $redis->lpop(self::RETRY_QUEUE_KEY);
            if (!$item) {
                break;
            }

            $data = json_decode($item, true);
            if (empty($data['trade_no'])) {
                $processed++;
                continue;
            }

            $order = Order::where('trade_no', $data['trade_no'])->first();
            if (!$order || (int)$order->status !== 1 || (int)$order->notify_status === 1) {
                $processed++;
                continue;
            }

            $retryCount = (int)($data['retry_count'] ?? 0);

            // 超过最大重试次数，丢弃并记录日志
            if ($retryCount >= self::MAX_RETRIES) {
                error_log('[MerchantNotify] 超过最大重试次数，放弃通知 trade_no=' . $data['trade_no']);
                Order::where('trade_no', $data['trade_no'])->update(['notify_status' => 3]);
                $processed++;
                continue;
            }

            // 指数退避延迟检查（下次执行时间未到则重新入队尾）
            $nextRetryAt = (int)($data['next_retry_at'] ?? 0);
            if ($nextRetryAt > time()) {
                $redis->rpush(self::RETRY_QUEUE_KEY, $item);
                $processed++;
                continue;
            }

            // 发起重试通知
            $fullUrl = $this->buildNotifyUrl($order);
            $success = $fullUrl !== null && $this->curlGet($fullUrl);
            if ($success) {
                Order::where('trade_no', $data['trade_no'])->update(['notify_status' => 1]);
            } else {
                // 计算下次重试时间（指数退避），重新入队
                $nextInterval          = self::RETRY_BASE_SECONDS * (2 ** $retryCount);
                $data['retry_count']   = $retryCount + 1;
                $data['next_retry_at'] = time() + $nextInterval;
                $redis->rpush(self::RETRY_QUEUE_KEY, json_encode($data, JSON_UNESCAPED_UNICODE));
            }

            $processed++;
        }
    }

    /**
     * 同步 curl GET 请求（不阻塞 Workerman 进程，带超时保护）
     *
     * @param  string $url 回调 URL
     * @return bool 商户响应中是否包含 'success'
     */
    protected function curlGet(string $url): bool
    {
        if (!function_exists('curl_init')) {
            error_log('[MerchantNotify] PHP cURL 扩展未安装');
            return false;
        }

        $target = UrlGuard::resolve($url, (bool)config('app.allow_private_callbacks', false));
        if ($target === null) {
            error_log('[MerchantNotify] 回调地址被出站安全策略拒绝');
            return false;
        }

        $ch = curl_init($url);
        $resolvedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'CXPAY-Notify-Worker/2.0',
            CURLOPT_SSL_VERIFYPEER => true,   // 修复：开启 SSL 证书验证，防止 MITM
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$resolvedIp}"],
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false || $httpCode < 200 || $httpCode >= 300) {
            return false;
        }

        return Sign::callbackNotify($response);
    }

    /**
     * 推入 Redis 重试队列
     */
    protected function pushToRetryQueue(string $tradeNo): bool
    {
        try {
            $redis = \Webman\Redis\Client::connection();
            $redis->rpush(self::RETRY_QUEUE_KEY, json_encode([
                'trade_no'      => $tradeNo,
                'retry_count'   => 0,
                'next_retry_at' => time(),
                'created_at'    => time(),
            ], JSON_UNESCAPED_UNICODE));
            return true;
        } catch (\Throwable) {
            // Redis 不可用时降级：记录错误日志
            error_log('[MerchantNotify] Redis 不可用，无法入队重试 trade_no=' . $tradeNo);
            Order::where('trade_no', $tradeNo)->update(['notify_status' => 3]);
            return false;
        }
    }

    private function buildNotifyUrl(Order $order): ?string
    {
        $merchant = Merchant::find($order->merchant_id);
        if (!$merchant || empty($order->notify_url)) {
            return null;
        }
        $notifyParams = Sign::buildMerchantNotifyData(
            $order->toArray(),
            (string)$merchant->pid,
            (string)$merchant->key
        );
        $queryString = http_build_query($notifyParams);
        $url = (string)$order->notify_url;
        return str_contains($url, '?') ? "{$url}&{$queryString}" : "{$url}?{$queryString}";
    }
}

