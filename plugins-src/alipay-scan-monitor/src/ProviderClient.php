<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_scan_monitor;

use GuzzleHttp\Client;
use RuntimeException;
use support\UrlGuard;

final class ProviderClient
{
    /** @return array<string, mixed> */
    public function createAuthSession(array $config): array
    {
        return $this->request('POST', '/v1/auth-sessions', $config, [
            'reference' => 'cxpay-alipay-channel-' . (string)($config['channel_id'] ?? 'unknown'),
            'pay_type' => 'alipay',
        ]);
    }

    /** @return array<string, mixed> */
    public function getAuthSession(array $config, string $sessionId): array
    {
        return $this->request('GET', '/v1/auth-sessions/' . rawurlencode($sessionId), $config);
    }

    /** @return array<string, mixed> */
    public function registerOrder(array $config, string $tradeNo, string $amount, int $expiresAt): array
    {
        return $this->request('POST', '/v1/orders', $config, [
            'account_id' => (string)($config['account_id'] ?? ''),
            'out_trade_no' => $tradeNo,
            'amount' => $amount,
            'expires_at' => $expiresAt,
            'pay_type' => 'alipay',
        ]);
    }

    /** @return array<string, mixed> */
    public function reviewEvents(array $config): array
    {
        return $this->request('GET', '/v1/review/events', $config);
    }

    /** @return array<string, mixed> */
    public function operationsStatus(array $config): array
    {
        return $this->request('GET', '/v1/ops/status', $config);
    }

    /**
     * 查询指定平台订单号的支付状态。
     * 云服务应返回 {"paid": true/false, "amount": "xx.xx", "occurred_at": 123456}。
     *
     * @return array<string, mixed>
     */
    public function queryOrder(array $config, string $tradeNo): array
    {
        return $this->request('GET', '/v1/orders/' . rawurlencode($tradeNo), $config);
    }

    /** @return array<string, mixed> */
    public function matchReviewEvent(array $config, int $eventId, string $tradeNo, string $operator, string $note): array
    {
        return $this->request('POST', "/v1/review/events/{$eventId}/match", $config, [
            'out_trade_no' => $tradeNo,
            'operator' => $operator,
            'note' => $note,
        ]);
    }

    /** @return array<string, mixed> */
    public function ignoreReviewEvent(array $config, int $eventId, string $operator, string $note): array
    {
        return $this->request('POST', "/v1/review/events/{$eventId}/ignore", $config, [
            'operator' => $operator,
            'note' => $note,
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $config, array $payload = []): array
    {
        $baseUrl = rtrim((string)($config['monitor_base_url'] ?? ''), '/');
        $clientId = (string)($config['client_id'] ?? '');
        $clientSecret = (string)($config['client_secret'] ?? '');
        $callbackSecrets = array_values(array_filter([
            (string)($config['callback_secret'] ?? ''),
            (string)($config['callback_secret_previous'] ?? ''),
        ], static fn (string $secret): bool => strlen($secret) >= 32 && strlen($secret) <= 128));
        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
            || UrlGuard::resolve($baseUrl) === null || strlen($clientSecret) < 32 || $callbackSecrets === []) {
            throw new RuntimeException('支付宝云账单服务配置不完整或地址不可用');
        }
        $body = $payload === [] ? '' : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $clientSecret);
        $response = (new Client([
            'base_uri' => $baseUrl,
            'timeout' => 5.0,
            'connect_timeout' => 3.0,
            'verify' => true,
            'http_errors' => false,
            'allow_redirects' => false,
        ]))->request($method, $path, ['headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-CXPAY-Client' => $clientId,
            'X-CXPAY-Timestamp' => $timestamp,
            'X-CXPAY-Nonce' => $nonce,
            'X-CXPAY-Signature' => $signature,
        ], 'body' => $body]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('支付宝云账单接口暂时不可用，HTTP ' . $response->getStatusCode());
        }
        $responseBody = (string)$response->getBody();
        $receivedSignature = strtolower(trim($response->getHeaderLine('X-CXPAY-Signature')));
        $verified = false;
        foreach ($callbackSecrets as $secret) {
            $verified = hash_equals(hash_hmac('sha256', $responseBody, $secret), $receivedSignature) || $verified;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $receivedSignature) || !$verified) {
            throw new RuntimeException('支付宝云账单响应签名无效');
        }
        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException('支付宝云账单响应格式错误');
        }
        return $data;
    }
}
