<?php

declare(strict_types=1);

namespace app\payment\Drivers\AlipayScanBill;

use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\MonitorableDriverInterface;

/**
 * 支付宝外部账单推送驱动。
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
            'pay_url'      => $config['qr_url'] ?? 'https://qr.alipay.com/',
        ];
    }

    /**
     * 支付宝扫码免挂回调 token 验签
     * 得到账单后云端推送时须携带 token 字段
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
            'name'        => 'alipay_scan_bill',
            'title'       => '支付宝旧版账单回调（已停用）',
            'description' => '旧版共享 Token 协议缺少订单登记和防重放能力，请安装支付宝扫码免挂插件',
            'deprecated'  => true,
            'replacement' => 'alipay_scan_monitor',
            'pay_category' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => $this->monitorMode(),
            'inputs'      => [
                ['name' => 'qr_url',        'title' => '个人支付宝收款码解析链接',          'type' => 'string',   'required' => true],
                ['name' => 'notify_token',  'title' => '云端回调鉴权 Token',              'type' => 'string',   'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['qr_url'])) {
            return ['code' => -1, 'msg' => '支付宝收款码内容不能为空'];
        }
        if (empty($config['notify_token'])) {
            return ['code' => -1, 'msg' => '云端回调鉴权 Token 不能为空'];
        }
        return $config;
    }
}
