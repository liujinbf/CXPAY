<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use PDO;
use RuntimeException;

final class Authenticator
{
    public function __construct(private readonly PDO $pdo, private readonly SecretVault $vault)
    {
    }

    /** @param array<string, string> $headers @return array<string, mixed> */
    public function authenticate(string $method, string $path, array $headers, string $body, string $role): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $prefix = $role === 'collector' ? 'x-collector-' : 'x-cxpay-';
        $principalId = trim((string)($headers[$prefix . ($role === 'collector' ? 'id' : 'client')] ?? ''));
        $timestamp = trim((string)($headers[$prefix . 'timestamp'] ?? ''));
        $nonce = trim((string)($headers[$prefix . 'nonce'] ?? ''));
        $signature = strtolower(trim((string)($headers[$prefix . 'signature'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $principalId)
            || !ctype_digit($timestamp)
            || abs(time() - (int)$timestamp) > 300
            || !preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new RuntimeException('请求鉴权头不完整或已过期');
        }

        $statement = $this->pdo->prepare('SELECT * FROM principals WHERE id = ? AND role = ? AND status = 1');
        $statement->execute([$principalId, $role]);
        $principal = $statement->fetch();
        if (!$principal) {
            throw new RuntimeException('调用方身份不存在或已停用');
        }
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);
        $verified = false;
        foreach ((new PrincipalKeyManager($this->pdo, $this->vault))->verificationSecrets($principal) as $secret) {
            $verified = hash_equals(hash_hmac('sha256', $canonical, $secret), $signature) || $verified;
        }
        if (!$verified) {
            throw new RuntimeException('请求签名无效');
        }

        $this->pdo->prepare('DELETE FROM request_nonces WHERE expires_at < ?')->execute([time()]);
        try {
            $this->pdo->prepare('INSERT INTO request_nonces(principal_id, nonce, expires_at) VALUES(?, ?, ?)')
                ->execute([$principalId, $nonce, time() + 600]);
        } catch (\PDOException) {
            throw new RuntimeException('检测到重复请求随机数');
        }
        $this->recordActivity($principalId, $role);
        return $principal;
    }

    /** @param array<string, mixed> $principal */
    public function signResponse(HttpResponse $response, array $principal): HttpResponse
    {
        if (($principal['role'] ?? '') !== 'client') {
            return $response;
        }
        $secret = (new PrincipalKeyManager($this->pdo, $this->vault))->activeSecret($principal, 'response');
        return new HttpResponse(
            $response->status,
            $response->body,
            ['X-CXPAY-Signature' => hash_hmac('sha256', $response->body, $secret)] + $response->headers
        );
    }

    private function recordActivity(string $principalId, string $role): void
    {
        $now = time();
        $updated = $this->pdo->prepare(
            'UPDATE principal_activity
             SET role = ?, last_authenticated_at = ?, request_count = request_count + 1
             WHERE principal_id = ?'
        );
        $updated->execute([$role, $now, $principalId]);
        if ($updated->rowCount() === 1) {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO principal_activity(principal_id, role, last_authenticated_at, request_count)
                 VALUES(?, ?, ?, 1)'
            )->execute([$principalId, $role, $now]);
        } catch (\PDOException) {
            // 并发首次请求只有一个插入成功，其余请求补做原子累加。
            $updated->execute([$role, $now, $principalId]);
        }
    }
}
