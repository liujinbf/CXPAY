<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayAppAsst;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 微信 App 挂机助手驱动插件
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
            'pay_url'      => $config['qr_code_url'] ?? 'wxp://f2f0',
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
            'name'        => 'wxpay_app_asst',
            'title'       => '微信 App 挂机助手驱动',
            'description' => '通过安卓手机挂机 App 监听微信支付到账通知实现免签挂账',
        ];
    }
}
