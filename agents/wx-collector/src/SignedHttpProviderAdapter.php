<?php

declare(strict_types=1);

namespace WxCollector;

use GuzzleHttp\Client;
use RuntimeException;

/** 连接实现标准签名 HTTPS API 的合法授权数据源。 */
final class SignedHttpProviderAdapter implements ProviderAdapterInterface
{
    private Client $http;
    private readonly string $clientId;
    private readonly string $requestSecret;
    private readonly string $responseSecret;

    public function __construct(
        ?string $baseUrl = null,
        ?string $clientId = null,
        ?string $requestSecret = null,
        ?string $responseSecret = null,
        ?Client $http = null,
    ) {
        $baseUrl ??= (string)getenv('WXPROVIDER_BASE_URL');
        $clientId ??= (string)getenv('WXPROVIDER_CLIENT_ID');
        $requestSecret ??= (string)getenv('WXPROVIDER_REQUEST_SECRET');
        $responseSecret ??= (string)getenv('WXPROVIDER_RESPONSE_SECRET');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $clientId)
            || strlen($requestSecret) < 32 || strlen($requestSecret) > 128
            || strlen($responseSecret) < 32 || strlen($responseSecret) > 128) {
            throw new RuntimeException('授权服务商 HTTPS 地址或签名配置不合法');
        }
        $this->clientId = $clientId;
        $this->requestSecret = $requestSecret;
        $this->responseSecret = $responseSecret;
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => 10.0,
            'connect_timeout' => 3.0,
            'verify' => true,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function startAuthorization(array $task): ?array
    {
        $result = $this->request('POST', '/v1/authorization-sessions', [
            'session_id' => (string)($task['id'] ?? ''),
            'expires_at' => (int)($task['expires_at'] ?? 0),
        ]);
        return ($result['pending'] ?? false) === true ? null : $result;
    }

    public function pollAuthorization(array $task): ?array
    {
        $result = $this->request(
            'GET',
            '/v1/authorization-sessions/' . rawurlencode((string)($task['id'] ?? ''))
        );
        return ($result['changed'] ?? true) === false ? null : $result;
    }

    public function pullPaymentEvents(int $limit): array
    {
        $result = $this->request('GET', '/v1/payment-events?limit=' . max(1, min(100, $limit)));
        return array_values(array_filter((array)($result['data'] ?? []), 'is_array'));
    }

    public function acknowledgePaymentEvent(string $ackToken): void
    {
        if ($ackToken === '' || strlen($ackToken) > 255) {
            throw new RuntimeException('数据源账单确认令牌不合法');
        }
        $this->request('POST', '/v1/payment-events/' . rawurlencode($ackToken) . '/ack');
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $payload = []): array
    {
        $body = $payload === [] ? '' : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);
        $response = $this->http->request($method, $path, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Provider-Client' => $this->clientId,
                'X-Provider-Timestamp' => $timestamp,
                'X-Provider-Nonce' => $nonce,
                'X-Provider-Signature' => hash_hmac('sha256', $canonical, $this->requestSecret),
            ],
            'body' => $body,
        ]);
        $raw = (string)$response->getBody();
        $signature = strtolower(trim($response->getHeaderLine('X-Provider-Signature')));
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)
            || !hash_equals(hash_hmac('sha256', $raw, $this->responseSecret), $signature)) {
            throw new RuntimeException('授权服务商响应签名无效');
        }
        $data = json_decode($raw, true);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException((string)($data['message'] ?? '授权服务商接口请求失败'));
        }
        if (!is_array($data)) {
            throw new RuntimeException('授权服务商响应格式错误');
        }
        return $data;
    }
}
