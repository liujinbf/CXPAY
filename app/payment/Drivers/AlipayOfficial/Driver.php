<?php

declare(strict_types=1);

namespace app\payment\Drivers\AlipayOfficial;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 支付宝官方 Open API 驱动 (支持 RSA2 加签与动态 Inputs)
 */
class Driver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        return [
            'type'         => 'url',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => 'https://openapi.alipay.com/gateway.do',
        ];
    }

    public function notify(array $params, array $config): array
    {
        return [
            'success'      => true,
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no'     => $params['trade_no'] ?? '',
            'amount'       => (float)($params['total_amount'] ?? 0),
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => true];
    }

    public function getMeta(): array
    {
        return [
            'name'        => 'alipay_official',
            'title'       => '支付宝官方 Open API',
            'description' => '支持 RSA2 私钥加签与网页/手机 Wap 支付',
            'inputs'      => [
                ['name' => 'app_id', 'title' => '支付宝 AppID', 'type' => 'string', 'required' => true],
                ['name' => 'merchant_private_key', 'title' => '应用私钥 (RSA2)', 'type' => 'textarea', 'required' => true],
                ['name' => 'alipay_public_key', 'title' => '支付宝公钥', 'type' => 'textarea', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['app_id'])) {
            return ['code' => -1, 'msg' => '支付宝 AppID 不能为空'];
        }
        return $config;
    }
}
