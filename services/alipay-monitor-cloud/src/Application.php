<?php

declare(strict_types=1);

namespace AlipayMonitorCloud;

use PDO;
use RuntimeException;

final class Application
{
    private PDO $db;
    private PrincipalKeyManager $keys;

    public function __construct(Database $database, string $masterKey)
    {
        $this->db = $database->pdo();
        $key = base64_decode($masterKey, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('masterKey 必须是 Base64 编码的 32 字节密钥');
        }
        $this->keys = new PrincipalKeyManager($this->db, $key);
    }

    public function handle(string $method, string $path, array $headers, string $body): array
    {
        // 1. 鉴权与时钟安全校验
        $clientId  = (string)($headers['X-CXPAY-Client'] ?? $headers['x-cxpay-client'] ?? '');
        $timestamp = (int)($headers['X-CXPAY-Timestamp'] ?? $headers['x-cxpay-timestamp'] ?? 0);
        $nonce     = (string)($headers['X-CXPAY-Nonce'] ?? $headers['x-cxpay-nonce'] ?? '');
        $signature = (string)($headers['X-CXPAY-Signature'] ?? $headers['x-cxpay-signature'] ?? '');

        if ($clientId === '' || abs(time() - $timestamp) > 300 || strlen($nonce) < 8) {
            return [401, ['message' => '请求鉴权参数或时间戳不合法']];
        }

        $secret = $this->keys->getActiveRequestSecret($clientId);
        if ($secret === null) {
            return [401, ['message' => '未授权的 Client ID']];
        }

        $canonical = implode("\n", [strtoupper($method), $path, (string)$timestamp, $nonce, hash('sha256', $body)]);
        if (!hash_equals(hash_hmac('sha256', $canonical, $secret), strtolower($signature))) {
            return [401, ['message' => '签名验证失败']];
        }

        // 2. 路由派发
        $payload = json_decode($body, true) ?: [];

        if ($method === 'POST' && $path === '/v1/auth-sessions') {
            return $this->createAuthSession($clientId, $payload);
        }

        if ($method === 'GET' && str_starts_with($path, '/v1/auth-sessions/')) {
            $sessionId = substr($path, strlen('/v1/auth-sessions/'));
            return $this->getAuthSession($clientId, $sessionId);
        }

        if ($method === 'POST' && $path === '/v1/orders') {
            return $this->registerOrder($clientId, $payload);
        }

        return [404, ['message' => '接口路由不存在']];
    }

    private function createAuthSession(string $clientId, array $payload): array
    {
        $sessionId = 'aas_' . bin2hex(random_bytes(12));
        $now = time();
        $expiresAt = $now + 600;

        // 生成针对支付宝网页授权的模拟链接/引导串
        $qrUrl = 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm?app_id=2021000000000000&scope=auth_base&redirect_uri=https://auth.example.com';

        $this->db->prepare(
            'INSERT INTO amc_auth_sessions (id, principal_id, status, qr_url, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$sessionId, $clientId, 'QR_READY', $qrUrl, $expiresAt, $now]);

        return [200, [
            'id'       => $sessionId,
            'status'   => 'QR_READY',
            'qr_url'   => $qrUrl,
            'message'  => '请使用手机支付宝扫描二维码进行授权',
        ]];
    }

    private function getAuthSession(string $clientId, string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM amc_auth_sessions WHERE id = ? AND principal_id = ?');
        $stmt->execute([$sessionId, $clientId]);
        $session = $stmt->fetch();
        if (!$session) {
            return [404, ['message' => '授权会话不存在']];
        }

        return [200, [
            'id'           => $session['id'],
            'status'       => $session['status'],
            'qr_url'       => $session['qr_url'],
            'external_ref' => $session['external_ref'],
            'display_name' => $session['display_name'],
            'message'      => '轮询查询成功',
        ]];
    }

    private function registerOrder(string $clientId, array $payload): array
    {
        $outTradeNo = (string)($payload['out_trade_no'] ?? '');
        $amount     = number_format((float)($payload['amount'] ?? 0), 2, '.', '');
        $expiresAt  = (int)($payload['expires_at'] ?? 0);

        if ($outTradeNo === '' || (float)$amount <= 0 || $expiresAt <= time()) {
            return [400, ['message' => '订单登记参数不合法']];
        }

        $now = time();
        $this->db->prepare(
            'INSERT OR REPLACE INTO amc_orders (out_trade_no, principal_id, amount, expires_at, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$outTradeNo, $clientId, $amount, $expiresAt, 'PENDING', $now]);

        return [200, [
            'accepted'     => true,
            'message'      => '支付宝订单登记成功',
            'out_trade_no' => $outTradeNo,
        ]];
    }
}
