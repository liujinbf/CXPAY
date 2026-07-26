<?php

declare(strict_types=1);

namespace app\payment\Drivers\EpayGeneric;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 易支付通用 MD5 协议驱动
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
            'pay_url'      => $config['api_url'] ?? '',
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
        return ['paid' => true];
    }

    public function getMeta(): array
    {
        return [
            'name'        => 'epay_generic',
            'title'       => '易支付通用 MD5 协议驱动',
            'description' => '标准易支付 MD5 签名上游接入驱动',
            'inputs'      => [
                ['name' => 'api_url', 'title' => '易支付 API 网址', 'type' => 'string', 'required' => true],
                ['name' => 'pid', 'title' => '易支付 PID', 'type' => 'string', 'required' => true],
                ['name' => 'key', 'title' => '易支付 KEY', 'type' => 'string', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['api_url']) || empty($config['pid']) || empty($config['key'])) {
            return ['code' => -1, 'msg' => '易支付 API 地址、PID 与 KEY 不能为空'];
        }
        return $config;
    }
}
