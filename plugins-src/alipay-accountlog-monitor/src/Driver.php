<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_accountlog_monitor;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\ServerPollingDriverInterface;

require_once __DIR__ . '/AccountLogClient.php';
require_once __DIR__ . '/AutoConfigHelper.php';

use app\payment\Contracts\AccountAuthorizationInterface;

final class Driver implements PaymentDriverInterface, MonitorableDriverInterface, ServerPollingDriverInterface, AccountAuthorizationInterface
{
    public function monitorMode(): string
    {
        return self::MODE_SERVER;
    }

    public function pay(array $params, array $config): array
    {
        $qrUrl = trim((string)($config['qr_url'] ?? ''));
        if ($qrUrl === '') {
            throw new \RuntimeException('支付宝收款码未配置');
        }
        return [
            'type'         => 'qrcode',
            'trade_no'     => (string)($params['trade_no'] ?? ''),
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount'       => number_format((float)($params['money'] ?? 0), 2, '.', ''),
            'pay_url'      => $qrUrl,
        ];
    }

    public function notify(array $params, array $config): array
    {
        return ['success' => false];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function pollPaymentEvents(array $config, int $since, int $until): array
    {
        return (new AccountLogClient())->query(
            trim((string)($config['app_id'] ?? '')),
            (string)($config['app_private_key'] ?? ''),
            (string)($config['alipay_public_key'] ?? ''),
            $since,
            $until
        );
    }

    public function getMeta(): array
    {
        return [
            'name' => 'alipay_accountlog_monitor',
            'title' => '支付宝商家账单（免 CK 自动配置 / 手动配置）',
            'description' => '支持「自动配置」（扫码登录自动申请应用与设密钥）与「手动配置」；使用官方公开账单接口，不存在漏单',
            'supports_account_authorization' => true,
            'authorization_label' => '扫码登录自动完成配置',
            'pay_category' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs' => [
                [
                    'type' => 'notice',
                    'title' => '配置说明',
                    'content' => "推荐使用「自动配置」：点击【扫码登录自动完成配置】，手机支付宝扫码后将自动为您申请应用并设置 RSA2 密钥。\n"
                        . "如需手动配置：请前往 open.alipay.com 填入 AppID 与应用私钥、支付宝公钥。",
                    'tone' => 'info',
                ],
                ['name' => 'qr_url', 'title' => '支付宝收款码内容', 'type' => 'string', 'required' => true],
                ['name' => 'app_id', 'title' => '支付宝开放平台 AppID', 'type' => 'string', 'required' => true],
                ['name' => 'app_private_key', 'title' => '应用私钥（RSA2）', 'type' => 'password', 'required' => true],
                ['name' => 'alipay_public_key', 'title' => '支付宝公钥（用于响应验签）', 'type' => 'textarea', 'required' => true],
            ],
        ];
    }

    public function startAccountAuthorization(array $config): array
    {
        return (new AutoConfigHelper())->createAutoAuthSession();
    }

    public function pollAccountAuthorization(string $sessionId, array $config): array
    {
        return (new AutoConfigHelper())->pollAutoAuthSession($sessionId);
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $qrUrl = trim((string)($config['qr_url'] ?? ''));
        if ($qrUrl === '' || strlen($qrUrl) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $qrUrl)) {
            return ['code' => -1, 'msg' => '支付宝收款码不能为空或格式不合法'];
        }
        $appId = trim((string)($config['app_id'] ?? ''));
        if (!preg_match('/^[0-9]{8,32}$/', $appId)) {
            return ['code' => -1, 'msg' => '支付宝开放平台 AppID 格式不合法'];
        }
        $privateKey = openssl_pkey_get_private(AccountLogClient::privateKey((string)($config['app_private_key'] ?? '')));
        if ($privateKey === false) {
            return ['code' => -1, 'msg' => '支付宝应用私钥无法解析'];
        }
        $publicKey = openssl_pkey_get_public(AccountLogClient::publicKey((string)($config['alipay_public_key'] ?? '')));
        if ($publicKey === false) {
            return ['code' => -1, 'msg' => '支付宝公钥无法解析'];
        }
        $config['qr_url'] = $qrUrl;
        $config['app_id'] = $appId;
        return $config;
    }
}
