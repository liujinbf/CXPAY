<?php

declare(strict_types=1);

namespace plugin\cxpay\wxpay_clerk_adapter;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use support\UrlGuard;

final class ProviderClient
{
    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    public function capabilities(array $config): array
    {
        $accountId = (string)($config['account_id'] ?? '');
        $path = '/v1/accounts/' . rawurlencode($accountId) . '/capabilities';
        return $this->request('GET', $path, $config);
    }

    public function registerOrder(array $config, string $outTradeNo, string $amount, int $expiresAt): array
    {
        return $this->request('POST', '/v1/orders', $config, [
            'account_id'   => (string)($config['account_id'] ?? ''),
            'out_trade_no' => $outTradeNo,
            'amount'       => $amount,
            'expires_at'   => $expiresAt,
            'mode'         => 'clerk',
        ]);
    }

    public function queryOrder(array $config, string $tradeNo): array
    {
        return $this->request('GET', '/v1/orders/' . rawurlencode($tradeNo), $config);
    }

    public function createAuthSession(array $config): array
    {
        return $this->request('POST', '/v1/auth-sessions', $config, [
            'reference' => 'cxpay-clerk-channel-' . (string)($config['channel_id'] ?? 'unknown'),
            'mode'      => 'clerk',
        ]);
    }

    public function getAuthSession(array $config, string $sessionId): array
    {
        return $this->request('GET', '/v1/auth-sessions/' . rawurlencode($sessionId), $config);
    }

    public function reviewEvents(array $config): array
    {
        return $this->request('GET', '/v1/review/events', $config);
    }

    public function operationsStatus(array $config): array
    {
        return $this->request('GET', '/v1/ops/status', $config);
    }

    public function matchReviewEvent(array $config, int $eventId, string $outTradeNo, string $operator, string $note): array
    {
        return $this->request('POST', "/v1/review/events/{$eventId}/match", $config, [
            'out_trade_no' => $outTradeNo,
            'operator'     => $operator,
            'note'         => $note,
        ]);
    }

    public function ignoreReviewEvent(array $config, int $eventId, string $operator, string $note): array
    {
        return $this->request('POST', "/v1/review/events/{$eventId}/ignore", $config, [
            'operator' => $operator,
            'note'     => $note,
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

        $target = UrlGuard::resolve($baseUrl);
        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
            || $target === null || $target['port'] !== 443) {
            throw new RuntimeException('店员服务地址必须是可解析的公网 HTTPS 地址');
        }

        $body = $payload === [] ? '' : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $clientSecret);

        $response = $this->http->request($method, $baseUrl . $path, [
            'timeout' => 5.0,
            'connect_timeout' => 3.0,
            'verify' => true,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => [
            'Accept'            => 'application/json',
            'Content-Type'      => 'application/json',
            'X-CXPAY-Client'    => $clientId,
            'X-CXPAY-Timestamp' => $timestamp,
            'X-CXPAY-Nonce'     => $nonce,
            'X-CXPAY-Signature' => $signature,
        ], 'body' => $body]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('店员免挂云服务接口暂时不可用，HTTP ' . $response->getStatusCode());
        }

        $body = (string)$response->getBody();
        $responseSignature = trim($response->getHeaderLine('X-CXPAY-Signature'));
        $verified = false;
        foreach ($callbackSecrets as $callbackSecret) {
            $verified = hash_equals(hash_hmac('sha256', $body, $callbackSecret), $responseSignature) || $verified;
        }
        if ($responseSignature === '' || !$verified) {
            throw new RuntimeException('店员免挂云服务响应签名无效');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('店员免挂云服务响应格式错误');
        }
        return $data;
    }
}
