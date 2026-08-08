<?php

declare(strict_types=1);

namespace plugin\cxpay\wxpay_cloud_adapter;

use app\payment\Contracts\AccountCapabilityDetectorInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\OperationsStatusInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\PaymentEventReviewInterface;
use support\UrlGuard;

require_once __DIR__ . '/ProviderClient.php';

final class Driver implements PaymentDriverInterface, MonitorableDriverInterface, AccountCapabilityDetectorInterface, AccountAuthorizationInterface, PaymentEventReviewInterface, OperationsStatusInterface
{
    public function monitorMode(): string
    {
        return self::MODE_CALLBACK;
    }

    public function pay(array $params, array $config): array
    {
        $tradeNo = (string)($params['trade_no'] ?? '');
        $amount = number_format((float)($params['money'] ?? 0), 2, '.', '');
        $expiresAt = (int)($params['expire_time'] ?? 0);
        if ($tradeNo === '' || (float)$amount <= 0 || $expiresAt <= time()) {
            throw new \RuntimeException('云监控订单登记参数不完整');
        }
        (new ProviderClient())->registerOrder($config, $tradeNo, $amount, $expiresAt);
        return [
            'type' => 'qrcode',
            'trade_no' => $tradeNo,
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount' => $params['money'] ?? '0.00',
            'pay_url' => trim((string)($config['qr_url'] ?? '')),
        ];
    }

    public function notify(array $params, array $config): array
    {
        $occurredAt = (int)($params['occurred_at'] ?? 0);
        $timestamp = (int)($params['timestamp'] ?? 0);
        $money = number_format((float)($params['money'] ?? 0), 2, '.', '');
        $fields = [
            'source_bill_id' => trim((string)($params['source_bill_id'] ?? '')),
            'out_trade_no' => trim((string)($params['out_trade_no'] ?? '')),
            'money' => $money,
            'occurred_at' => (string)$occurredAt,
            'timestamp' => (string)$timestamp,
            'nonce' => trim((string)($params['nonce'] ?? '')),
        ];
        $received = strtolower(trim((string)($params['sign'] ?? '')));
        $secrets = array_values(array_filter([
            (string)($config['callback_secret'] ?? ''),
            (string)($config['callback_secret_previous'] ?? ''),
        ], static fn (string $secret): bool => strlen($secret) >= 32 && strlen($secret) <= 128));
        $validShape = $fields['source_bill_id'] !== ''
            && $fields['out_trade_no'] !== ''
            && $fields['nonce'] !== ''
            && (float)$money > 0
            && $occurredAt > 0
            && abs(time() - $timestamp) <= 300
            && preg_match('/^[a-f0-9]{64}$/', $received) === 1;
        ksort($fields);
        $verified = false;
        $canonical = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        foreach ($secrets as $secret) {
            $verified = hash_equals(hash_hmac('sha256', $canonical, $secret), $received) || $verified;
        }
        $verified = $validShape && $verified;

        return [
            'success' => $verified,
            'out_trade_no' => $verified ? $fields['out_trade_no'] : '',
            'trade_no' => $verified ? $fields['source_bill_id'] : '',
            'amount' => $verified ? (float)$money : 0.0,
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name' => 'wxpay_cloud_adapter',
            'title' => '微信云监控适配器',
            'description' => '连接独立 WX Monitor Cloud；不接入微信官方商户支付',
            'supports_account_authorization' => true,
            'supports_account_capability_detection' => true,
            'authorization_label' => '微信扫码授权',
            'pay_category' => 'wxpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs' => [
                ['name' => 'qr_url', 'title' => '微信个人收款码内容', 'type' => 'string', 'required' => true],
                ['name' => 'monitor_base_url', 'title' => 'WX Monitor Cloud 地址', 'type' => 'string', 'required' => true],
                ['name' => 'account_id', 'title' => '云监控账号 ID（扫码成功后自动写入）', 'type' => 'string', 'required' => false],
                ['name' => 'client_id', 'title' => '接口 Client ID', 'type' => 'string', 'required' => true],
                ['name' => 'client_secret', 'title' => '接口请求签名密钥', 'type' => 'password', 'required' => true],
                ['name' => 'callback_secret', 'title' => '能力响应及到账回调密钥', 'type' => 'password', 'required' => true],
                ['name' => 'callback_secret_previous', 'title' => '上一回调密钥（轮换宽限期，可选）', 'type' => 'password', 'required' => false],
            ],
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $qrUrl = trim((string)($config['qr_url'] ?? ''));
        if ($qrUrl === '' || strlen($qrUrl) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $qrUrl)) {
            return ['code' => -1, 'msg' => '微信个人收款码不能为空或格式不合法'];
        }
        if (UrlGuard::resolve((string)($config['monitor_base_url'] ?? '')) === null) {
            return ['code' => -1, 'msg' => 'WX Monitor Cloud 必须是可解析的公网 HTTP(S) 地址'];
        }
        foreach (['client_id'] as $field) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', (string)($config[$field] ?? ''))) {
                return ['code' => -1, 'msg' => "{$field} 格式不合法"];
            }
        }
        $accountId = trim((string)($config['account_id'] ?? ''));
        if ($accountId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
            return ['code' => -1, 'msg' => 'account_id 格式不合法'];
        }
        if ($accountId === '' && (int)($channelRow['status'] ?? 0) === 1) {
            return ['code' => -1, 'msg' => '请先停用通道并完成微信扫码授权'];
        }
        foreach (['client_secret', 'callback_secret'] as $field) {
            $secret = (string)($config[$field] ?? '');
            if (strlen($secret) < 32 || strlen($secret) > 128) {
                return ['code' => -1, 'msg' => "{$field} 长度必须为32至128位"];
            }
        }
        $previous = (string)($config['callback_secret_previous'] ?? '');
        if ($previous !== '' && (strlen($previous) < 32 || strlen($previous) > 128)) {
            return ['code' => -1, 'msg' => 'callback_secret_previous 长度必须为32至128位'];
        }
        $config['qr_url'] = $qrUrl;
        $config['monitor_base_url'] = rtrim((string)$config['monitor_base_url'], '/');
        return $config;
    }

    public function startAccountAuthorization(array $config): array
    {
        return (new ProviderClient())->createAuthSession($config);
    }

    public function pollAccountAuthorization(string $sessionId, array $config): array
    {
        return (new ProviderClient())->getAuthSession($config, $sessionId);
    }

    public function detectAccountCapabilities(array $config): array
    {
        try {
            $result = (new ProviderClient())->capabilities($config);
            $status = (string)($result['status'] ?? self::STATUS_UNKNOWN);
            $allowed = [
                self::STATUS_RECEIPT_AVAILABLE,
                self::STATUS_RECEIPT_NOT_OPENED,
                self::STATUS_BOOK_AVAILABLE,
                self::STATUS_REAUTH_REQUIRED,
                self::STATUS_TEMPORARY_ERROR,
                self::STATUS_UNKNOWN,
            ];
            if (!in_array($status, $allowed, true)) {
                $status = self::STATUS_UNKNOWN;
            }
            return [
                'status' => $status,
                'message' => (string)($result['message'] ?? '能力探测完成'),
                'capabilities' => (array)($result['capabilities'] ?? []),
            ];
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_TEMPORARY_ERROR, 'message' => $e->getMessage()];
        }
    }

    public function reviewEvents(array $config): array
    {
        return (new ProviderClient())->reviewEvents($config);
    }

    public function operationsStatus(array $config): array
    {
        return (new ProviderClient())->operationsStatus($config);
    }

    public function matchReviewEvent(array $config, int $eventId, string $tradeNo, string $operator, string $note): array
    {
        return (new ProviderClient())->matchReviewEvent($config, $eventId, $tradeNo, $operator, $note);
    }

    public function ignoreReviewEvent(array $config, int $eventId, string $operator, string $note): array
    {
        return (new ProviderClient())->ignoreReviewEvent($config, $eventId, $operator, $note);
    }
}
