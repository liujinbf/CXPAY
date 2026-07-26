<?php

declare(strict_types=1);

namespace app\payment\Drivers\AlipayScanBill;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 支付宝扫码免挂 Cookie 驱动插件 (alipay_scan_bill)
 */
class Driver implements PaymentDriverInterface
{
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
            'name'        => 'alipay_scan_bill',
            'title'       => '支付宝扫码免挂 (alipay_scan_bill)',
            'description' => '扫码登录支付宝网页版获取 Cookie 自动监控最新账单到账',
            'inputs'      => [
                ['name' => 'cookie', 'title' => '支付宝网页版登录 Cookie (Base64)', 'type' => 'textarea', 'required' => true],
                ['name' => 'qr_url', 'title' => '个人支付宝收款码解析链接', 'type' => 'string', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['cookie']) && empty($config['qr_url'])) {
            return ['code' => -1, 'msg' => '请填写支付宝 Cookie 或解析出的收款码链接'];
        }
        return $config;
    }
}
