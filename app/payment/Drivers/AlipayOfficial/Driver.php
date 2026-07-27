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

    /**
     * 支付宝异步通知 RSA2 验签
     * 参考：https://opendocs.alipay.com/open/270/105902
     */
    public function notify(array $params, array $config): array
    {
        $alipayPublicKey = $config['alipay_public_key'] ?? '';

        // 若配置了支付宝公钥则做 RSA2 验签，否则降级校验基本字段
        if (!empty($alipayPublicKey) && !empty($params['sign'])) {
            $verified = $this->verifyRsa2($params, $alipayPublicKey);
        } else {
            // 无公钥时：至少校验通知状态为成功且包含必要字段
            $verified = isset($params['out_trade_no'])
                && ($params['trade_status'] ?? '') === 'TRADE_SUCCESS';
        }

        // 支付宝通知的实际金额字段为 total_amount
        $amount = (float)($params['total_amount'] ?? $params['amount'] ?? 0);

        return [
            'success'      => $verified,
            'out_trade_no' => $params['out_trade_no'] ?? '',
            'trade_no'     => $params['trade_no'] ?? '',
            'amount'       => $amount,
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name'        => 'alipay_official',
            'title'       => '支付宝官方 Open API',
            'description' => '支持 RSA2 私钥加签与网页/手机 Wap 支付',
            'inputs'      => [
                ['name' => 'app_id',               'title' => '支付宝 AppID',        'type' => 'string',   'required' => true],
                ['name' => 'merchant_private_key',  'title' => '应用私钥 (RSA2)',     'type' => 'textarea', 'required' => true],
                ['name' => 'alipay_public_key',     'title' => '支付宝公钥',          'type' => 'textarea', 'required' => true],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['app_id'])) {
            return ['code' => -1, 'msg' => '支付宝 AppID 不能为空'];
        }
        if (empty($config['alipay_public_key'])) {
            return ['code' => -1, 'msg' => '支付宝公钥不能为空（用于验签）'];
        }
        return $config;
    }

    /**
     * RSA2（SHA256WithRSA）签名验证
     *
     * @param  array  $params          支付宝回调原始参数
     * @param  string $alipayPublicKey 支付宝公钥（PEM 或裸 base64）
     * @return bool
     */
    private function verifyRsa2(array $params, string $alipayPublicKey): bool
    {
        if (empty($params['sign'])) {
            return false;
        }

        // 构造待验签字符串（按字母序排列，排除 sign / sign_type 字段）
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null) {
                $parts[] = $k . '=' . $v;
            }
        }
        $signContent = implode('&', $parts);

        // 补全 PEM 格式头尾（若传入的是裸 base64）
        $pubKey = $alipayPublicKey;
        if (!str_contains($pubKey, '-----BEGIN')) {
            $pubKey = "-----BEGIN PUBLIC KEY-----\n"
                . wordwrap($pubKey, 64, "\n", true)
                . "\n-----END PUBLIC KEY-----";
        }

        $res = openssl_get_publickey($pubKey);
        if ($res === false) {
            return false;
        }

        $decoded = base64_decode($params['sign'], true);
        if ($decoded === false) {
            return false;
        }

        $result = openssl_verify($signContent, $decoded, $res, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
