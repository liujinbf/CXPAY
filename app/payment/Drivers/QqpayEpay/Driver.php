<?php

declare(strict_types=1);

namespace app\payment\Drivers\QqpayEpay;

use app\payment\Contracts\PaymentDriverInterface;
use support\Sign;

/**
 * QQ 钱包支付驱动插件 (支持 EPay 聚合网关与 QQ 钱包 H5 / Native 链接出码)
 */
class Driver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        $apiUrl = rtrim($config['api_url'] ?? $config['apiurl'] ?? '', '/');
        $pid    = $config['pid'] ?? '';
        $key    = $config['key'] ?? '';
        $mode   = $config['mode'] ?? 'submit';

        $payData = [
            'pid'          => $pid,
            'type'         => 'qqpay',
            'out_trade_no' => $params['trade_no'],
            'notify_url'   => $params['notify_url'] ?? '',
            'return_url'   => $params['return_url'] ?? '',
            'name'         => $params['name'] ?? 'QQ钱包支付',
            'money'        => number_format((float)$params['money'], 2, '.', ''),
        ];

        // 计算 MD5 签名
        $payData['sign']      = Sign::makeSign($payData, $key);
        $payData['sign_type'] = 'MD5';

        // 1. Submit 模式：生成带参跳转 URL
        $submitUrl = $apiUrl . '/submit.php?' . http_build_query($payData);

        if ($mode === 'mapi' && !empty($apiUrl)) {
            // 2. Mapi 模式：尝试调用 mapi 接口出二维码原生链接
            try {
                $opts = [
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($payData),
                        'timeout' => 5.0,
                    ]
                ];
                $res = @file_get_contents($apiUrl . '/mapi.php', false, stream_context_create($opts));
                if ($res) {
                    $json = json_decode($res, true);
                    if (($json['code'] ?? 0) == 1) {
                        return [
                            'type'         => !empty($json['qrcode']) ? 'qrcode' : 'url',
                            'trade_no'     => $params['trade_no'],
                            'out_trade_no' => $params['out_trade_no'],
                            'amount'       => $params['money'],
                            'pay_url'      => $json['qrcode'] ?? $json['payurl'] ?? $submitUrl,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // 异常回退 submit 模式
            }
        }

        return [
            'type'         => 'url',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => $submitUrl,
        ];
    }

    public function notify(array $params, array $config): array
    {
        $key = $config['key'] ?? '';
        $verifySuccess = Sign::verifySign($params, $key);

        return [
            'success'      => $verifySuccess,
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
            'name'        => 'qqpay_epay',
            'title'       => 'QQ 钱包 EPay 聚合网关驱动',
            'description' => '通过彩虹易支付与 QQ 钱包 H5 / Native 协议发起 QQ 钱包收款',
            'inputs'      => [
                ['name' => 'api_url', 'title' => 'QQ 钱包易支付网关地址 (http://...)', 'type' => 'string', 'required' => true],
                ['name' => 'pid', 'title' => '商户 ID (PID)', 'type' => 'string', 'required' => true],
                ['name' => 'key', 'title' => '签名密钥 (KEY)', 'type' => 'string', 'required' => true],
                ['name' => 'mode', 'title' => '支付模式 (submit 页面跳转 / mapi 收银台出码)', 'type' => 'string', 'default' => 'submit'],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['api_url']) || empty($config['pid']) || empty($config['key'])) {
            return ['code' => -1, 'msg' => '请配置 QQ 钱包网关 API 地址、PID 及 KEY'];
        }
        return $config;
    }
}
