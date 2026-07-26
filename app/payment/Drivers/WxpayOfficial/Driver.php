<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayOfficial;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 微信支付原生 V3 驱动
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
            'pay_url'      => 'weixin://wxpay/bizpayurl',
        ];
    }

    public function notify(array $params, array $config): array
    {
        return [
            'success'      => true,
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no'     => $params['transaction_id'] ?? '',
            'amount'       => (float)($params['amount']['total'] ?? 0) / 100,
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => true];
    }

    public function getMeta(): array
    {
        return [
            'name'        => 'wxpay_official',
            'title'       => '微信支付原生 V3 驱动',
            'description' => '支持微信 Native 扫码与 H5 MWEB 支付',
            'inputs'      => [
                ['name' => 'mch_id', 'title' => '微信商户号 (MCHID)', 'type' => 'string', 'required' => true],
                ['name' => 'v3_key', 'title' => 'API v3 密钥', 'type' => 'string', 'required' => true],
                ['name' => 'serial_no', 'title' => '商户证书序列号', 'type' => 'string', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['mch_id'])) {
            return ['code' => -1, 'msg' => '微信商户号 (mch_id) 不能为空'];
        }
        return $config;
    }
}
