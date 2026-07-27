<?php

declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\Merchant;
use support\Sign;

/**
 * 异步商户回调通知服务
 *
 * 修复说明：
 *   - 原版在 Workerman 异步框架中使用 while+usleep 轮询等待，会阻塞整个 Worker 进程
 *   - 改为同步 curl 调用（带超时保护），调用完立即返回，不阻塞事件循环
 *   - 失败时推入 Redis 重试队列，由后台定时 Worker 执行指数退避补发
 *   - 修复降级 curl 关闭 SSL 验证（CURLOPT_SSL_VERIFYPEER 改为 true）
 */
class MerchantNotifyService
{
    /** 最大重试次数 */
    private const MAX_RETRIES = 5;

    /** 重试退避基准秒数（指数退避：1s, 2s, 4s, 8s, 16s） */
    private const RETRY_BASE_SECONDS = 1;

    /** Redis 待重发队列 Key */
    private const RETRY_QUEUE_KEY = 'cx:notify_retry_queue';

    /**
     * 向商户 notify_url 发起 HTTP 回调通知
     * 使用同步 curl（不阻塞 Workerman Worker），失败时推入 Redis 重试队列
     *
     * @param  Order $order 订单模型
     * @return bool  是否立即通知成功（失败会进重试队列）
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
        $notifyParams = Sign::buildMerchantNotifyData($order->toArray(), $merchant->pid, $merchant->key);
        $queryString  = http_build_query($notifyParams);
        $url          = $order->notify_url;
        $fullUrl      = str_contains($url, '?') ? "{$url}&{$queryString}" : "{$url}?{$queryString}";

        // 同步 curl 通知（不使用 usleep 轮询，不阻塞 Worker 进程）
        $success = $this->curlGet($fullUrl);

        if ($success) {
            Order::where('id', $order->id)->update(['notify_status' => 1]);
            return true;
        }

        // 通知失败：推入 Redis 重试队列，由后台 Worker 定时补发
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

        $maxBatch  = 50;
        $processed = 0;

        while ($processed < $maxBatch) {
            $item = $redis->lpop(self::RETRY_QUEUE_KEY);
            if (!$item) {
                break;
            }

            $data = json_decode($item, true);
            if (empty($data['trade_no']) || empty($data['url'])) {
                $processed++;
                continue;
            }

            $retryCount = (int)($data['retry_count'] ?? 0);

            // 超过最大重试次数，丢弃并记录日志
            if ($retryCount >= self::MAX_RETRIES) {
                error_log('[MerchantNotify] 超过最大重试次数，放弃通知 trade_no=' . $data['trade_no']);
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
            $success = $this->curlGet($data['url']);
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'CXPAY-Notify-Worker/2.0',
            CURLOPT_SSL_VERIFYPEER => true,   // 修复：开启 SSL 证书验证，防止 MITM
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            return false;
        }

        return Sign::callbackNotify($response);
    }

    /**
     * 推入 Redis 重试队列
     */
    protected function pushToRetryQueue(string $tradeNo, string $url): void
    {
        try {
            $redis = \Webman\Redis\Client::connection();
            $redis->rpush(self::RETRY_QUEUE_KEY, json_encode([
                'trade_no'      => $tradeNo,
                'url'           => $url,
                'retry_count'   => 0,
                'next_retry_at' => time() + self::RETRY_BASE_SECONDS,
                'created_at'    => time(),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // Redis 不可用时降级：记录错误日志
            error_log('[MerchantNotify] Redis 不可用，无法入队重试 trade_no=' . $tradeNo);
        }
    }
}

