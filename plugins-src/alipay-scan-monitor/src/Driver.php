<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_scan_monitor;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use support\UrlGuard;

require_once __DIR__ . '/ProviderClient.php';

final class Driver implements PaymentDriverInterface, MonitorableDriverInterface, AccountAuthorizationInterface
{
    public function monitorMode(): string
    {
        return self::MODE_CALLBACK;
    }

    public function pay(array $params, array $config): array
    {
        $tradeNo = trim((string)($params['trade_no'] ?? ''));
        $amount = number_format((float)($params['money'] ?? 0), 2, '.', '');
        $expiresAt = (int)($params['expire_time'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $tradeNo)
            || (float)$amount <= 0 || (float)$amount > 50000
            || $expiresAt <= time() || $expiresAt > time() + 3600) {
            throw new \RuntimeException('支付宝扫码订单登记参数不完整');
        }
        $registered = (new ProviderClient())->registerOrder($config, $tradeNo, $amount, $expiresAt);
        if (($registered['accepted'] ?? false) !== true) {
            throw new \RuntimeException('支付宝云账单服务没有确认订单登记');
        }
        return [
            'type' => 'qrcode',
            'trade_no' => $tradeNo,
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount' => $amount,
            'pay_url' => trim((string)($config['qr_url'] ?? '')),
        ];
    }

    public function notify(array $params, array $config): array
    {
        $occurredAt = (int)($params['occurred_at'] ?? 0);
        $timestamp = (int)($params['timestamp'] ?? 0);
        $moneyRaw = trim((string)($params['money'] ?? ''));
        $money = number_format((float)$moneyRaw, 2, '.', '');
        $fields = [
            'source_bill_id' => trim((string)($params['source_bill_id'] ?? '')),
            'out_trade_no' => trim((string)($params['out_trade_no'] ?? '')),
            'money' => $money,
            'occurred_at' => (string)$occurredAt,
            'timestamp' => (string)$timestamp,
            'nonce' => trim((string)($params['nonce'] ?? '')),
        ];
        $received = strtolower(trim((string)($params['sign'] ?? '')));
        $validShape = preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $fields['source_bill_id']) === 1
            && preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $fields['out_trade_no']) === 1
            && preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $fields['nonce']) === 1
            && preg_match('/^[a-f0-9]{64}$/', $received) === 1
            && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $moneyRaw) === 1
            && (float)$money > 0 && (float)$money <= 50000
            && $occurredAt >= time() - 604800 && $occurredAt <= time() + 300
            && abs(time() - $timestamp) <= 300;
        ksort($fields);
        $canonical = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $verified = false;
        foreach (['callback_secret', 'callback_secret_previous'] as $name) {
            $secret = (string)($config[$name] ?? '');
            if (strlen($secret) >= 32 && strlen($secret) <= 128) {
                $verified = hash_equals(hash_hmac('sha256', $canonical, $secret), $received) || $verified;
            }
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

    public function reviewEvents(array $config): array
    {
        return (new ProviderClient())->reviewEvents($config);
    }

    public function operationsStatus(array $config): array
    {
        return (new ProviderClient())->operationsStatus($config);
    }

    public function startAccountAuthorization(array $config): array
    {
        return (new ProviderClient())->createAuthSession($config);
    }

    public function pollAccountAuthorization(string $sessionId, array $config): array
    {
        return (new ProviderClient())->getAuthSession($config, $sessionId);
    }

    public function matchReviewEvent(array $config, int $eventId, string $tradeNo, string $operator, string $note): array
    {
        return (new ProviderClient())->matchReviewEvent($config, $eventId, $tradeNo, $operator, $note);
    }

    public function ignoreReviewEvent(array $config, int $eventId, string $operator, string $note): array
    {
        return (new ProviderClient())->ignoreReviewEvent($config, $eventId, $operator, $note);
    }

    public function getMeta(): array
    {
        return [
            'name' => 'alipay_scan_monitor',
            'title' => '支付宝扫码免挂',
            'description' => '固定支付宝个人收款码 + 授权云账单服务订单登记与签名到账回调',
            'supports_account_authorization' => true,
            'authorization_label' => '支付宝扫码登录',
            'pay_category' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs' => [
                ['name' => 'qr_url', 'title' => '支付宝个人收款码内容', 'type' => 'string', 'required' => true],
                ['name' => 'monitor_base_url', 'title' => '支付宝云账单服务地址', 'type' => 'string', 'required' => true],
                ['name' => 'account_id', 'title' => '云账单账号 ID（扫码成功后自动写入）', 'type' => 'string', 'required' => false],
                ['name' => 'client_id', 'title' => '接口 Client ID', 'type' => 'string', 'required' => true],
                ['name' => 'client_secret', 'title' => '接口请求签名密钥', 'type' => 'password', 'required' => true],
                ['name' => 'callback_secret', 'title' => '响应及到账回调密钥', 'type' => 'password', 'required' => true],
                ['name' => 'callback_secret_previous', 'title' => '上一回调密钥（轮换宽限期，可选）', 'type' => 'password', 'required' => false],
            ],
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $qrUrl = trim((string)($config['qr_url'] ?? ''));
        if ($qrUrl === '' || strlen($qrUrl) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $qrUrl)) {
            return ['code' => -1, 'msg' => '支付宝个人收款码不能为空或格式不合法'];
        }
        $baseUrl = rtrim((string)($config['monitor_base_url'] ?? ''), '/');
        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
            || UrlGuard::resolve($baseUrl) === null) {
            return ['code' => -1, 'msg' => '支付宝云账单服务必须是可解析的公网 HTTPS 地址'];
        }
        $accountId = trim((string)($config['account_id'] ?? ''));
        if ($accountId !== '' && !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
            return ['code' => -1, 'msg' => 'account_id 格式不合法'];
        }
        if ($accountId === '' && (int)($channelRow['status'] ?? 0) === 1) {
            return ['code' => -1, 'msg' => '请先停用通道并完成支付宝扫码登录'];
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', (string)($config['client_id'] ?? ''))) {
            return ['code' => -1, 'msg' => 'client_id 格式不合法'];
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
        $config['monitor_base_url'] = $baseUrl;
        return $config;
    }
}
