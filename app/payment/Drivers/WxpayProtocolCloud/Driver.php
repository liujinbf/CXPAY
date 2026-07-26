<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayProtocolCloud;

use app\payment\Contracts\PaymentDriverInterface;
use app\service\ProxyIsolationService;

/**
 * 微信协议云端 (小账本/收款单) 免挂驱动插件 (集成 ProxyIsolationService 住宅 IP 与指纹隔离)
 */
class Driver implements PaymentDriverInterface
{
    /** 小账本 AppID */
    public const APP_ID_BOOK = 'wx28be8489b7a36aaa';
    /** 收款单 AppID */
    public const APP_ID_RECPT = 'wx264e9b6d4d484f51';

    protected ProxyIsolationService $proxyService;

    public function __construct()
    {
        $this->proxyService = new ProxyIsolationService();
    }

    public function pay(array $params, array $config): array
    {
        $channelId  = (int)($config['channel_id'] ?? 1);
        $merchantId = (int)($params['pid'] ?? 1);

        // 获取该通道隔离的独立 HTTP 指纹与住宅 IP
        $context = $this->proxyService->getIsolatedContext($channelId, $merchantId);

        $appType = $config['app_type'] ?? 'book';

        $payUrl = $config['qr_url'] ?? ("/cashier/index.html?trade_no=" . $params['trade_no']);

        return [
            'type'         => 'qrcode',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => $payUrl,
            'context'      => $context,
        ];
    }

    public function notify(array $params, array $config): array
    {
        return [
            'success'      => true,
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no'     => $params['trade_no'] ?? '',
            'amount'       => (float)($params['money'] ?? 0),
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name'        => 'wxpay_protocol_cloud',
            'title'       => '微信协议云端 (小账本 / 收款单免挂 · 带多租户 IP 隔离)',
            'description' => '官方微信小账本/收款单云端协议免挂，内置住宅代理 IP 与客户端指纹防连坐隔离',
            'inputs'      => [
                ['name' => 'openid', 'title' => '微信 OpenID / UIN', 'type' => 'string', 'required' => true],
                ['name' => 'app_type', 'title' => '模式 (book 小账本 / recpt 收款单)', 'type' => 'string', 'required' => true],
                ['name' => 'sid', 'title' => '会话 Session SID 令牌', 'type' => 'string', 'required' => true],
                ['name' => 'proxy_ip', 'title' => '独立住宅代理 IP (留空使用系统代理池)', 'type' => 'string'],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['openid'])) {
            return ['code' => -1, 'msg' => '微信 OpenID 不能为空'];
        }
        return $config;
    }
}
