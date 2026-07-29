<?php

namespace app\common\library\payment\plugins\alipay_official\lib;

use think\facade\Request;

/**
 * 支付宝官方支付 API 实现类
 *
 * 逐字节移植自旧 protected/payplug/alipay_official/class/AliPay_Official_API.Class.php。
 * RSA2 签名 / 网络请求 / PEM 格式化逻辑保持不变，仅：
 *   - 命名空间化（类名 AliPay_Official_API → AliPayOfficialApi）；
 *   - getNotifyUrl/getReturnUrl 改用 xlpay 公开回调端点（Request::domain() + /notify/alipay/*）。
 */
class AliPayOfficialApi
{
    private $config;
    private $api_url = 'https://openapi.alipay.com/gateway.do';

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * 创建 Native 订单（二维码）
     */
    public function createNativeOrder($trade_no, $amount, $description)
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.trade.precreate',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->getNotifyUrl(),
            'biz_content' => json_encode([
                'out_trade_no' => $trade_no,
                'total_amount' => $amount,
                'subject' => $description,
                'scene' => 'QR_CODE'
            ])
        ];

        $response = $this->httpRequest($params);

        if ($response['code'] == 1 && isset($response['data']['qr_code'])) {
            return [
                'code' => 1,
                'msg' => '成功',
                'qr_code' => $response['data']['qr_code']
            ];
        }

        return $response;
    }

    /**
     * 创建电脑网站支付
     */
    public function createPageOrder($trade_no, $amount, $description)
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.trade.page.pay',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->getNotifyUrl(),
            'return_url' => $this->getReturnUrl(),
            'biz_content' => json_encode([
                'out_trade_no' => $trade_no,
                'total_amount' => $amount,
                'subject' => $description,
                'product_code' => 'FAST_INSTANT_TRADE_PAY'
            ])
        ];

        // 构建签名
        $params['sign'] = $this->buildSignature($params);

        // 构建支付链接
        $pay_url = $this->api_url . '?' . http_build_query($params);

        return [
            'code' => 1,
            'msg' => '成功',
            'pay_url' => $pay_url
        ];
    }

    /**
     * 创建手机网站支付
     */
    public function createH5Order($trade_no, $amount, $description)
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.trade.wap.pay',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->getNotifyUrl(),
            'return_url' => $this->getReturnUrl(),
            'biz_content' => json_encode([
                'out_trade_no' => $trade_no,
                'total_amount' => $amount,
                'subject' => $description,
                'product_code' => 'QUICK_WAP_PAY'
            ])
        ];

        // 构建签名
        $params['sign'] = $this->buildSignature($params);

        // 构建支付链接
        $h5_url = $this->api_url . '?' . http_build_query($params);

        return [
            'code' => 1,
            'msg' => '成功',
            'h5_url' => $h5_url
        ];
    }

    /**
     * 查询订单状态
     */
    public function queryOrder($trade_no)
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.trade.query',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_trade_no' => $trade_no
            ])
        ];

        $response = $this->httpRequest($params);

        if ($response['code'] == 1) {
            $data = $response['data'];
            return [
                'code' => 1,
                'msg' => '成功',
                'trade_status' => $data['trade_status'] ?? '',
                'trade_no' => $data['trade_no'] ?? '',
                'amount' => isset($data['total_amount']) ? floatval($data['total_amount']) : 0
            ];
        }

        return $response;
    }

    /**
     * 关闭订单
     */
    public function closeOrder($trade_no)
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.trade.close',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'out_trade_no' => $trade_no
            ])
        ];

        $response = $this->httpRequest($params);

        return $response;
    }

    /**
     * 发送 HTTP 请求
     */
    private function httpRequest($params)
    {
        // 构建签名
        $params['sign'] = $this->buildSignature($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => -1, 'msg' => '请求失败: ' . $error];
        }

        // 解析 JSON 响应
        $data = json_decode($response, true);

        if ($http_code == 200 && is_array($data)) {
            $method = $params['method'];
            $response_key = str_replace('.', '_', $method) . '_response';

            if (isset($data[$response_key])) {
                $result = $data[$response_key];
                if ($result['code'] == '10000') {
                    return ['code' => 1, 'msg' => '成功', 'data' => $result];
                } else {
                    $error_msg = $result['sub_msg'] ?? $result['msg'] ?? '未知错误';
                    return ['code' => -1, 'msg' => $error_msg, 'data' => $result];
                }
            }

            return ['code' => -1, 'msg' => '响应格式错误'];
        } else {
            return ['code' => -1, 'msg' => '请求失败，HTTP ' . $http_code];
        }
    }

    /**
     * 构建请求签名
     */
    public function buildSignature($params)
    {
        // 移除签名字段
        unset($params['sign']);

        // 按字母顺序排序
        ksort($params);

        // 构建签名字符串
        $sign_string = '';
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $sign_string .= $key . '=' . $value . '&';
            }
        }
        $sign_string = rtrim($sign_string, '&');

        // 格式化私钥为 PEM 格式
        $private_key = $this->formatPrivateKey($this->config['private_key']);

        // 验证私钥格式
        if (empty($private_key)) {
            return '';
        }

        // 使用应用私钥签名
        $signature = '';
        $result = @openssl_sign($sign_string, $signature, $private_key, OPENSSL_ALGO_SHA256);

        if (!$result) {
            return '';
        }

        // Base64 编码
        return base64_encode($signature);
    }

    /**
     * 格式化私钥为 PEM 格式
     */
    private function formatPrivateKey($key)
    {
        if (empty($key)) {
            return '';
        }

        // 如果已经包含 BEGIN 标记，说明已经是完整格式
        if (strpos($key, 'BEGIN') !== false) {
            // 处理转义的换行符
            $key = str_replace("\\r\\n", "\n", $key);
            $key = str_replace("\\n", "\n", $key);
            return $key;
        }

        // 移除所有空白字符，确保是纯 Base64 字符串
        $key = preg_replace('/\s+/', '', $key);

        // 验证是否为有效的 Base64 字符串
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $key)) {
            return '';
        }

        // 纯密钥内容，需要格式化为 PEM 格式
        return "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    }

    /**
     * 格式化公钥为 PEM 格式
     */
    public function formatPublicKey($key)
    {
        if (empty($key)) {
            return '';
        }

        // 如果已经包含 BEGIN 标记，说明已经是完整格式
        if (strpos($key, 'BEGIN') !== false) {
            // 处理转义的换行符
            $key = str_replace("\\r\\n", "\n", $key);
            $key = str_replace("\\n", "\n", $key);
            $key = str_replace("\\r", "\n", $key);
            return $key;
        }

        // 移除所有空白字符，确保是纯 Base64 字符串
        $key = preg_replace('/\s+/', '', $key);

        // 验证是否为有效的 Base64 字符串
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $key)) {
            return '';
        }

        // 纯密钥内容，需要格式化为 PEM 格式
        return "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
    }

    /**
     * 获取回调通知 URL（xlpay 官方异步回调端点）
     */
    private function getNotifyUrl()
    {
        return Request::domain() . '/notify/alipay/notify';
    }

    /**
     * 获取返回 URL（xlpay 官方同步回调端点）
     */
    private function getReturnUrl()
    {
        return Request::domain() . '/notify/alipay/returnUrl';
    }
}
