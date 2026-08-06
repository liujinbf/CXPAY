<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

/**
 * HMAC-SHA256 双向签名助手。
 * 与 CXPAY wxpay-clerk-adapter ProviderClient 签名协议完全兼容。
 *
 * 请求规范串（5 段 \n 连接）：
 *   UPPERCASE_METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256(BODY)
 *
 * 响应签名：HMAC-SHA256(response_body, callback_secret)
 * 通过 X-CXPAY-Signature 响应头返回。
 */
final class SignatureHelper
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $callbackSecret
    ) {
        if (strlen($clientSecret) < 32) {
            throw new RuntimeException('client_secret 长度不足 32 位');
        }
        if (strlen($callbackSecret) < 32) {
            throw new RuntimeException('callback_secret 长度不足 32 位');
        }
    }

    /**
     * 从 PHP 全局变量中提取请求头并验证 CXPAY 请求签名。
     */
    public function verifyIncomingRequest(string $method, string $path, string $rawBody): bool
    {
        $clientId  = $_SERVER['HTTP_X_CXPAY_CLIENT']    ?? '';
        $timestamp = $_SERVER['HTTP_X_CXPAY_TIMESTAMP'] ?? '';
        $nonce     = $_SERVER['HTTP_X_CXPAY_NONCE']     ?? '';
        $signature = $_SERVER['HTTP_X_CXPAY_SIGNATURE'] ?? '';

        if (!hash_equals($this->clientId, $clientId)) {
            return false;
        }
        if (abs(time() - (int)$timestamp) > 300) {
            return false; // 时间戳偏差超过 5 分钟
        }
        $bodyHash  = hash('sha256', $rawBody);
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, $bodyHash]);
        $expected  = hash_hmac('sha256', $canonical, $this->clientSecret);
        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * 对响应体计算签名，用于 X-CXPAY-Signature 响应头。
     */
    public function signResponse(string $body): string
    {
        return hash_hmac('sha256', $body, $this->callbackSecret);
    }

    /**
     * 输出带签名头的 JSON 响应并终止执行。
     *
     * @param array<string, mixed> $data
     */
    public function sendJson(array $data, int $statusCode = 200): never
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-CXPAY-Signature: ' . $this->signResponse($body));
        echo $body;
        exit;
    }

    /**
     * 生成用于向 CXPAY 发出回调请求的 HMAC 签名头集合。
     * 注意：回调方向的密钥与响应签名方向相同（callback_secret）。
     *
     * @return array<string, string>
     */
    public function buildCallbackHeaders(string $method, string $path, string $body): array
    {
        $timestamp = (string)time();
        $nonce     = bin2hex(random_bytes(12));
        $bodyHash  = hash('sha256', $body);
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, $this->callbackSecret);
        return [
            'X-CXPAY-Client'    => $this->clientId,
            'X-CXPAY-Timestamp' => $timestamp,
            'X-CXPAY-Nonce'     => $nonce,
            'X-CXPAY-Signature' => $signature,
        ];
    }
}
