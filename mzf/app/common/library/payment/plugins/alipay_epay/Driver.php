<?php

namespace app\common\library\payment\plugins\alipay_epay;

use app\common\library\payment\AbstractPaymentChannel;
use app\common\library\payment\lib\EpayCore;
use think\facade\Log;

/**
 * 易支付-支付宝 驱动（alipay_epay）
 *
 * 移植自旧 protected/payplug/alipay_epay/config.php（v1.0.0）。
 * 原理：对接彩虹易支付接口，支持 submit 跳转模式与 mapi 收银台出码。
 *   - submit：生成跳转链接，前端跳转到易支付收银台（type=url）
 *   - mapi：调易支付 mapi.php 下单，返回 qrcode(二维码,type=qr) 或 payurl(链接,type=url)；
 *           异常/失败回退为 submit 跳转链接
 * 回调：易支付服务器异步回调 → verifyCallbackSign(MD5 验签) → 入账通知商户。
 * monitorMode=none（回调制，无需监控/轮询）。
 *
 * @see /www/wwwroot/peakpay/protected/payplug/alipay_epay/config.php 原始实现
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'alipay_epay';

    /** 易支付上游支付方式类型 */
    protected string $payType = 'alipay';

    public function config(): array
    {
        return [
            'version'       => '1.0.0',
            'name'          => '易支付-支付宝',
            'author'        => '小乐',
            'link'          => 'https://xiaole.ink',
            'type'          => 'alipay',
            'c_type'        => 'alipay_epay',
            'switch_qr_url' => false,
            'getqrlogin'    => false,
            'inputs'        => [
                'apiurl' => [
                    'name' => '接口地址',
                    'type' => 'input',
                    'note' => '例如 http://pay.www.com/',
                ],
                'pid' => [
                    'name' => '商户ID',
                    'type' => 'input',
                    'note' => '填写你的商户PID',
                ],
                'key' => [
                    'name' => '商户密钥',
                    'type' => 'input',
                    'note' => '填写你的商户KEY',
                ],
                'mode' => [
                    'name'    => '支付模式',
                    'type'    => 'select',
                    'options' => [
                        'submit' => 'submit跳转模式',
                        'mapi'   => 'mapi收银台',
                    ],
                    'note' => 'submit跳转模式为直接跳转支付页面，mapi收银台为生成二维码支付不进行跳转',
                ],
            ],
            'note' => '对接易支付接口实现支付回调',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        if (empty($config['apiurl']) || empty($config['pid']) || empty($config['key'])) {
            Log::channel('file')->info('Epay[' . ($channelRow['c_type'] ?? $this->cType) . ']配置不完整');
            return $this->fail('配置不完整');
        }
        if (substr($config['apiurl'], -1) !== '/') {
            $config['apiurl'] .= '/';
        }
        if (!isset($config['mode']) || !in_array($config['mode'], ['submit', 'mapi'])) {
            $config['mode'] = 'submit';
        }
        Log::channel('file')->info('Epay[' . ($channelRow['c_type'] ?? $this->cType) . ']配置成功');
        return $config;
    }

    /**
     * 出码：submit 跳转链接（type=url）或 mapi 二维码/链接（type=qr|url）。
     * 失败回退为 submit 跳转链接。
     */
    public function getPayQr(array $order, array $config): array
    {
        if (!isset($config['apiurl'])) {
            return $this->fail('未配置接口地址');
        }
        $config['apiurl'] = rtrim(trim($config['apiurl']));
        if (substr($config['apiurl'], -1) !== '/') {
            $config['apiurl'] .= '/';
        }

        $notify_url = rtrim(request()->domain() . '/notify/epay/notify');
        $return_url = rtrim(request()->domain() . '/notify/epay/return');
        $param = [
            'pid'          => $config['pid'] ?? '',
            'type'         => $this->payType,
            'notify_url'   => $notify_url,
            'return_url'   => $return_url,
            'out_trade_no' => $order['trade_no'] ?? '',
            'name'         => $order['name'] ?? '',
            'money'        => $order['price'] ?? '',
        ];
        $tradeNo = $param['out_trade_no'];
        $mode = ($config['mode'] ?? 'submit') ?: 'submit';
        $core = new EpayCore($config);

        if ($mode === 'mapi') {
            $result = $core->apiPay($param);
            if (!is_array($result) || !isset($result['code']) || $result['code'] != 1) {
                Log::channel('file')->info('Epay[' . $this->payType . ']mapi异常/失败,回退跳转:' . $tradeNo);
                return $this->ok(['qr' => $core->getPayLink($param), 'type' => 'url']);
            }
            if (!empty($result['qrcode'])) {
                return $this->ok(['qr' => $result['qrcode'], 'type' => 'qr']);
            }
            if (!empty($result['payurl'])) {
                return $this->ok(['qr' => $result['payurl'], 'type' => 'url']);
            }
            Log::channel('file')->info('Epay[' . $this->payType . ']mapi未返回支付链接,回退跳转:' . $tradeNo);
            return $this->ok(['qr' => $core->getPayLink($param), 'type' => 'url']);
        }

        // submit 模式：生成跳转链接
        $link = $core->getPayLink($param);
        if (!strpos($link, 'submit.php')) {
            $apiRes = $core->apiPay($param);
            $link = $config['apiurl'] . 'submit.php?' . http_build_query(is_array($apiRes) ? $apiRes : $param);
        }
        Log::channel('file')->info('Epay[' . $this->payType . ']生成跳转链接:' . $tradeNo);
        return $this->ok(['qr' => $link, 'type' => 'url']);
    }

    public function getPayCurl(array $channelRow, array $config, array $order = []): array
    {
        return [];
    }

    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array
    {
        $config['bill'] = false;
        return $config;
    }

    public function tradeClose(array $order, array $config): array
    {
        return ['code' => 1, 'msg' => 'not_supported'];
    }

    /**
     * 易支付回调验签：MD5，ksort → k=v&(排除 sign/sign_type 及空) → 尾拼 key → md5 比对。
     */
    public function verifyCallbackSign(array $params, array $config): bool
    {
        if (empty($params) || empty($config['key'])) {
            return false;
        }
        return (new EpayCore($config))->verifyParams($params);
    }
}
