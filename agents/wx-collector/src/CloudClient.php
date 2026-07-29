<?php

declare(strict_types=1);

namespace WxCollector;

use GuzzleHttp\Client;
use RuntimeException;

final class CloudClient implements CloudGatewayInterface
{
    private Client $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $collectorId,
        private readonly string $secret,
        bool $allowInsecureHttp = false,
        ?Client $http = null,
    ) {
        $parts = parse_url($baseUrl);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ($scheme !== 'https' && !($allowInsecureHttp && $scheme === 'http'))
            || !preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $collectorId)
            || strlen($secret) < 32 || strlen($secret) > 128) {
            throw new RuntimeException('采集器云服务地址或鉴权配置不合法');
        }
        $this->http = $http ?? new Client([
            'base_uri' => rtrim($baseUrl, '/'),
            'timeout' => 10.0,
            'connect_timeout' => 3.0,
            'verify' => true,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function pendingSessions(): array
    {
        $response = $this->request('GET', '/v1/collector/auth-sessions/pending');
        return array_values(array_filter((array)($response['data'] ?? []), 'is_array'));
    }

    public function updateSession(string $sessionId, array $state): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $sessionId)) {
            throw new RuntimeException('授权会话 ID 不合法');
        }
        return $this->request('POST', '/v1/collector/auth-sessions/' . rawurlencode($sessionId), $state);
    }

    public function submitPaymentEvent(array $event): array
    {
        return $this->request('POST', '/v1/collector/events', $event);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $payload = []): array
    {
        $body = $payload === [] ? '' : json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $this->secret);
        $response = $this->http->request($method, $path, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Collector-Id' => $this->collectorId,
                'X-Collector-Timestamp' => $timestamp,
                'X-Collector-Nonce' => $nonce,
                'X-Collector-Signature' => $signature,
            ],
            'body' => $body,
        ]);
        $data = json_decode((string)$response->getBody(), true);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException((string)($data['message'] ?? 'WX Monitor Cloud 请求失败'));
        }
        if (!is_array($data)) {
            throw new RuntimeException('WX Monitor Cloud 响应格式错误');
        }
        return $data;
    }
}
