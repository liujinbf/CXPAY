<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

/**
 * 向 CXPAY 发送签名的到账回调。
 *
 * 目标端点：POST {cxpay_notify_url}/notify/wxpay_clerk_adapter
 *
 * 回调字段（与 wxpay-clerk-adapter Driver::notify() 完全对称）：
 *   source_bill_id  — 本服务的稳定账单标识
 *   out_trade_no    — CXPAY 平台流水号
 *   money           — 两位小数金额字符串
 *   occurred_at     — 到账 Unix 时间戳
 *   timestamp       — 推送时间
 *   nonce           — 随机串（防重放）
 *   sign            — HMAC-SHA256（fields 规范化串，callback_secret 签名）
 */
final class CxpayCallback
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT         = 10;
    private const MAX_RETRY       = 3;

    public function __construct(
        private readonly string $cxpayNotifyUrl,
        private readonly string $callbackSecret,
        private readonly string $clientId
    ) {}

    /**
     * 向 CXPAY 发送到账回调，最多重试 MAX_RETRY 次，指数退避。
     *
     * @throws RuntimeException 所有重试均失败时抛出
     */
    public function send(
        string $outTradeNo,
        string $sourceBillId,
        string $amount,
        int    $occurredAt
    ): void {
        $lastError = null;
        for ($i = 0; $i < self::MAX_RETRY; $i++) {
            try {
                $this->doSend($outTradeNo, $sourceBillId, $amount, $occurredAt);
                return;
            } catch (RuntimeException $e) {
                $lastError = $e;
                if ($i < self::MAX_RETRY - 1) {
                    usleep(500_000 * (2 ** $i)); // 0.5s → 1s → 2s
                }
            }
        }
        throw new RuntimeException('CXPAY 回调发送失败（已重试 ' . self::MAX_RETRY . ' 次）: ' . $lastError->getMessage());
    }

    private function doSend(
        string $outTradeNo,
        string $sourceBillId,
        string $amount,
        int    $occurredAt
    ): void {
        $timestamp = time();
        $nonce     = bin2hex(random_bytes(16));

        // 构造签名字段集（与 Driver::notify() 的 ksort + http_build_query 完全对称）
        $fields = [
            'source_bill_id' => $sourceBillId,
            'out_trade_no'   => $outTradeNo,
            'money'          => $amount,
            'occurred_at'    => (string)$occurredAt,
            'timestamp'      => (string)$timestamp,
            'nonce'          => $nonce,
        ];
        ksort($fields);
        $canonical    = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $fields['sign'] = hash_hmac('sha256', $canonical, $this->callbackSecret);

        $path = '/notify/wxpay_clerk_adapter';
        $body = http_build_query($fields);

        // 生成请求签名头（用于 CXPAY 侧验证本服务身份）
        $reqTimestamp = (string)time();
        $reqNonce     = bin2hex(random_bytes(12));
        $bodyHash     = hash('sha256', $body);
        $reqCanonical = implode("\n", ['POST', $path, $reqTimestamp, $reqNonce, $bodyHash]);
        $reqSignature = hash_hmac('sha256', $reqCanonical, $this->callbackSecret);

        $ch = curl_init(rtrim($this->cxpayNotifyUrl, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                "X-CXPAY-Client: {$this->clientId}",
                "X-CXPAY-Timestamp: {$reqTimestamp}",
                "X-CXPAY-Nonce: {$reqNonce}",
                "X-CXPAY-Signature: {$reqSignature}",
            ],
        ]);

        $resp     = curl_exec($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errMsg !== '') {
            throw new RuntimeException("回调连接失败: {$errMsg}");
        }
        if ($httpCode !== 200 || strtolower(trim((string)$resp)) !== 'success') {
            throw new RuntimeException("CXPAY 未确认 [HTTP {$httpCode}]: " . substr((string)$resp, 0, 200));
        }
    }
}
