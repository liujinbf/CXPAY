<?php

declare(strict_types=1);

namespace app\payment\Drivers\EpayGeneric;

use app\payment\Contracts\PaymentDriverInterface;
use app\payment\EpayUpstreamException;
use app\payment\EpayUpstreamGuard;
use Closure;
use support\Sign;

/**
 * 外部易支付兼容上游驱动。
 *
 * 将 CXPAY 订单转发给第三方易支付兼容平台。
 * 支持 submit 页面跳转及 mapi 服务端请求。
 */
class Driver implements PaymentDriverInterface
{
    /**
     * @param null|Closure(
     *     string,
     *     array<string,mixed>,
     *     array{scheme:string,host:string,port:int,ip:string}
     * ):string|false $httpPost
     */
    public function __construct(
        private readonly ?EpayUpstreamGuard $guard = null,
        private readonly ?Closure $httpPost = null
    ) {
    }

    public function pay(array $params, array $config): array
    {
        $apiUrl = rtrim(
            (string)($config['api_url'] ?? ''),
            '/'
        );
        $pid = (string)($config['pid'] ?? '');
        $key = (string)($config['key'] ?? '');
        $mode = (string)($config['mode'] ?? 'submit');
        $type = (string)($params['type'] ?? 'alipay');

        // 在构造任何跳转 URL 或发起网络请求前严格校验。
        $target = $this->upstreamGuard()->validate($apiUrl);

        $payData = [
            'pid' => $pid,
            'type' => $type,
            'out_trade_no' => $params['trade_no'],
            'notify_url' => $params['notify_url'] ?? '',
            'return_url' => $params['return_url'] ?? '',
            'name' => $params['name'] ?? '网络充值',
            'money' => number_format(
                (float)$params['money'],
                2,
                '.',
                ''
            ),
        ];

        $payData['sign'] = Sign::makeSign(
            $payData,
            $key
        );
        $payData['sign_type'] = 'MD5';

        $submitUrl = $apiUrl
            . '/submit.php?'
            . http_build_query($payData);

        if ($mode === 'mapi') {
            try {
                $res = $this->httpPost !== null
                    ? ($this->httpPost)(
                        $apiUrl . '/mapi.php',
                        $payData,
                        $target
                    )
                    : $this->postForm(
                        $apiUrl . '/mapi.php',
                        $payData,
                        $target
                    );

                if ($res) {
                    $json = json_decode($res, true);

                    if (($json['code'] ?? 0) == 1) {
                        return [
                            'type' => !empty($json['qrcode'])
                                ? 'qrcode'
                                : 'url',
                            'trade_no' =>
                                $params['trade_no'],
                            'out_trade_no' =>
                                $params['out_trade_no'],
                            'amount' =>
                                $params['money'],
                            'pay_url' =>
                                $json['qrcode']
                                ?? $json['payurl']
                                ?? $submitUrl,
                        ];
                    }
                }
            } catch (EpayUpstreamException $e) {
                // 安全拒绝必须继续向上传递，禁止降级绕过。
                throw $e;
            } catch (\Throwable) {
                // 只有普通网络或上游响应异常允许回退 submit。
            }
        }

        return [
            'type' => 'url',
            'trade_no' => $params['trade_no'],
            'out_trade_no' => $params['out_trade_no'],
            'amount' => $params['money'],
            'pay_url' => $submitUrl,
        ];
    }

    public function notify(array $params, array $config): array
    {
        $key = (string)($config['key'] ?? '');

        $verified = $key !== ''
            && Sign::verifySign($params, $key);

        return [
            'success' => $verified,
            'out_trade_no' =>
                $params['out_trade_no'] ?? '',
            'trade_no' =>
                $params['trade_no'] ?? '',
            'amount' =>
                (float)($params['money'] ?? 0),
        ];
    }

    public function query(string $tradeNo, array $config): array
    {
        // 该驱动保持仅依赖回调确认，不声明主动查询能力。
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name' => 'epay_generic',
            'title' => '外部易支付上游（可选）',
            'description' =>
                '将 CXPAY 订单转发给第三方易支付兼容平台；'
                . '不是 CXPAY 对下游商户提供的易支付接口。',
            'available' => true,
            'inputs' => [
                [
                    'name' => 'api_url',
                    'title' =>
                        '易支付 API 网址（如 https://pay.example.com）',
                    'type' => 'string',
                    'required' => true,
                ],
                [
                    'name' => 'pid',
                    'title' => '易支付 PID（商户号）',
                    'type' => 'string',
                    'required' => true,
                ],
                [
                    'name' => 'key',
                    'title' => '易支付 KEY（MD5 签名密钥）',
                    'type' => 'password',
                    'required' => true,
                ],
                [
                    'name' => 'mode',
                    'title' =>
                        '支付模式（submit 页面跳转 / mapi 收银台出码）',
                    'type' => 'string',
                    'default' => 'submit',
                ],
            ],
        ];
    }

    public function upchannel(
        array $channelRow,
        array $config
    ): array {
        if (
            empty($config['api_url'])
            || empty($config['pid'])
            || empty($config['key'])
        ) {
            return [
                'code' => -1,
                'msg' =>
                    '易支付 API 地址、PID 与 KEY 不能为空',
            ];
        }

        if (
            !filter_var(
                $config['api_url'],
                FILTER_VALIDATE_URL
            )
            || !in_array(
                strtolower(
                    (string)parse_url(
                        $config['api_url'],
                        PHP_URL_SCHEME
                    )
                ),
                ['http', 'https'],
                true
            )
        ) {
            return [
                'code' => -1,
                'msg' =>
                    '易支付 API 网址必须是有效的 HTTP(S) 地址',
            ];
        }

        if (
            isset($config['mode'])
            && !in_array(
                (string)$config['mode'],
                ['submit', 'mapi'],
                true
            )
        ) {
            return [
                'code' => -1,
                'msg' =>
                    '支付模式只允许 submit 或 mapi',
            ];
        }

        try {
            $this->upstreamGuard()->validate(
                (string)$config['api_url']
            );
        } catch (EpayUpstreamException) {
            return [
                'code' => -1,
                'msg' =>
                    EpayUpstreamGuard::REJECTED_MESSAGE,
            ];
        }

        return $config;
    }

    private function upstreamGuard(): EpayUpstreamGuard
    {
        return $this->guard
            ?? new EpayUpstreamGuard();
    }

    /**
     * @param array<string,mixed> $data
     * @param array{
     *     scheme:string,
     *     host:string,
     *     port:int,
     *     ip:string
     * } $target
     */
    private function postForm(
        string $url,
        array $data,
        array $target
    ): string|false {
        if (!function_exists('curl_init')) {
            return false;
        }

        $resolvedIp = str_contains(
            $target['ip'],
            ':'
        )
            ? '[' . $target['ip'] . ']'
            : $target['ip'];

        $ch = curl_init($url);

        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS =>
                http_build_query($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [
                "{$target['host']}:"
                . "{$target['port']}:"
                . $resolvedIp,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );
        $error = curl_errno($ch);

        curl_close($ch);

        return $error === 0
            && $httpCode >= 200
            && $httpCode < 300
            && is_string($response)
                ? $response
                : false;
    }
}
