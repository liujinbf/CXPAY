<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayProtocolCloud;

use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\MonitorableDriverInterface;
/**
 * 微信外部账单推送驱动。
 */
class Driver implements PaymentDriverInterface, MonitorableDriverInterface
{
    /** 小账本 AppID */
    public const APP_ID_BOOK = 'wx28be8489b7a36aaa';
    /** 收款单 AppID */
    public const APP_ID_RECPT = 'wx264e9b6d4d484f51';

    public function monitorMode(): string
    {
        return MonitorableDriverInterface::MODE_CALLBACK;
    }

    public function pay(array $params, array $config): array
    {
        $payUrl = $config['qr_url'] ?? ("/cashier/index.html?trade_no=" . $params['trade_no']);

        return [
            'type'         => 'qrcode',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => $payUrl,
        ];
    }

    /**
     * 协议云端回调 token 验签
     * 云端服务推送时须携带 token 字段，与 config 中的 notify_token 比对
     */
    public function notify(array $params, array $config): array
    {
        $notifyToken = $config['notify_token'] ?? '';
        $receivedToken = $params['token'] ?? '';

        if (!empty($notifyToken)) {
            // 配置了 token 时严格校验
            $verified = !empty($receivedToken) && hash_equals($notifyToken, $receivedToken);
        } else {
            $verified = false;
        }

        return [
            'success'      => $verified,
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
            'title'       => '微信外部账单回调',
            'description' => '展示已配置的微信收款码，并接受具备共享 Token 的外部账单服务回调',
            'pay_category' => 'wxpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs'      => [
                ['name' => 'qr_url',        'title' => '微信收款码内容',               'type' => 'string', 'required' => true],
                ['name' => 'notify_token',  'title' => '云端回调鉴权 Token',          'type' => 'string', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['qr_url'])) {
            return ['code' => -1, 'msg' => '微信收款码内容不能为空'];
        }
        if (empty($config['notify_token'])) {
            return ['code' => -1, 'msg' => '云端回调鉴权 Token 不能为空'];
        }
        return $config;
    }
}
