<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayRecptAfkPc;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 微信收款码 PC 挂机账单捕获驱动插件
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
            'pay_url'      => $config['qr_code_url'] ?? '',
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
            'name'        => 'wxpay_recpt_afk_pc',
            'title'       => '微信收款码 PC 挂机账单驱动',
            'description' => '通过 Windows PC 挂机软件监听电脑版微信收款通知实现免签挂账',
        ];
    }
}
