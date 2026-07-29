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
        $gateway = (string)($config['gateway_url'] ?? 'https://openapi.alipay.com/gateway.do');
        $requestParams = [
            'app_id' => (string)($config['app_id'] ?? ''),
            'method' => 'alipay.trade.page.pay',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => (string)($params['notify_url'] ?? ''),
            'return_url' => (string)($params['return_url'] ?? ''),
            'biz_content' => json_encode([
                'out_trade_no' => (string)$params['out_trade_no'],
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
                'total_amount' => number_format((float)$params['money'], 2, '.', ''),
                'subject' => mb_substr((string)($params['name'] ?? '网络支付'), 0, 128),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($requestParams['return_url'] === '') {
            unset($requestParams['return_url']);
        }

        $requestParams['sign'] = $this->signRsa2(
            $this->buildSignContent($requestParams),
            (string)($config['merchant_private_key'] ?? '')
        );

        return [
            'type'         => 'url',
            'trade_no'     => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount'       => $params['money'],
            'pay_url'      => $gateway . '?' . http_build_query($requestParams, '', '&', PHP_QUERY_RFC3986),
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
            $verified = $this->verifyRsa2($params, $alipayPublicKey)
                && in_array((string)($params['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)
                && hash_equals((string)($config['app_id'] ?? ''), (string)($params['app_id'] ?? ''));
        } else {
            $verified = false;
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
            'available'   => false,
            'inputs'      => [
                ['name' => 'app_id',               'title' => '支付宝 AppID',        'type' => 'string',   'required' => true],
                ['name' => 'merchant_private_key',  'title' => '应用私钥 (RSA2)',     'type' => 'textarea', 'required' => true],
                ['name' => 'alipay_public_key',     'title' => '支付宝公钥',          'type' => 'textarea', 'required' => true],
                ['name' => 'gateway_url',           'title' => '支付宝网关地址',       'type' => 'string', 'required' => true, 'default' => 'https://openapi.alipay.com/gateway.do'],
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
        if (empty($config['merchant_private_key'])) {
            return ['code' => -1, 'msg' => '支付宝应用私钥不能为空（用于下单签名）'];
        }
        $allowedGateways = [
            'https://openapi.alipay.com/gateway.do',
            'https://openapi-sandbox.dl.alipaydev.com/gateway.do',
        ];
        $gateway = (string)($config['gateway_url'] ?? $allowedGateways[0]);
        if (!in_array($gateway, $allowedGateways, true)) {
            return ['code' => -1, 'msg' => '支付宝网关地址不在允许列表中'];
        }
        return $config;
    }

    private function signRsa2(string $content, string $privateKey): string
    {
        $key = $this->normalizePrivateKey($privateKey);
        $resource = openssl_pkey_get_private($key);
        if ($resource === false) {
            throw new \RuntimeException('支付宝应用私钥格式不正确');
        }
        $signature = '';
        if (!openssl_sign($content, $signature, $resource, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('支付宝 RSA2 下单签名失败');
        }
        return base64_encode($signature);
    }

    private function buildSignContent(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null && $key !== 'sign') {
                $pairs[] = $key . '=' . $value;
            }
        }
        return implode('&', $pairs);
    }

    private function normalizePrivateKey(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        return "-----BEGIN PRIVATE KEY-----\n"
            . wordwrap(preg_replace('/\s+/', '', $key), 64, "\n", true)
            . "\n-----END PRIVATE KEY-----";
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
