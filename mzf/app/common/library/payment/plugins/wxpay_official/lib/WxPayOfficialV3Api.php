<?php

namespace app\common\library\payment\plugins\wxpay_official\lib;

use think\facade\Request;

/**
 * 微信支付 V3 API 实现类
 *
 * 逐字节移植自旧 protected/payplug/wxpay_official/class/WxPay_Official_V3_API.Class.php。
 * WECHATPAY2-SHA256-RSA2048 签名 / 网络请求逻辑保持不变，仅：
 *   - 命名空间化（类名 WxPay_Official_V3_API → WxPayOfficialV3Api）；
 *   - getNotifyUrl 改用 xlpay 公开回调端点（Request::domain() + /notify/wxpay/notify）。
 */
class WxPayOfficialV3Api
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * 创建 Native 订单（二维码）
     */
    public function createNativeOrder($trade_no, $amount, $description)
    {
        $url = 'https://api.mch.weixin.qq.com/v3/pay/transactions/native';

        $body = [
            'appid' => $this->config['appid'],
            'mchid' => $this->config['mchid'],
            'description' => $description,
            'out_trade_no' => $trade_no,
            'notify_url' => $this->getNotifyUrl(),
            'amount' => [
                'total' => intval($amount * 100),
                'currency' => 'CNY'
            ]
        ];

        $response = $this->httpRequest('POST', $url, json_encode($body));

        if ($response['code'] == 1) {
            return [
                'code' => 1,
                'msg' => '成功',
                'qr_code' => $response['data']['code_url']
            ];
        }

        return $response;
    }

    /**
     * 创建 H5 订单
     */
    public function createH5Order($trade_no, $amount, $description)
    {
        $url = 'https://api.mch.weixin.qq.com/v3/pay/transactions/h5';

        $body = [
            'appid' => $this->config['appid'],
            'mchid' => $this->config['mchid'],
            'description' => $description,
            'out_trade_no' => $trade_no,
            'notify_url' => $this->getNotifyUrl(),
            'amount' => [
                'total' => intval($amount * 100),
                'currency' => 'CNY'
            ],
            'scene_info' => [
                'payer_client_ip' => $this->getClientIp(),
                'h5_info' => [
                    'type' => 'WECHAT_PUBLIC'
                ]
            ]
        ];

        $response = $this->httpRequest('POST', $url, json_encode($body));

        if ($response['code'] == 1) {
            return [
                'code' => 1,
                'msg' => '成功',
                'h5_url' => $response['data']['h5_url']
            ];
        }

        return $response;
    }

    /**
     * 查询订单状态
     */
    public function queryOrder($trade_no)
    {
        $url = 'https://api.mch.weixin.qq.com/v3/pay/transactions/out-trade-no/' . $trade_no . '?mchid=' . $this->config['mchid'];

        $response = $this->httpRequest('GET', $url, '');

        if ($response['code'] == 1) {
            $data = $response['data'];
            return [
                'code' => 1,
                'msg' => '成功',
                'trade_state' => $data['trade_state'],
                'transaction_id' => $data['transaction_id'] ?? '',
                'amount' => isset($data['amount']['payer_total']) ? $data['amount']['payer_total'] / 100 : 0
            ];
        }

        return $response;
    }

    /**
     * 关闭订单
     */
    public function closeOrder($trade_no)
    {
        $url = 'https://api.mch.weixin.qq.com/v3/pay/transactions/out-trade-no/' . $trade_no . '/close';

        $body = [
            'mchid' => $this->config['mchid']
        ];

        $response = $this->httpRequest('POST', $url, json_encode($body));

        return $response;
    }

    /**
     * 发送 HTTP 请求
     */
    private function httpRequest($method, $url, $body)
    {
        $timestamp = time();
        $nonce = $this->generateNonce();
        $signature = $this->buildSignature($method, $url, $body, $timestamp, $nonce);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: WECHATPAY2-SHA256-RSA2048 ' . $signature,
            'Wechatpay-Timestamp: ' . $timestamp,
            'Wechatpay-Nonce: ' . $nonce,
            'Wechatpay-Serial: ' . $this->config['serial_no']
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => -1, 'msg' => '请求失败: ' . $error];
        }

        $data = json_decode($response, true);

        if ($http_code == 200 || $http_code == 201) {
            return ['code' => 1, 'msg' => '成功', 'data' => $data];
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : '未知错误';
            return ['code' => -1, 'msg' => $error_msg, 'data' => $data];
        }
    }

    /**
     * 构建请求签名
     */
    private function buildSignature($method, $url, $body, $timestamp, $nonce)
    {
        // 提取 URL 路径和查询字符串
        $url_parts = parse_url($url);
        $path = $url_parts['path'];
        if (isset($url_parts['query'])) {
            $path .= '?' . $url_parts['query'];
        }

        // 构建签名字符串
        $sign_string = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";

        // 使用商户私钥签名
        $private_key = $this->config['private_key'];
        $signature = '';
        openssl_sign($sign_string, $signature, $private_key, OPENSSL_ALGO_SHA256);

        // 构建授权头
        $signature_base64 = base64_encode($signature);

        return $this->config['serial_no'] . ',' . $signature_base64;
    }

    /**
     * 生成随机字符串
     */
    private function generateNonce()
    {
        return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 32);
    }

    /**
     * 获取客户端 IP
     */
    private function getClientIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
        return trim($ip);
    }

    /**
     * 获取回调通知 URL（xlpay 微信官方异步回调端点）
     */
    private function getNotifyUrl()
    {
        return Request::domain() . '/notify/wxpay/notify';
    }
}
