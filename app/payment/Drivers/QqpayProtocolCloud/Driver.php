<?php

declare(strict_types=1);

namespace app\payment\Drivers\QqpayProtocolCloud;

use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\MonitorableDriverInterface;
/**
 * QQ 钱包外部账单推送驱动。
 */
class Driver implements PaymentDriverInterface, MonitorableDriverInterface
{
    public function monitorMode(): string
    {
        return MonitorableDriverInterface::MODE_CALLBACK;
    }

    public function pay(array $params, array $config): array
    {
        return [
            'type'         => 'qrcode',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => $config['qr_url'] ?? ("/cashier/index.html?trade_no=" . $params['trade_no']),
        ];
    }

    /**
     * QQ 钱包协议云端回调 token 验签
     */
    public function notify(array $params, array $config): array
    {
        $notifyToken   = $config['notify_token'] ?? '';
        $receivedToken = $params['token'] ?? '';

        if (!empty($notifyToken)) {
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
            'name'        => 'qqpay_protocol_cloud',
            'title'       => 'QQ 钱包外部账单回调',
            'description' => '展示已配置的 QQ 钱包收款码，并接受具备共享 Token 的外部账单服务回调',
            'pay_category' => 'qqpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs'      => [
                ['name' => 'qr_url',       'title' => '个人 QQ 錢包收款码解析链接',       'type' => 'textarea', 'required' => true],
                ['name' => 'notify_token', 'title' => '云端回调鉴权 Token',            'type' => 'string',   'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['qr_url'])) {
            return ['code' => -1, 'msg' => 'QQ 钱包收款码内容不能为空'];
        }
        if (empty($config['notify_token'])) {
            return ['code' => -1, 'msg' => '云端回调鉴权 Token 不能为空'];
        }
        return $config;
    }
}
