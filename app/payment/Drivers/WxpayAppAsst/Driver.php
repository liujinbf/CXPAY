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
            'pay_url'      => $config['qr_code_url'] ?? ("/cashier/index.html?trade_no=" . $params['trade_no']),
        ];
    }

    /**
     * 微信挂机助手回调验签
     * 挂机 App 推送时须携带 device_id + sign（HMAC-SHA256(device_id + '|' + money, secret)）
     */
    public function notify(array $params, array $config): array
    {
        $secret   = $config['notify_secret'] ?? '';
        $deviceId = $params['device_id'] ?? '';
        $money    = $params['money'] ?? '';
        $sign     = $params['sign'] ?? '';

        if (!empty($secret) && !empty($sign)) {
            $expected = hash_hmac('sha256', $deviceId . '|' . $money, $secret);
            $verified = hash_equals($expected, strtolower($sign));
        } else {
            $verified = !empty($deviceId);
        }

        return [
            'success'      => $verified,
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no'     => $params['trade_no'] ?? '',
            'amount'       => (float)($money),
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
            'inputs'      => [
                ['name' => 'qr_code_url',   'title' => '微信个人收款码链接',              'type' => 'string', 'required' => true],
                ['name' => 'notify_secret', 'title' => '挂机 App 推送签名 Secret（可选）', 'type' => 'string', 'required' => false],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['qr_code_url'])) {
            return ['code' => -1, 'msg' => '微信个人收款码链接不能为空'];
        }
        return $config;
    }
}
