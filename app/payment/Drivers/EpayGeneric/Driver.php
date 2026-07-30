<?php

declare(strict_types=1);

namespace app\payment\Drivers\EpayGeneric;

use app\payment\Contracts\PaymentDriverInterface;
use support\Sign;
use support\UrlGuard;

/**
 * 易支付通用 MD5 协议驱动
 *
 * 支持任意标准易支付上游接入（支付宝/微信/QQ 三种 type）。
 * 下单模式：
 *   - submit（默认）：拼接完整带签名参数跳转到 /submit.php
 *   - mapi：POST 到 /mapi.php，返回 JSON 收银台链接，失败回退 submit
 */
class Driver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        $pid    = $config['pid'] ?? '';
        $key    = $config['key'] ?? '';
        $mode   = $config['mode'] ?? 'submit';
        $type   = $params['type'] ?? 'alipay'; // alipay / wxpay / qqpay

        $payData = [
            'pid'          => $pid,
            'type'         => $type,
            'out_trade_no' => $params['trade_no'],
            'notify_url'   => $params['notify_url'] ?? '',
            'return_url'   => $params['return_url'] ?? '',
            'name'         => $params['name'] ?? '网络充值',
            'money'        => number_format((float)$params['money'], 2, '.', ''),
        ];

        // 计算 MD5 签名
        $payData['sign']      = Sign::makeSign($payData, $key);
        $payData['sign_type'] = 'MD5';

        // 生成 submit.php 跳转 URL（Submit 模式与 Mapi 失败时的备用）
        $submitUrl = $apiUrl . '/submit.php?' . http_build_query($payData);

        if ($mode === 'mapi' && !empty($apiUrl)) {
            // Mapi 模式：POST 到 /mapi.php，尝试获取收银台直链
            try {
                $res = $this->postForm($apiUrl . '/mapi.php', $payData);
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
            } catch (\Throwable) {
                // Mapi 异常回退 Submit 模式
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

    /**
     * 易支付标准 MD5 验签
     * 上游回调须携带 sign 字段，使用与下单相同的 key 校验
     */
    public function notify(array $params, array $config): array
    {
        $key = $config['key'] ?? '';

        // 使用统一 Sign 工具类做 MD5 验签
        $verified = !empty($key) && Sign::verifySign($params, $key);

        return [
            'success'      => $verified,
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
            'name'        => 'epay_generic',
            'title'       => '易支付通用 MD5 协议驱动',
            'description' => '标准易支付 MD5 签名上游接入驱动，支持支付宝/微信/QQ 三种收款类型',
            'available'   => true,
            'inputs'      => [
                ['name' => 'api_url', 'title' => '易支付 API 网址（如 https://pay.example.com）', 'type' => 'string', 'required' => true],
                ['name' => 'pid',     'title' => '易支付 PID（商户号）',                          'type' => 'string', 'required' => true],
                ['name' => 'key',     'title' => '易支付 KEY（MD5 签名密钥）',                    'type' => 'password', 'required' => true],
                ['name' => 'mode',    'title' => '支付模式（submit 页面跳转 / mapi 收银台出码）', 'type' => 'string', 'default' => 'submit'],
            ]
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['api_url']) || empty($config['pid']) || empty($config['key'])) {
            return ['code' => -1, 'msg' => '易支付 API 地址、PID 与 KEY 不能为空'];
        }
        if (!filter_var($config['api_url'], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($config['api_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return ['code' => -1, 'msg' => '易支付 API 网址必须是有效的 HTTP(S) 地址'];
        }
        if (isset($config['mode']) && !in_array((string)$config['mode'], ['submit', 'mapi'], true)) {
            return ['code' => -1, 'msg' => '支付模式只允许 submit 或 mapi'];
        }
        return $config;
    }

    /**
     * 向易支付 mapi 接口 POST 表单，内置 SSRF 防护
     */
    private function postForm(string $url, array $data): string|false
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $target = UrlGuard::resolve($url);
        if ($target === null) {
            return false;
        }

        $resolvedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE        => ["{$target['host']}:{$target['port']}:{$resolvedIp}"],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch);
        curl_close($ch);

        return $error === 0 && $httpCode >= 200 && $httpCode < 300 && is_string($response)
            ? $response
            : false;
    }
}
