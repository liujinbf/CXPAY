<?php

namespace app\common\library\payment\plugins\alipay_official;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\plugins\alipay_official\lib\AliPayOfficialApi;
use app\common\model\PayOrder;
use app\admin\model\User;
use app\core\SettlementService;
use app\core\CallbackService;
use think\facade\Request;

/**
 * 支付宝官方支付驱动
 *
 * 移植自旧 protected/payplug/alipay_official/config.php（v2.0.1）。
 * config() / upchannel() / normalizeKey() / verifyCallbackSign() 为纯逻辑，已完整移植并可用。
 * getPayQr()（当面付 precreate / 手机网站 wap / 电脑网站 pc）与 tradeClose() 依赖
 * AliPayOfficialApi SDK（plugins/alipay_official/lib/）与真实商户凭据出码，逻辑逐字节忠实移植，
 * 未联网验证。
 *
 * @see /www/wwwroot/peakpay/protected/payplug/alipay_official/config.php 原始实现
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'alipay_official';

    public function config(): array
    {
        return [
            'version'       => '2.0.1',
            'name'          => '支付宝官方支付',
            'author'        => '小乐',
            'link'          => 'https://xiaole.ink',
            'type'          => 'alipay',
            'c_type'        => 'alipay_official',
            'switch_qr_url' => false,
            'getqrlogin'    => false,
            'inputs'        => [
                'app_id' => [
                    'name' => '应用 APPID',
                    'type' => 'input',
                    'note' => '支付宝应用 APPID',
                ],
                'private_key' => [
                    'name' => '应用私钥',
                    'type' => 'textarea',
                    'note' => '应用私钥内容',
                ],
                'alipay_public_key' => [
                    'name' => '支付宝公钥',
                    'type' => 'textarea',
                    'note' => '支付宝公钥内容',
                ],
                'enable_face_to_face' => [
                    'name'    => '启用当面付',
                    'type'    => 'select',
                    'note'    => '是否启用当面付（二维码支付，电脑端和手机端都支持）',
                    'options' => ['1' => '启用', '0' => '禁用'],
                    'default' => '1',
                ],
                'enable_mobile_web' => [
                    'name'    => '启用手机网站支付',
                    'type'    => 'select',
                    'note'    => '是否启用手机网站支付（仅手机端支持）',
                    'options' => ['1' => '启用', '0' => '禁用'],
                    'default' => '1',
                ],
                'enable_pc_web' => [
                    'name'    => '启用电脑网站支付',
                    'type'    => 'select',
                    'note'    => '是否启用电脑网站支付（仅电脑端支持）',
                    'options' => ['1' => '启用', '0' => '禁用'],
                    'default' => '1',
                ],
            ],
            'note' => '<span style="color:red">使用说明：</span><br>'
                . '① 请根据实际情况开启3种支付方式。<br>'
                . '② 请勿开启您未在支付宝官方签约的支付方式。<br>'
                . '③ 手机端访问优先调用手机网站支付（如已启用），否则调用当面付。电脑网站支付在手机端无法调用！<br>'
                . '④ 电脑端访问优先调用电脑网站支付（如已启用），否则调用当面付。手机网站支付在电脑端无法调用！',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['app_id'])) {
            return $this->fail('请填写应用 APPID');
        }
        if (empty($config['private_key'])) {
            return $this->fail('请填写应用私钥');
        }
        if (empty($config['alipay_public_key'])) {
            return $this->fail('请填写支付宝公钥');
        }

        $config['enable_face_to_face'] = $config['enable_face_to_face'] ?? '0';
        $config['enable_mobile_web']   = $config['enable_mobile_web'] ?? '0';
        $config['enable_pc_web']       = $config['enable_pc_web'] ?? '0';

        if ($config['enable_face_to_face'] != '1' && $config['enable_mobile_web'] != '1' && $config['enable_pc_web'] != '1') {
            return $this->fail('至少需要启用一种支付方式');
        }

        $config['private_key']       = self::normalizeKey($config['private_key']);
        $config['alipay_public_key'] = self::normalizeKey($config['alipay_public_key']);

        $config['status']     = $config['status'] ?? 1;
        $config['status_msg'] = $config['status_msg'] ?? '正常';
        $config['msg']        = $config['msg'] ?? '';

        return $config;
    }

    /**
     * 服务端监控方式：官方通道无账单列表，改用逐笔查单(alipay.trade.query)兜底。
     * 即使支付宝异步通知未送达(域名不可达等)，也能由监控 worker 主动查单到账。
     */
    public function monitorMode(): string
    {
        return 'server';
    }

    /**
     * 服务端监控一次：查本通道待支付订单的支付宝交易状态，已支付则置态扣费 + 通知商户。
     */
    public function monitor(array $channelRow, array $config): array
    {
        $pending = PayOrder::where(['channel_id' => (int) $channelRow['id'], 'status' => 0])
            ->order('create_time desc')->limit(50)
            ->select();

        if (count($pending)) {
            $api = new AliPayOfficialApi($config);
            foreach ($pending as $o) {
                try {
                    $r = $api->queryOrder((string) $o['trade_no']);
                } catch (\Throwable $e) {
                    continue;
                }
                if (($r['code'] ?? 0) != 1) {
                    continue;
                }
                $st = (string) ($r['trade_status'] ?? '');
                if ($st !== 'TRADE_SUCCESS' && $st !== 'TRADE_FINISHED') {
                    continue;
                }
                // 金额比对(分)
                if ((int) round(((float) ($r['amount'] ?? 0)) * 100) !== (int) round(((float) $o['price']) * 100)) {
                    continue;
                }
                // 置态扣费 + 通知商户（对外用商户对接PID）
                $arr = $o->toArray();
                if ((new SettlementService())->forceSettle($arr, '官方查单到账扣除手续费')) {
                    $user = User::find($o['pid']);
                    if ($user) {
                        try {
                            $arr['pid'] = $user->pid;
                            (new CallbackService())->notifyMerchant($arr, $user->pay_key);
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }
        }

        $config['status'] = '1'; // 有凭据即视为在线
        $config['bill']   = false;
        return $config;
    }

    public function getPayQr(array $order, array $config): array
    {
        // 逐字节移植旧 getpayqrurl：按设备类型 + 启用开关选择支付方式。
        // 返回契约：type=url（浏览器跳转链接：手机网站/电脑网站）、type=qr（当面付二维码支付串）。
        $api       = new AliPayOfficialApi($config);
        $tradeNo   = (string) ($order['trade_no'] ?? '');
        $price     = $order['price'] ?? 0;
        $subject   = '订单号: ' . $tradeNo;
        $isMobile  = $this->isMobileDevice();

        if ($isMobile) {
            // 手机端：优先手机网站支付，否则当面付
            if (($config['enable_mobile_web'] ?? '0') == '1') {
                $result = $api->createH5Order($tradeNo, $price, $subject);
                if ($result['code'] == 1) {
                    return $this->ok(['qr' => $result['h5_url'], 'type' => 'url']);
                }
                return $this->fail($result['msg'] ?? '手机网站下单失败');
            } elseif (($config['enable_face_to_face'] ?? '0') == '1') {
                $result = $api->createNativeOrder($tradeNo, $price, $subject);
                if ($result['code'] == 1) {
                    return $this->ok(['qr' => $result['qr_code'], 'type' => 'qr']);
                }
                return $this->fail($result['msg'] ?? '当面付下单失败');
            }
            return $this->fail('未启用任何支付方式');
        }

        // 电脑端：优先电脑网站支付，否则当面付
        if (($config['enable_pc_web'] ?? '0') == '1') {
            $result = $api->createPageOrder($tradeNo, $price, $subject);
            if ($result['code'] == 1) {
                return $this->ok(['qr' => $result['pay_url'], 'type' => 'url']);
            }
            return $this->fail($result['msg'] ?? '电脑网站下单失败');
        } elseif (($config['enable_face_to_face'] ?? '0') == '1') {
            $result = $api->createNativeOrder($tradeNo, $price, $subject);
            if ($result['code'] == 1) {
                return $this->ok(['qr' => $result['qr_code'], 'type' => 'qr']);
            }
            return $this->fail($result['msg'] ?? '当面付下单失败');
        }
        return $this->fail('未启用任何支付方式');
    }

    /**
     * 关单（移植旧 trade_close）
     */
    public function tradeClose(array $order, array $config): array
    {
        $api = new AliPayOfficialApi($config);
        $api->closeOrder((string) ($order['trade_no'] ?? ''));
        return ['code' => 1, 'msg' => ''];
    }

    /**
     * 检测是否为手机设备（移植旧 isMobileDevice，改用框架 Request 取 UA）
     */
    private function isMobileDevice(): bool
    {
        $userAgent = (string) Request::header('user-agent', '');
        return preg_match('/(mobile|android|iphone|ipad|phone|webos|blackberry|windows phone)/i', $userAgent) ? true : false;
    }

    public function verifyCallbackSign(array $params, array $config): bool
    {
        // 逐字节移植旧 alipay_official::verifyCallbackSign（支付宝公钥 RSA2/SHA256 验签）
        // 兼容：过滤全参数集 / 同步固定字段集；UTF-8 与 GBK 两种编码；多种公钥 PEM 格式。
        if (empty($params) || empty($config['alipay_public_key'])) {
            return false;
        }

        $sign = isset($params['sign']) ? trim($params['sign']) : '';
        if ($sign === '') {
            return false;
        }

        $sign_decoded = base64_decode(str_replace(' ', '+', $sign), true);
        if ($sign_decoded === false) {
            return false;
        }

        // 参数集1：过滤框架污染参数
        $filtered_params = $params;
        unset($filtered_params['sign'], $filtered_params['sign_type'],
              $filtered_params['a'], $filtered_params['c'], $filtered_params['m'], $filtered_params['s']);
        $param_sets = [];
        $param_sets[] = ['type' => 'filtered_all', 'params' => $filtered_params];

        // 参数集2：仅同步回调固定字段
        $sync_fields = ['out_trade_no', 'charset', 'method', 'total_amount', 'trade_no',
                        'auth_app_id', 'version', 'app_id', 'seller_id', 'timestamp'];
        $sync_params = [];
        foreach ($sync_fields as $field) {
            if (isset($params[$field]) && !is_array($params[$field]) && $params[$field] !== '') {
                $sync_params[$field] = $params[$field];
            }
        }
        if (!empty($sync_params)) {
            $param_sets[] = ['type' => 'sync_fields_only', 'params' => $sync_params];
        }

        // 公钥纯内容
        $raw_public_key = trim($config['alipay_public_key']);
        $public_key_body = preg_replace('/-----BEGIN [A-Z\s]+-----/', '', $raw_public_key);
        $public_key_body = preg_replace('/-----END [A-Z\s]+-----/', '', $public_key_body);
        $public_key_body = preg_replace('/\s+/', '', $public_key_body);

        // 多种公钥 PEM 格式
        $public_keys = [];
        if (strpos($raw_public_key, 'BEGIN ') !== false) {
            $public_keys[] = ['type' => 'raw_input',
                'value' => str_replace(["\r\n", "\r"], "\n", $raw_public_key)];
        }
        if ($public_key_body !== '') {
            $pem_body = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($public_key_body, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
            $public_keys[] = ['type' => 'public_key', 'value' => $pem_body];
            $pem_body = "-----BEGIN RSA PUBLIC KEY-----\n" . wordwrap($public_key_body, 64, "\n", true) . "\n-----END RSA PUBLIC KEY-----";
            $public_keys[] = ['type' => 'rsa_public_key', 'value' => $pem_body];
            $pem_body = "-----BEGIN CERTIFICATE-----\n" . wordwrap($public_key_body, 64, "\n", true) . "\n-----END CERTIFICATE-----";
            $public_keys[] = ['type' => 'certificate', 'value' => $pem_body];
        }

        // 去重
        $unique_public_keys = [];
        foreach ($public_keys as $item) {
            $unique_public_keys[$item['type'] . '|' . md5($item['value'])] = $item;
        }
        $public_keys = array_values($unique_public_keys);

        foreach ($param_sets as $param_set_item) {
            $param_set = $param_set_item['params'];
            ksort($param_set);

            // UTF-8 与 GBK 两种签名字符串
            $verify_contents = [];
            $utf8_content = '';
            $gbk_content = '';
            foreach ($param_set as $key => $value) {
                if ($value === '' || $value === null || is_array($value) || substr((string)$value, 0, 1) === '@') {
                    continue;
                }
                $value = (string)$value;
                if ($utf8_content !== '') {
                    $utf8_content .= '&';
                    $gbk_content .= '&';
                }
                $utf8_content .= $key . '=' . $value;
                $gbk_value = @mb_convert_encoding($value, 'GBK', 'UTF-8');
                if ($gbk_value === false) {
                    $gbk_value = $value;
                }
                $gbk_content .= $key . '=' . $gbk_value;
            }
            if ($utf8_content !== '') {
                $verify_contents[] = ['encoding' => 'utf8', 'content' => $utf8_content];
            }
            if ($gbk_content !== '' && $gbk_content !== $utf8_content) {
                $verify_contents[] = ['encoding' => 'gbk', 'content' => $gbk_content];
            }

            foreach ($verify_contents as $verify_content_item) {
                foreach ($public_keys as $public_key_item) {
                    $public_key_resource = @openssl_pkey_get_public($public_key_item['value']);
                    if (!$public_key_resource) {
                        continue;
                    }
                    $verify_result = @openssl_verify(
                        $verify_content_item['content'],
                        $sign_decoded,
                        $public_key_resource,
                        OPENSSL_ALGO_SHA256
                    );
                    if ($verify_result === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * 规范化 PEM 密钥（移除 BEGIN/END 标记与多行空白），与旧实现一致
     */
    private static function normalizeKey(?string $key): ?string
    {
        if (empty($key)) {
            return $key;
        }

        $key = preg_replace('/-----BEGIN [A-Z\s]+-----/', '', $key);
        $key = preg_replace('/-----END [A-Z\s]+-----/', '', $key);
        $key = trim($key);

        if (strpos($key, "\n") !== false || strpos($key, "\r") !== false) {
            $key = preg_replace('/\s+/', '', $key);
        }

        return $key;
    }
}
