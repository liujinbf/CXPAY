<?php

declare(strict_types=1);

namespace app\payment\Drivers\QqpayAppAsst;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * QQ 钱包个人收款码免签挂机助手驱动插件
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
            'pay_url'      => $config['qr_url'] ?? 'https://i.qianbao.qq.com/wallet/sq/i.html',
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
            'name'        => 'qqpay_app_asst',
            'title'       => 'QQ 钱包个人收款码 (挂机助手)',
            'description' => '上传 QQ 钱包个人收款码，搭配安卓挂机 App 监听 QQ 到账通知免签到账',
            'inputs'      => [
                ['name' => 'qr_url', 'title' => 'QQ 钱包个人收款码解析链接', 'type' => 'string', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['qr_url'])) {
            return ['code' => -1, 'msg' => '请填写 QQ 钱包个人收款码解析链接'];
        }
        return $config;
    }
}
