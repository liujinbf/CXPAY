<?php

namespace app\common\library\payment\plugins\wxpay_official;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\plugins\wxpay_official\lib\WxPayOfficialV3Api;
use think\facade\Request;

/**
 * 微信官方支付驱动（wxpay_official）
 *
 * 移植自旧 protected/payplug/wxpay_official/config.php（v2.0.0）。
 *   - V2 版本：仅 Native 支付（XML + MD5 签名），下单/验签逻辑内置于本类；
 *   - V3 版本：Native + H5 支付（JSON + RSA2 签名），走 lib/WxPayOfficialV3Api。
 * config()/upchannel()/verifyCallbackSign()（V2 MD5）为纯逻辑，已完整移植可用。
 * getPayQr()/tradeClose() 依赖真实商户凭据出码，逐字节忠实移植，未联网验证。
 *
 * 回调：旧 handleReturn/handleNotify 是页面级流程，移交给新的 notify 控制器统一处理
 * （见报告：需新建 app/notify/controller/Wxpay），此处仅保留验签方法供其调用。
 *
 * @see /www/wwwroot/peakpay/protected/payplug/wxpay_official/config.php 原始实现
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'wxpay_official';

    public function config(): array
    {
        return [
            'version'       => '2.0.0',
            'name'          => '微信官方支付',
            'author'        => '小乐',
            'link'          => 'https://xiaole.ink',
            'type'          => 'wxpay',
            'c_type'        => 'wxpay_official',
            'switch_qr_url' => false,
            'getqrlogin'    => false,
            'inputs'        => [
                'api_version' => [
                    'name'    => 'API 版本',
                    'type'    => 'select',
                    'note'    => '选择微信支付 API 版本',
                    'options' => [
                        'v2' => 'V2 版本（仅支持 Native 支付）',
                        'v3' => 'V3 版本（支持 Native 和 H5 支付）',
                    ],
                    'default' => 'v2',
                ],
                'mchid' => [
                    'name' => '商户号',
                    'type' => 'input',
                    'note' => '微信支付商户号',
                ],
                'appid' => [
                    'name' => '应用 APPID',
                    'type' => 'input',
                    'note' => '微信应用 APPID',
                ],
                'api_key' => [
                    'name' => 'API 密钥 (Key)',
                    'type' => 'textarea',
                    'note' => '微信 API 密钥（32位）- V2 和 V3 都需要',
                ],
                'app_secret' => [
                    'name' => 'AppSecret',
                    'type' => 'textarea',
                    'note' => '微信应用密钥 - 仅 V2 版本需要',
                ],
                'serial_no' => [
                    'name' => '证书序列号',
                    'type' => 'input',
                    'note' => '证书序列号 - 仅 V3 版本需要',
                ],
                'private_key' => [
                    'name' => '商户私钥',
                    'type' => 'textarea',
                    'note' => '商户私钥内容（-----BEGIN PRIVATE KEY-----...-----END PRIVATE KEY-----）- 仅 V3 版本需要',
                ],
                'enable_native' => [
                    'name'    => '启用 Native 支付',
                    'type'    => 'select',
                    'note'    => '是否启用二维码支付（电脑端和手机端都支持）',
                    'options' => ['1' => '启用', '0' => '禁用'],
                    'default' => '1',
                ],
                'enable_h5' => [
                    'name'    => '启用 H5 支付',
                    'type'    => 'select',
                    'note'    => '是否启用移动网页支付（仅手机端支持）',
                    'options' => ['1' => '启用', '0' => '禁用'],
                    'default' => '1',
                ],
            ],
            'note' => '<span style="color:red">使用说明：</span><br>'
                . '① <b>V2 版本</b>：只需填写商户号、APPID、Key、AppSecret，配置简单，适合 Native 支付。<br>'
                . '② <b>V3 版本</b>：需要额外填写证书序列号和商户私钥，支持 Native 和 H5 支付。<br>'
                . '③ 请根据实际情况选择 API 版本和支付方式。<br>'
                . '④ 手机端访问优先调用 H5 支付（如已启用），否则调用 Native 支付。<br>'
                . '⑤ 电脑端只能调用 Native 支付，H5 支付在电脑端无法使用。',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        // 验证必要字段
        if (empty($config['mchid'])) {
            return $this->fail('请填写商户号');
        }
        if (empty($config['appid'])) {
            return $this->fail('请填写应用 APPID');
        }
        if (empty($config['api_key'])) {
            return $this->fail('请填写 API 密钥');
        }

        // 设置默认版本
        if (!isset($config['api_version'])) {
            $config['api_version'] = 'v2';
        }

        // 根据版本验证不同的字段
        if ($config['api_version'] == 'v2') {
            // V2 版本需要 AppSecret
            if (empty($config['app_secret'])) {
                return $this->fail('V2 版本需要填写 AppSecret');
            }
        } elseif ($config['api_version'] == 'v3') {
            // V3 版本需要证书序列号和商户私钥
            if (empty($config['serial_no'])) {
                return $this->fail('V3 版本需要填写证书序列号');
            }
            if (empty($config['private_key'])) {
                return $this->fail('V3 版本需要填写商户私钥');
            }
            // 验证私钥格式
            if (strpos($config['private_key'], 'BEGIN PRIVATE KEY') === false) {
                return $this->fail('商户私钥格式不正确');
            }
        }

        // 默认支付方式开关
        $config['enable_native'] = $config['enable_native'] ?? '0';
        $config['enable_h5']     = $config['enable_h5'] ?? '0';

        // 验证支付方式配置
        if ($config['enable_native'] != '1' && $config['enable_h5'] != '1') {
            return $this->fail('至少需要启用一种支付方式');
        }

        // V2 版本不支持 H5 支付
        if ($config['api_version'] == 'v2' && $config['enable_h5'] == '1') {
            return $this->fail('V2 版本仅支持 Native 支付，不支持 H5 支付');
        }

        // 初始化状态
        $config['status']     = $config['status'] ?? 1;
        $config['status_msg'] = $config['status_msg'] ?? '正常';

        return $config;
    }

    /**
     * 生成支付二维码或 H5 支付链接（移植旧 getpayqrurl）
     * 返回契约：Native→type=qr（code_url 支付串）；H5→type=url（浏览器跳转链接）。
     */
    public function getPayQr(array $order, array $config): array
    {
        // 设置默认版本
        if (!isset($config['api_version'])) {
            $config['api_version'] = 'v2';
        }

        // V2 版本只支持 Native 支付
        if ($config['api_version'] == 'v2') {
            if (($config['enable_native'] ?? '0') == '1') {
                return $this->createNativePaymentV2($order, $config);
            }
            return $this->fail('V2 版本仅支持 Native 支付');
        }

        // V3 版本支持 Native 和 H5 支付
        $isMobile = $this->isMobileDevice();

        if ($isMobile && ($config['enable_h5'] ?? '0') == '1') {
            // 手机端且启用 H5，优先 H5
            return $this->createH5Payment($order, $config);
        } elseif (($config['enable_native'] ?? '0') == '1') {
            // 电脑端或未启用 H5，使用 Native（二维码）
            return $this->createNativePayment($order, $config);
        }
        return $this->fail('未启用任何支付方式');
    }

    /**
     * 创建 Native 支付（二维码）- V3 版本
     */
    private function createNativePayment(array $order, array $config): array
    {
        $api    = new WxPayOfficialV3Api($config);
        $result = $api->createNativeOrder(
            (string) ($order['trade_no'] ?? ''),
            $order['price'] ?? 0,
            '订单号: ' . ($order['trade_no'] ?? '')
        );

        if ($result['code'] == 1) {
            return $this->ok(['qr' => $result['qr_code'], 'type' => 'qr']);
        }
        return $this->fail($result['msg'] ?? 'Native 下单失败');
    }

    /**
     * 创建 Native 支付（二维码）- V2 版本
     */
    private function createNativePaymentV2(array $order, array $config): array
    {
        // 构建统一下单参数
        $params = [
            'appid'            => $config['appid'],
            'mch_id'           => $config['mchid'],
            'nonce_str'        => $this->generateNonceStr(),
            'body'             => '订单号: ' . ($order['trade_no'] ?? ''),
            'out_trade_no'     => (string) ($order['trade_no'] ?? ''),
            'total_fee'        => intval(($order['price'] ?? 0) * 100),
            'spbill_create_ip' => $this->getClientIp(),
            'notify_url'       => $this->getNotifyUrl(),
            'trade_type'       => 'NATIVE',
        ];

        // 生成签名
        $params['sign'] = $this->generateSignV2($params, $config['api_key']);

        // 转换为 XML
        $xml = $this->arrayToXml($params);

        // 发送请求
        $response = $this->httpPostXml('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);

        if (!$response) {
            return $this->fail('请求失败');
        }

        // 解析响应
        $result = $this->xmlToArray($response);

        if (($result['return_code'] ?? '') == 'SUCCESS' && ($result['result_code'] ?? '') == 'SUCCESS') {
            return $this->ok(['qr' => $result['code_url'], 'type' => 'qr']);
        }
        $error_msg = isset($result['err_code_des']) ? $result['err_code_des'] : '未知错误';
        return $this->fail($error_msg);
    }

    /**
     * 创建 H5 支付 - V3 版本
     */
    private function createH5Payment(array $order, array $config): array
    {
        $api    = new WxPayOfficialV3Api($config);
        $result = $api->createH5Order(
            (string) ($order['trade_no'] ?? ''),
            $order['price'] ?? 0,
            '订单号: ' . ($order['trade_no'] ?? '')
        );

        if ($result['code'] == 1) {
            return $this->ok(['qr' => $result['h5_url'], 'type' => 'url']);
        }
        return $this->fail($result['msg'] ?? 'H5 下单失败');
    }

    /**
     * 关单（移植旧 trade_close，V3）
     */
    public function tradeClose(array $order, array $config): array
    {
        $api = new WxPayOfficialV3Api($config);
        $api->closeOrder((string) ($order['trade_no'] ?? ''));
        return ['code' => 1, 'msg' => ''];
    }

    /**
     * 回调验签（供 notify 控制器调用）。
     *
     * V2：XML 回调字段（含 sign）→ MD5 验签（移植旧 verifyNotifySign）。
     * V3：JSON 加密回调需用 APIv3 平台证书解密+验签，旧系统未实现，此处不放行（返回 false，安全兜底）。
     */
    public function verifyCallbackSign(array $params, array $config): bool
    {
        return $this->verifyNotifySign($params, $config);
    }

    /**
     * 验证回调签名（V2 版本，移植旧 verifyNotifySign）
     */
    private function verifyNotifySign(array $data, array $config): bool
    {
        if (!isset($data['sign'])) {
            return false;
        }
        if (empty($config['api_key'])) {
            return false;
        }

        $sign = $data['sign'];
        unset($data['sign']);

        // 重新生成签名
        $new_sign = $this->generateSignV2($data, $config['api_key']);

        return hash_equals((string) $sign, (string) $new_sign);
    }

    /**
     * 检测是否为手机设备（移植旧 isMobileDevice，改用框架 Request 取 UA）
     */
    private function isMobileDevice(): bool
    {
        $userAgent = (string) Request::header('user-agent', '');
        return preg_match('/(mobile|android|iphone|ipad|phone|webos|blackberry|windows phone)/i', $userAgent) ? true : false;
    }

    /**
     * 生成随机字符串
     */
    private function generateNonceStr(int $length = 32): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * 生成 V2 签名（MD5）
     */
    private function generateSignV2(array $params, string $key): string
    {
        // 排序参数
        ksort($params);

        // 拼接字符串
        $string = '';
        foreach ($params as $k => $v) {
            if ($k != 'sign' && $v != '' && !is_array($v)) {
                $string .= $k . '=' . $v . '&';
            }
        }
        $string .= 'key=' . $key;

        // MD5 签名
        return strtoupper(md5($string));
    }

    /**
     * 数组转 XML
     */
    private function arrayToXml(array $arr): string
    {
        $xml = '<xml>';
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= '<' . $key . '>' . $val . '</' . $key . '>';
            } else {
                $xml .= '<' . $key . '><![CDATA[' . $val . ']]></' . $key . '>';
            }
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * XML 转数组
     */
    private function xmlToArray(string $xml): array
    {
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader(true);
        }
        $values = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return is_array($values) ? $values : [];
    }

    /**
     * 发送 XML POST 请求
     */
    private function httpPostXml(string $url, string $xml)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * 获取客户端 IP
     */
    private function getClientIp(): string
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
     * 获取回调通知 URL（xlpay 微信官方异步回调端点，V2 下单用）
     */
    private function getNotifyUrl(): string
    {
        return Request::domain() . '/notify/wxpay/notify';
    }
}
