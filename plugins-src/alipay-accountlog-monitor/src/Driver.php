<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_accountlog_monitor;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\ServerPollingDriverInterface;

require_once __DIR__ . '/AccountLogClient.php';

final class Driver implements PaymentDriverInterface, MonitorableDriverInterface, ServerPollingDriverInterface
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
            'type' => 'qrcode',
            'trade_no' => (string)($params['trade_no'] ?? ''),
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount' => number_format((float)($params['money'] ?? 0), 2, '.', ''),
            'pay_url' => $qrUrl,
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
            'title' => '支付宝商家账单（手动配置）',
            'description' => '可选插件：固定收款码 + 支付宝开放平台账单查询；不创建官方支付订单，不支持自动申请应用',
            'pay_category' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs' => [
                [
                    'type' => 'notice',
                    'title' => '手动配置流程',
                    'content' => "1. 登录支付宝开放平台（open.alipay.com），使用具备相应资质的主体创建应用。\n"
                        . "2. 在应用中配置 RSA2 密钥，保存应用 AppID、应用私钥，并复制支付宝公钥。\n"
                        . "3. 为应用申请并开通 alipay.data.bill.accountlog.query（商家账单查询）权限，按平台要求完成签约和发布。\n"
                        . "4. 填写下方四项配置并保存；启用通道后，查询成功会显示在线，权限或密钥错误会显示离线并后台重试。\n"
                        . "注意：这里不需要支付宝登录密码、Cookie 或 PID；应用私钥只保存在 CXPAY 加密配置中。",
                    'tone' => 'warning',
                ],
                ['name' => 'qr_url', 'title' => '支付宝收款码内容', 'type' => 'string', 'required' => true],
                ['name' => 'app_id', 'title' => '支付宝开放平台 AppID', 'type' => 'string', 'required' => true],
                ['name' => 'app_private_key', 'title' => '应用私钥（RSA2）', 'type' => 'password', 'required' => true],
                ['name' => 'alipay_public_key', 'title' => '支付宝公钥（用于响应验签）', 'type' => 'textarea', 'required' => true],
            ],
        ];
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
