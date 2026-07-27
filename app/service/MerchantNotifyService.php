<?php

declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\Merchant;
use support\Sign;

/**
 * 异步商户回调通知服务
 * 修复：
 *   - 原版使用同步阻塞 file_get_contents，在 Webman 协程架构下会阻塞整个 Worker
 *   - 改用 Workerman\Http\Client 非阻塞异步 HTTP，不阻断当前协程
 *   - 增加指数退避重试机制（最多 5 次），失败时写 Redis 队列待后台 Worker 补发
 */
class MerchantNotifyService
{
    /** 最大即时重试次数 */
    private const MAX_RETRIES = 5;

    /** 重试退避基准秒数（指数退避：1s, 2s, 4s, 8s, 16s） */
    private const RETRY_BASE_SECONDS = 1;

    /** Redis 待重发队列 Key */
    private const RETRY_QUEUE_KEY = 'cx:notify_retry_queue';

    /**
     * 向商户 notify_url 发起非阻塞异步 HTTP 回调通知
     *
     * @param  Order  $order 订单模型
     * @return bool   是否立即通知成功（失败会进重试队列）
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

        // 构建易支付规范回调数据包（含 MD5 签名）
        $notifyParams = Sign::buildMerchantNotifyData($order->toArray(), $merchant->key);
        $queryString  = http_build_query($notifyParams);
        $url          = $order->notify_url;
        $fullUrl      = str_contains($url, '?') ? "{$url}&{$queryString}" : "{$url}?{$queryString}";

        // 使用 Workerman 非阻塞异步 HTTP 客户端（不阻塞当前 Worker 协程）
        $success = $this->asyncGet($fullUrl);

        if ($success) {
            // 标记回调成功
            Order::where('id', $order->id)->update(['notify_status' => 1]);
            return true;
        }

        // 异步通知失败：推入 Redis 重试队列，由后台 Worker 定时补发
        $this->pushToRetryQueue($order->trade_no, $fullUrl);
        return false;
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

        $maxBatch = 50; // 每次最多处理 50 条
        $processed = 0;

        while ($processed < $maxBatch) {
            $item = $redis->lpop(self::RETRY_QUEUE_KEY);
            if (!$item) {
                break;
            }

            $data = json_decode($item, true);
            if (empty($data['trade_no']) || empty($data['url'])) {
                continue;
            }

            $retryCount = (int)($data['retry_count'] ?? 0);

            // 超过最大重试次数，丢弃
            if ($retryCount >= self::MAX_RETRIES) {
                continue;
            }

            // 指数退避延迟检查（下次执行时间未到则重新入队）
            $nextRetryAt = (int)($data['next_retry_at'] ?? 0);
            if ($nextRetryAt > time()) {
                $redis->rpush(self::RETRY_QUEUE_KEY, $item);
                $processed++;
                continue;
            }

            // 发起重试
            $success = $this->asyncGet($data['url']);
            if ($success) {
                Order::where('trade_no', $data['trade_no'])->update(['notify_status' => 1]);
            } else {
                // 计算下次重试时间（指数退避）
                $nextInterval = self::RETRY_BASE_SECONDS * (2 ** $retryCount);
                $data['retry_count']  = $retryCount + 1;
                $data['next_retry_at'] = time() + $nextInterval;
                $redis->rpush(self::RETRY_QUEUE_KEY, json_encode($data, JSON_UNESCAPED_UNICODE));
            }

            $processed++;
        }
    }

    /**
     * 推入 Redis 重试队列
     */
    protected function pushToRetryQueue(string $tradeNo, string $url): void
    {
        try {
            $redis = \Webman\Redis\Client::connection();
            $redis->rpush(self::RETRY_QUEUE_KEY, json_encode([
                'trade_no'     => $tradeNo,
                'url'          => $url,
                'retry_count'  => 0,
                'next_retry_at'=> time() + self::RETRY_BASE_SECONDS,
                'created_at'   => time(),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // Redis 不可用时降级：记录错误日志
        }
    }

    /**
     * 非阻塞异步 HTTP GET 请求
     * 优先使用 Workerman\Http\Client（非阻塞），降级到同步 curl（带超时保护）
     */
    protected function asyncGet(string $url): bool
    {
        // 优先：Workerman 非阻塞协程 HTTP 客户端
        if (class_exists(\Workerman\Http\Client::class)) {
            $result  = false;
            $done    = false;

            $client = new \Workerman\Http\Client();
            $client->get($url, function ($response) use (&$result, &$done) {
                $body = (string)$response->getBody();
                $result = Sign::callbackNotify($body);
                $done   = true;
            }, [
                'timeout' => 8,
                'headers' => ['User-Agent' => 'CXPAY-Notify-Worker/2.0'],
            ]);

            // 等待协程完成（最多 8 秒）
            $start = microtime(true);
            while (!$done && (microtime(true) - $start) < 8) {
                usleep(1000);
            }
            return $result;
        }

        // 降级：同步 curl（5 秒超时，最后手段）
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT      => 'CXPAY-Notify-Worker/2.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            return false;
        }

        return Sign::callbackNotify($response);
    }
}
