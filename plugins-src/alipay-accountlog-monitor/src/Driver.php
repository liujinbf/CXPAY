<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_accountlog_monitor;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\ServerPollingDriverInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use support\UrlGuard;

require_once __DIR__ . '/AccountLogClient.php';
require_once __DIR__ . '/AutoConfigHelper.php';

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
            'name'                           => 'alipay_accountlog_monitor',
            'title'                          => '支付宝商家账单监控',
            'description'                    => '通过支付宝官方账单查询 API（alipay.data.bill.accountlog.query）轮询到账记录，不依赖挂机 Cookie，不会漏单。',
            'supports_account_authorization' => true,
            'authorization_label'            => '扫码自动配置（需代理服务）',
            'pay_category'                   => 'alipay',
            'collection_mode'                => 'personal_qr',
            'monitor_mode'                   => $this->monitorMode(),
            'inputs' => [
                [
                    'type'    => 'notice',
                    'title'   => '配置说明',
                    'content' => "【自动配置】需先在通道配置中填写自动配置代理服务地址及密钥，然后点击【扫码自动配置】，在弹出的引导页面按步骤完成支付宝开放平台应用配置。\n"
                        . "【手动配置】请前往 open.alipay.com 申请应用并开通「账户流水」权限，手动填写 AppID、应用私钥和支付宝公钥。",
                    'tone'    => 'info',
                ],
                ['name' => 'qr_url',          'title' => '支付宝收款码内容',            'type' => 'string',   'required' => true],
                ['name' => 'app_id',           'title' => '支付宝开放平台 AppID',        'type' => 'string',   'required' => true],
                ['name' => 'app_private_key',  'title' => '应用私钥（RSA2，PKCS#8）',    'type' => 'password', 'required' => true],
                ['name' => 'alipay_public_key','title' => '支付宝公钥（用于验签）',        'type' => 'textarea', 'required' => true],
                [
                    'type'    => 'notice',
                    'title'   => '自动配置代理服务（可选，不填则使用手动配置）',
                    'content' => "如需使用【扫码自动配置】功能，请部署 alipay-autoconfig-proxy 代理服务，并在此填写以下三项。",
                    'tone'    => 'tip',
                ],
                ['name' => 'autoconfig_base_url',       'title' => '自动配置代理服务地址（HTTPS）',  'type' => 'string',   'required' => false],
                ['name' => 'autoconfig_client_id',      'title' => '代理服务 Client ID',             'type' => 'string',   'required' => false],
                ['name' => 'autoconfig_client_secret',  'title' => '代理服务请求签名密钥',           'type' => 'password', 'required' => false],
                ['name' => 'autoconfig_callback_secret','title' => '代理服务响应验签密钥',           'type' => 'password', 'required' => false],
            ],
        ];
    }

    /**
     * 发起自动配置会话。
     * 若未配置代理服务，返回错误提示而非假数据。
     */
    public function startAccountAuthorization(array $config): array
    {
        $helper = $this->makeAutoConfigHelper($config);
        if ($helper === null) {
            return [
                'status'  => 'NOT_AVAILABLE',
                'message' => '尚未配置自动配置代理服务，请手动填写 AppID 和私钥，或部署代理服务后再使用此功能',
            ];
        }
        $result = $helper->createAutoAuthSession();
        // 将 guide_url 作为扫码 QR 地址返回给前端
        return [
            'status'     => 'QR_READY',
            'session_id' => $result['session_id'],
            'qr_url'     => $result['guide_url'],
            'expires_at' => $result['expires_at'],
            // 临时保存生成的私钥，轮询确认后通过 config_patch 写入通道
            '_private_key_temp' => $result['private_key'],
            'message'    => $result['message'],
        ];
    }

    /**
     * 轮询自动配置会话状态。
     * CONFIRMED 时通过 config_patch 写回 app_id、alipay_public_key 和 app_private_key。
     */
    public function pollAccountAuthorization(string $sessionId, array $config): array
    {
        $helper = $this->makeAutoConfigHelper($config);
        if ($helper === null) {
            return ['status' => 'NOT_AVAILABLE', 'message' => '未配置自动配置代理服务'];
        }

        $result = $helper->pollAutoAuthSession($sessionId, $config);

        if ((string)($result['status'] ?? '') !== 'CONFIRMED') {
            return $result;
        }

        // CONFIRMED：构造 config_patch，将三个字段写回通道配置（由 MerchantChannelController 加密存储）
        $privateKey = trim((string)($config['_private_key_temp'] ?? ''));
        if ($privateKey === '') {
            return ['status' => 'FAILED', 'message' => '会话中缺少应用私钥，请重新发起自动配置'];
        }

        return [
            'status'  => 'CONFIRMED',
            'message' => $result['message'],
            'config_patch' => [
                'app_id'            => (string)($result['app_id']             ?? ''),
                'alipay_public_key' => (string)($result['alipay_public_key']  ?? ''),
                'app_private_key'   => $privateKey,
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
            return ['code' => -1, 'msg' => '支付宝应用私钥无法解析，请确认为 RSA2 PKCS#8 格式'];
        }
        $publicKey = openssl_pkey_get_public(AccountLogClient::publicKey((string)($config['alipay_public_key'] ?? '')));
        if ($publicKey === false) {
            return ['code' => -1, 'msg' => '支付宝公钥无法解析'];
        }

        // 可选的自动配置代理服务字段校验
        $autoconfigBaseUrl = trim((string)($config['autoconfig_base_url'] ?? ''));
        if ($autoconfigBaseUrl !== '') {
            if (strtolower((string)parse_url($autoconfigBaseUrl, PHP_URL_SCHEME)) !== 'https'
                || UrlGuard::resolve($autoconfigBaseUrl) === null) {
                return ['code' => -1, 'msg' => '自动配置代理服务地址必须是可解析的公网 HTTPS 地址'];
            }
            foreach (['autoconfig_client_id'] as $field) {
                if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', (string)($config[$field] ?? ''))) {
                    return ['code' => -1, 'msg' => "{$field} 格式不合法（3-128位字母数字）"];
                }
            }
            foreach (['autoconfig_client_secret', 'autoconfig_callback_secret'] as $field) {
                $v = (string)($config[$field] ?? '');
                if (strlen($v) < 32 || strlen($v) > 128) {
                    return ['code' => -1, 'msg' => "{$field} 长度必须为32至128位"];
                }
            }
        }

        $config['qr_url']            = $qrUrl;
        $config['app_id']            = $appId;
        $config['autoconfig_base_url'] = rtrim($autoconfigBaseUrl, '/');
        return $config;
    }

    /**
     * 根据通道配置构造 AutoConfigHelper 实例。
     * 如果未配置代理服务，返回 null。
     */
    private function makeAutoConfigHelper(array $config): ?AutoConfigHelper
    {
        $baseUrl        = trim((string)($config['autoconfig_base_url']       ?? ''));
        $clientId       = trim((string)($config['autoconfig_client_id']      ?? ''));
        $clientSecret   = trim((string)($config['autoconfig_client_secret']  ?? ''));
        $callbackSecret = trim((string)($config['autoconfig_callback_secret']?? ''));

        if ($baseUrl === '' || $clientId === ''
            || strlen($clientSecret) < 32 || strlen($callbackSecret) < 32) {
            return null;
        }

        return new AutoConfigHelper($baseUrl, $clientId, $clientSecret, $callbackSecret);
    }
}
