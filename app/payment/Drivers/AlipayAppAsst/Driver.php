<?php

declare(strict_types=1);

namespace app\payment\Drivers\AlipayAppAsst;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 支付宝 App 挂机助手驱动插件
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
            'name'        => 'alipay_app_asst',
            'title'       => '支付宝 App 挂机助手驱动',
            'description' => '通过安卓挂机 App 监听通知推送实现自动免签到账',
        ];
    }
}
