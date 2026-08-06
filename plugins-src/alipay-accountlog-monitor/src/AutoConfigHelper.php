<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_accountlog_monitor;

use RuntimeException;

/**
 * 支付宝账单通道自动配置助手（代理服务版）。
 *
 * 通过外部「支付宝自动配置代理服务」（alipay-autoconfig-proxy）完成配置流程：
 *
 * 1. [startAccountAuthorization] 向代理服务发起配置会话请求，获取 session_id 和引导 URL。
 *    引导 URL 将以 QR 码形式展示给管理员，管理员在浏览器中打开后按步骤填写信息。
 *
 * 2. [pollAccountAuthorization] 轮询代理服务，直到会话变为 CONFIRMED（商户验证成功）。
 *    CONFIRMED 时返回 app_id 和 alipay_public_key，通过 config_patch 写回通道配置。
 *
 * 如果未配置代理服务，则退化为"仅本地生成密钥对"模式，提示管理员手动完成剩余配置。
 *
 * 签名协议：与 alipay-scan-monitor ProviderClient 完全相同的 HMAC-SHA256 方案。
 */
final class AutoConfigHelper
{
    private const TIMEOUT_CONNECT = 5;
    private const TIMEOUT_READ    = 10;

    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $callbackSecret;

    public function __construct(
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        string $callbackSecret
    ) {
        $this->baseUrl        = rtrim($baseUrl, '/');
        $this->clientId       = $clientId;
        $this->clientSecret   = $clientSecret;
        $this->callbackSecret = $callbackSecret;
    }

    /**
     * 向代理服务发起配置会话，获取引导 URL。
     *
     * @return array{
     *   session_id: string,
     *   guide_url: string,
     *   private_key: string,
     *   public_key: string,
     *   expires_at: int,
     *   status: string,
     *   message: string
     * }
     */
    public function createAutoAuthSession(): array
    {
        // 在本地生成 RSA2 密钥对。私钥留在 CXPAY 通道配置中，公钥发送给代理服务。
        $keyPair   = self::generateKeyPair();
        $reference = 'cxpay-channel-' . bin2hex(random_bytes(6));

        $body = json_encode([
            'reference'  => $reference,
            'public_key' => $keyPair['public_key'],
            'pay_type'   => 'alipay',
        ], JSON_THROW_ON_ERROR);

        $result = $this->request('POST', '/v1/autoconfig-sessions', $body);

        return [
            'session_id'  => (string)($result['session_id'] ?? ''),
            'guide_url'   => (string)($result['guide_url']  ?? ''),
            'expires_at'  => (int)($result['expires_at']    ?? (time() + 1200)),
            'status'      => 'QR_READY',
            'private_key' => $keyPair['private_key'],   // 临时保存，后续在 config 中加密
            'public_key'  => $keyPair['public_key'],
            'message'     => (string)($result['message'] ?? '请在引导页面按步骤完成支付宝开放平台应用配置'),
        ];
    }

    /**
     * 轮询代理服务的会话状态。
     *
     * 状态值：
     * - PENDING    → 商户尚未在引导页面提交
     * - CONFIRMED  → 商户已提交且验证通过，包含 app_id 和 alipay_public_key
     * - FAILED     → 验证失败
     * - EXPIRED    → 会话超时
     *
     * @param array<string, mixed> $sessionContext 从 startAccountAuthorization 传来的上下文（含 private_key）
     *
     * @return array{
     *   status: string,
     *   message: string,
     *   app_id?: string,
     *   alipay_public_key?: string,
     *   private_key?: string
     * }
     */
    public function pollAutoAuthSession(string $sessionId, array $sessionContext = []): array
    {
        $result = $this->request('GET', '/v1/autoconfig-sessions/' . rawurlencode($sessionId), '');

        $status = (string)($result['status'] ?? 'PENDING');

        if ($status !== 'CONFIRMED') {
            return [
                'status'  => $status,
                'message' => (string)($result['message'] ?? match ($status) {
                    'PENDING' => '等待商户在引导页面完成填写',
                    'FAILED'  => '配置验证失败，请重新操作',
                    'EXPIRED' => '会话已超时，请重新发起配置',
                    default   => '未知状态',
                }),
            ];
        }

        $appId          = trim((string)($result['app_id']            ?? ''));
        $alipayPublicKey = trim((string)($result['alipay_public_key'] ?? ''));

        if ($appId === '' || $alipayPublicKey === '') {
            return ['status' => 'FAILED', 'message' => '代理服务返回的凭证不完整'];
        }

        return [
            'status'           => 'CONFIRMED',
            'app_id'           => $appId,
            'alipay_public_key'=> $alipayPublicKey,
            // private_key 由调用方从 session_context 中取得并拼入 config_patch
            'message'          => (string)($result['message'] ?? '配置完成，应用凭证已就绪'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 工具方法
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 生成 2048 位 RSA2 密钥对（PKCS#8 格式，去除 PEM 头尾行）。
     *
     * @return array{private_key: string, public_key: string}
     */
    public static function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            throw new RuntimeException('本地生成 RSA2 密钥对失败，请检查 PHP OpenSSL 扩展');
        }
        openssl_pkey_export($res, $privateKeyPem);
        $details     = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'] ?? '';

        $cleanPrivate = preg_replace('/-----.*-----|[\r\n]/', '', $privateKeyPem) ?? '';
        $cleanPublic  = preg_replace('/-----.*-----|[\r\n]/', '', $publicKeyPem)  ?? '';

        return ['private_key' => $cleanPrivate, 'public_key' => $cleanPublic];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 内部 HTTP 请求（与 alipay-scan-monitor ProviderClient 相同的签名协议）
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function request(string $method, string $path, string $body): array
    {
        $timestamp = (string)time();
        $nonce     = bin2hex(random_bytes(12));
        $bodyHash  = hash('sha256', $body);
        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, $this->clientSecret);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            "X-CXPAY-Client: {$this->clientId}",
            "X-CXPAY-Timestamp: {$timestamp}",
            "X-CXPAY-Nonce: {$nonce}",
            "X-CXPAY-Signature: {$signature}",
        ];

        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body !== '' ? $body : null,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONNECT,
            CURLOPT_TIMEOUT        => self::TIMEOUT_READ,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
        ]);
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new RuntimeException("自动配置代理服务连接失败: {$curlErr}");
        }

        $responseHeaders = substr($raw, 0, $headerSize);
        $responseBody    = substr($raw, $headerSize);

        // 验证代理服务的响应签名
        $this->verifyResponseSignature($responseHeaders, $responseBody);

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new RuntimeException("自动配置代理服务响应格式错误 [HTTP {$httpCode}]");
        }
        if ($httpCode >= 400 && isset($data['error'])) {
            throw new RuntimeException("代理服务错误: {$data['error']}");
        }
        return $data;
    }

    private function verifyResponseSignature(string $rawHeaders, string $body): void
    {
        if (!preg_match('/X-CXPAY-Signature:\s*([a-f0-9]{64})/i', $rawHeaders, $m)) {
            throw new RuntimeException('代理服务响应缺少签名头，请检查 callback_secret 是否与代理服务配置一致');
        }
        $expected = hash_hmac('sha256', $body, $this->callbackSecret);
        if (!hash_equals($expected, strtolower($m[1]))) {
            throw new RuntimeException('代理服务响应签名验证失败，可能遭受中间人攻击');
        }
    }
}
