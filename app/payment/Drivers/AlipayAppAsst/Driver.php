<?php

declare(strict_types=1);

namespace app\payment\Drivers\AlipayAppAsst;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 支付宝 App 挂机助手驱动插件
 * 挂机 App 上报账单到 /api/callbill 接口，notify() 在此处验证 App 推送合法性
 */
class Driver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        // 返回转账/固定码收款模式
        return [
            'type'         => 'qrcode',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => $config['qr_code_url'] ?? 'alipays://platformapi/startapp',
        ];
    }

    /**
     * 挂机助手回调验签
     * 挂机 App 推送账单时须携带 device_id + sign（HMAC-SHA256(device_id + money + secret)）
     * 若未配置 secret 则至少校验 device_id 字段存在
     */
    public function notify(array $params, array $config): array
    {
        $secret   = $config['notify_secret'] ?? '';
        $deviceId = $params['device_id'] ?? '';
        $money    = $params['money'] ?? '';
        $sign     = $params['sign'] ?? '';

        if (!empty($secret) && !empty($sign)) {
            // 验证 HMAC-SHA256 签名：sign = hmac(device_id + '|' + money, secret)
            $expected = hash_hmac('sha256', $deviceId . '|' . $money, $secret);
            $verified = hash_equals($expected, strtolower($sign));
        } else {
            // 无 secret 配置时降级：仅校验 device_id 不为空
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
            'name'        => 'alipay_app_asst',
            'title'       => '支付宝 App 挂机助手驱动',
            'description' => '通过安卓挂机 App 监听通知推送实现自动免签到账',
            'inputs'      => [
                ['name' => 'qr_code_url',    'title' => '支付宝个人收款码链接',          'type' => 'string', 'required' => true],
                ['name' => 'notify_secret',  'title' => '挂机 App 推送签名 Secret（可选）', 'type' => 'string', 'required' => false],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['qr_code_url'])) {
            return ['code' => -1, 'msg' => '支付宝个人收款码链接不能为空'];
        }
        return $config;
    }
}
