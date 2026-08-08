<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

final class RequestAuthenticator
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly NonceRepository $nonces
    ) {
        $length = strlen($clientSecret);
        if ($length < 32 || $length > 128) {
            throw new RuntimeException('client_secret 长度必须为 32 至 128 位');
        }
    }

    /** @param array<string, string> $headers */
    public function authenticate(
        string $method,
        string $path,
        array $headers,
        string $body,
        int $now
    ): string {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $clientId = trim((string) ($headers['x-cxpay-client'] ?? ''));
        $timestampValue = trim((string) ($headers['x-cxpay-timestamp'] ?? ''));
        $nonce = trim((string) ($headers['x-cxpay-nonce'] ?? ''));
        $signature = strtolower(trim((string) ($headers['x-cxpay-signature'] ?? '')));

        if (!hash_equals($this->clientId, $clientId)
            || !preg_match('/^\d{1,12}$/', $timestampValue)
            || abs($now - (int) $timestampValue) > 300) {
            throw new ApiException(401, '请求身份或时间戳不合法');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $nonce)) {
            throw new ApiException(401, '请求 nonce 不合法');
        }

        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            $timestampValue,
            $nonce,
            hash('sha256', $body),
        ]);
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)
            || !hash_equals(hash_hmac('sha256', $canonical, $this->clientSecret), $signature)) {
            throw new ApiException(401, '请求签名无效');
        }

        $this->nonces->purgeExpired($now);
        if (!$this->nonces->claim($clientId, $nonce, $now, $now + 300)) {
            throw new ApiException(409, '请求已重放');
        }
        return $clientId;
    }
}
