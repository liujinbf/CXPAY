<?php

declare(strict_types=1);

namespace plugin\cxpay\wxpay_clerk_adapter;

use app\payment\Contracts\AccountCapabilityDetectorInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\OperationsStatusInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\PaymentEventReviewInterface;
use support\UrlGuard;

require_once __DIR__ . '/ProviderClient.php';

/**
 * 微信店员到账通知适配器。
 * 依赖独立 Gewe/iPad 协议服务，账号风控和协议兼容性需由使用者评估。
 */
final class Driver implements PaymentDriverInterface, MonitorableDriverInterface, AccountCapabilityDetectorInterface, AccountAuthorizationInterface, PaymentEventReviewInterface, OperationsStatusInterface
{
    private ProviderClient $provider;

    public function __construct(?ProviderClient $provider = null)
    {
        $this->provider = $provider ?? new ProviderClient();
    }

    public function monitorMode(): string
    {
        return self::MODE_CALLBACK;
    }

    public function pay(array $params, array $config): array
    {
        $tradeNo = trim((string)($params['trade_no'] ?? ''));
        $amount = $this->normalizeAmount($params['money'] ?? '');
        $expiresAt = (int)($params['expire_time'] ?? 0);
        $now = time();
        if (!preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $tradeNo)
            || $amount === null
            || $expiresAt <= $now
            || $expiresAt > $now + 3600) {
            throw new \RuntimeException('店员免挂订单登记参数不完整');
        }
        $registered = $this->provider->registerOrder($config, $tradeNo, $amount, $expiresAt);
        if (($registered['accepted'] ?? false) !== true) {
            throw new \RuntimeException('店员服务没有确认订单登记');
        }
        return [
            'type'         => 'qrcode',
            'trade_no'     => $tradeNo,
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount'       => $amount,
            'pay_url'      => trim((string)($config['qr_url'] ?? '')),
        ];
    }

    public function notify(array $params, array $config): array
    {
        $occurredAt = (int)($params['occurred_at'] ?? 0);
        $timestamp = (int)($params['timestamp'] ?? 0);
        $moneyRaw = trim((string)($params['money'] ?? ''));
        $money = $this->normalizeAmount($moneyRaw) ?? '';
        $fields = [
            'source_bill_id' => trim((string)($params['source_bill_id'] ?? '')),
            'out_trade_no'   => trim((string)($params['out_trade_no'] ?? '')),
            'money'          => $money,
            'occurred_at'    => (string)$occurredAt,
            'timestamp'      => (string)$timestamp,
            'nonce'          => trim((string)($params['nonce'] ?? '')),
        ];
        $received = strtolower(trim((string)($params['sign'] ?? '')));
        $secrets = array_values(array_filter([
            (string)($config['callback_secret'] ?? ''),
            (string)($config['callback_secret_previous'] ?? ''),
        ], static fn (string $secret): bool => strlen($secret) >= 32 && strlen($secret) <= 128));
        $now = time();
        $validShape = preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $fields['source_bill_id']) === 1
            && preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $fields['out_trade_no']) === 1
            && preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $fields['nonce']) === 1
            && $money !== ''
            && $occurredAt >= $now - 604800
            && $occurredAt <= $now + 300
            && abs($now - $timestamp) <= 300
            && preg_match('/^[a-f0-9]{64}$/', $received) === 1;
        ksort($fields);
        $verified = false;
        $canonical = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        foreach ($secrets as $secret) {
            $verified = hash_equals(hash_hmac('sha256', $canonical, $secret), $received) || $verified;
        }
        $verified = $validShape && $verified;

        return [
            'success'      => $verified,
            'out_trade_no' => $verified ? $fields['out_trade_no'] : '',
            'trade_no'     => $verified ? $fields['source_bill_id'] : '',
            'amount'       => $verified ? (float)$money : 0.0,
        ];
    }

    /**
     * 通过云端店员服务查询指定平台订单的支付状态。
     * 云服务若能找到匹配记录则返回 paid=true；查询失败按未支付处理。
     */
    public function query(string $tradeNo, array $config): array
    {
        try {
            $result = $this->provider->queryOrder($config, $tradeNo);
            return ['paid' => ($result['paid'] ?? false) === true];
        } catch (\Throwable) {
            return ['paid' => false];
        }
    }

    public function getMeta(): array
    {
        return [
            'name' => 'wxpay_clerk_adapter',
            'title' => '微信店员到账通知（Gewe/iPad 协议）',
            'description' => '使用个人微信店员账号和独立 Gewe 服务接收到账通知；无需商户设备常驻，但存在账号风控与协议变化风险',
            'supports_account_authorization' => true,
            'supports_account_capability_detection' => true,
            'authorization_label' => '微信添加店员绑定',
            'pay_category' => 'wxpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs' => [
                [
                    'type' => 'notice',
                    'title' => '📖 微信店员免挂（Gewe/iPad 协议）配置流程与原理说明',
                    'content' => "💡 原理与安全优势：\n"
                        . "• 资金收款：客户扫码付款后，资金 100% 直入【老板微信零钱/银行卡】（老板大号无需在服务器登录，资金绝对安全 0 风险）。\n"
                        . "• 自动核销：老板微信收到款时，微信官方会给绑定的【店员小号】推送通知；服务器通过 iPad 协议拦截通知后自动核销订单并触发系统发货。\n\n"
                        . "📌 快速配置三步法：\n"
                        . "1. 【绑定店员 (手机操作)】老板微信进入「微信收款小账本」小程序 -> 点「店员管理」->「添加店员」，用店员小号微信扫码接受邀请（仅需绑定 1 次）。\n"
                        . "2. 【配置收款码】建议保存老板微信的「赞赏码」或「收款小账本物料码」（长期有效），点击下方「📷 上传收款码图片」自动识别填入。\n"
                        . "3. 【服务端接入】在服务器部署 wxpay-clerk-service 服务，店员小号扫码登录维持在线；将服务地址 (HTTPS) 及签名密钥填入下方并保存。",
                    'tone' => 'info',
                ],
                ['name' => 'qr_url', 'title' => '微信个人收款码 / 赞赏码内容', 'type' => 'string', 'required' => true],
                ['name' => 'monitor_base_url', 'title' => '店员免挂云服务地址', 'type' => 'string', 'required' => true],
                ['name' => 'account_id', 'title' => '店员绑定账号 ID（添加成功后自动写入）', 'type' => 'string', 'required' => false],
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
            return ['code' => -1, 'msg' => '微信个人收款码/赞赏码不能为空或格式不合法'];
        }
        $baseUrl = rtrim((string)($config['monitor_base_url'] ?? ''), '/');
        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
            || UrlGuard::resolve($baseUrl) === null) {
            return ['code' => -1, 'msg' => '店员服务地址必须是可解析的公网 HTTPS 地址'];
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
            return ['code' => -1, 'msg' => '请先停用通道并完成微信店员绑定'];
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

    public function startAccountAuthorization(array $config): array
    {
        return $this->provider->createAuthSession($config);
    }

    public function pollAccountAuthorization(string $sessionId, array $config): array
    {
        return $this->provider->getAuthSession($config, $sessionId);
    }

    public function detectAccountCapabilities(array $config): array
    {
        try {
            $result = $this->provider->capabilities($config);
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
                'status'       => $status,
                'message'      => (string)($result['message'] ?? '店员能力检测完成'),
                'capabilities' => (array)($result['capabilities'] ?? []),
            ];
        } catch (\Throwable $e) {
            return ['status' => self::STATUS_TEMPORARY_ERROR, 'message' => $e->getMessage()];
        }
    }

    public function reviewEvents(array $config): array
    {
        return $this->provider->reviewEvents($config);
    }

    public function operationsStatus(array $config): array
    {
        return $this->provider->operationsStatus($config);
    }

    public function matchReviewEvent(array $config, int $eventId, string $tradeNo, string $operator, string $note): array
    {
        return $this->provider->matchReviewEvent($config, $eventId, $tradeNo, $operator, $note);
    }

    public function ignoreReviewEvent(array $config, int $eventId, string $operator, string $note): array
    {
        return $this->provider->ignoreReviewEvent($config, $eventId, $operator, $note);
    }

    private function normalizeAmount(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if (!preg_match('/^(\d{1,5})(?:\.(\d{1,2}))?$/', $raw, $matches)) {
            return null;
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        $cents = (int)$matches[1] * 100 + (int)$fraction;
        if ($cents < 1 || $cents > 5_000_000) {
            return null;
        }
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
