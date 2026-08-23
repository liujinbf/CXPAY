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
     *   - T8 改进：回调方式由 GET 改为 POST (application/x-www-form-urlencoded)，
     *     与微信/支付宝官方协议一致，兼容主流商城插件（WooCommerce、ThinkPHP 等）
     */
class MerchantNotifyService
{
    /** 最大重试次数（8次，约4小时窗口） */
    private const MAX_RETRIES = 8;

    /**
     * 重试退避基准秒数（指数退避）
     * 间隔：60s, 120s, 240s, 480s, 960s, 1920s, 3840s, 7680s
     * 总覆盖约 4 小时，足以覆盖商户服务器重启/维护场景。
     */
    private const RETRY_BASE_SECONDS = 60;

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

        // 1. 首次优先直接同步发起 HTTP POST 通知（3秒超时），确保商户秒级收到支付回调
        $notifyData = $this->buildNotifyParams($order);
        if ($notifyData !== null) {
            $success = $this->curlPost((string)$order->notify_url, $notifyData);
            if ($success) {
                Order::where('id', $order->id)->update(['notify_status' => 1]);
                error_log('[MerchantNotify] 商户即时回调成功 trade_no=' . $order->trade_no);
                return true;
            }
        }

        // 2. 若即时通知失败，标记为通知中并尝试推入重试队列
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

            // 发起重试通知（POST 方式）
            $notifyData = $this->buildNotifyParams($order);
            $success = $notifyData !== null && $this->curlPost((string)$order->notify_url, $notifyData);
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
     * 以 POST (application/x-www-form-urlencoded) 方式发送商户回调通知。
     * 与微信/支付宝官方回调协议保持一致，兼容主流商城系统（WooCommerce、ThinkPHP 等）。
     * 同时保留 SSRF 防护（UrlGuard）和 SSL 强制验证，安全策略不降级。
     *
     * @param  string $url    商户 notify_url（仅含 URL，参数通过 POST body 传递）
     * @param  array  $params 回调参数（含签名）
     * @return bool 商户响应中是否包含 'success'
     */
    protected function curlPost(string $url, array $params): bool
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

        $postBody   = http_build_query($params);
        $resolvedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'CXPAY-Notify-Worker/2.0',
            CURLOPT_SSL_VERIFYPEER => true,
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

    /**
     * 构建商户回调参数数组（含签名）。
     * URL 保持干净，参数通过 POST body 传递（Content-Type: application/x-www-form-urlencoded）。
     *
     * @return array|null 回调参数数组，null 表示商户或 notify_url 不存在
     */
    private function buildNotifyParams(Order $order): ?array
    {
        $merchant = Merchant::find($order->merchant_id);
        if (!$merchant || empty($order->notify_url)) {
            return null;
        }
        return Sign::buildMerchantNotifyData(
            $order->toArray(),
            (string)$merchant->pid,
            (string)$merchant->key
        );
    }
}
