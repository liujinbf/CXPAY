<?php

declare(strict_types=1);

namespace AlipayAutoConfig;

use RuntimeException;

/**
 * 与 alipay-scan-monitor ProviderClient 完全兼容的请求/响应签名助手。
 *
 * 请求签名规范串：
 *   HTTP_METHOD\nREQUEST_PATH\nTIMESTAMP\nNONCE\nSHA256(RAW_BODY)
 *
 * 响应签名：对原始响应 JSON 字节计算 HMAC-SHA256，
 * 通过 X-CXPAY-Signature 响应头返回。
 */
final class SignatureHelper
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $callbackSecret
    ) {}

    /**
     * 验证 CXPAY 发来的请求签名。
     */
    public function verifyRequest(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $rawBody,
        string $receivedSignature
    ): bool {
        if (strlen($this->clientSecret) < 32) {
            throw new RuntimeException('client_secret 长度不足 32 位');
        }
        if (abs(time() - (int)$timestamp) > 300) {
            return false; // 时间戳偏差超过 5 分钟
        }
        $bodyHash  = hash('sha256', $rawBody);
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, $bodyHash]);
        $expected  = hash_hmac('sha256', $canonical, $this->clientSecret);
        return hash_equals($expected, strtolower(trim($receivedSignature)));
    }

    /**
     * 从 HTTP 请求头中提取并验证 CXPAY 签名信息。
     *
     * @param array<string, string> $headers
     */
    public function verifyRequestHeaders(
        string $method,
        string $path,
        string $rawBody,
        array  $headers
    ): bool {
        $timestamp = $headers['X-CXPAY-Timestamp'] ?? $headers['x-cxpay-timestamp'] ?? '';
        $nonce     = $headers['X-CXPAY-Nonce']     ?? $headers['x-cxpay-nonce']     ?? '';
        $signature = $headers['X-CXPAY-Signature'] ?? $headers['x-cxpay-signature'] ?? '';
        $clientId  = $headers['X-CXPAY-Client']    ?? $headers['x-cxpay-client']    ?? '';

        if (!hash_equals($this->clientId, $clientId)) {
            return false;
        }
        return $this->verifyRequest($method, $path, $timestamp, $nonce, $rawBody, $signature);
    }

    /**
     * 对响应 JSON 正文计算 HMAC-SHA256 签名，用于设置 X-CXPAY-Signature 响应头。
     */
    public function signResponse(string $responseBody): string
    {
        return hash_hmac('sha256', $responseBody, $this->callbackSecret);
    }

    /**
     * 输出带签名头的 JSON 响应并终止执行。
     *
     * @param array<string, mixed> $data
     */
    public function sendJson(array $data, int $statusCode = 200): never
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sig  = $this->signResponse($body);
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-CXPAY-Signature: ' . $sig);
        echo $body;
        exit;
    }
}
