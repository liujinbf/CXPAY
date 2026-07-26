<?php

declare(strict_types=1);

namespace app\payment\Drivers\QqpayProtocolCloud;

use app\payment\Contracts\PaymentDriverInterface;
use app\service\QqProtocolCloudService;

/**
 * QQ 钱包云端协议 (扫码登录 Cookie / Token 免挂) 驱动插件
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
            'name'        => 'qqpay_protocol_cloud',
            'title'       => 'QQ 钱包云端协议免挂 (扫码获取 Cookie/skey)',
            'description' => '后台扫码登录 QQ 钱包网页版自动换取 skey 令牌，后端协程轮询账单核销',
            'inputs'      => [
                ['name' => 'uin', 'title' => 'QQ 账号 (UIN)', 'type' => 'string', 'required' => true],
                ['name' => 'skey', 'title' => 'QQ 钱包 Session skey 令牌', 'type' => 'string', 'required' => true],
                ['name' => 'qr_url', 'title' => '个人 QQ 钱包收款码解析链接', 'type' => 'textarea', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['uin'])) {
            return ['code' => -1, 'msg' => 'QQ 账号 UIN 不能为空'];
        }
        return $config;
    }
}
