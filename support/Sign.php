<?php

declare(strict_types=1);

namespace support;

/**
 * 易支付/平台统一签名工具类 (带宽松比较与保留两位小数格式化)
 */
class Sign
{
    /**
     * 生成易支付标准 MD5 签名
     */
    public static function makeSign(array $param, string $key): string
    {
        ksort($param);
        $signstr = '';

        foreach ($param as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $k !== 'a' && $k !== 'c' && $k !== 'm' && $k !== 's' && $v !== '' && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = rtrim($signstr, '&');
        $signstr .= $key;

        return md5($signstr);
    }

    /**
     * 校验 MD5 签名
     */
    public static function verifySign(array $data, string $key): bool
    {
        if (empty($data['sign'])) {
            return false;
        }
        return self::makeSign($data, $key) === $data['sign'];
    }

    /**
     * 构建发送给商户的异步/同步通知数据包 (金额严格保留 2 位小数 "1.00")
     */
    public static function buildMerchantNotifyData(array $order, string $merchantKey): array
    {
        $data = [
            'pid'          => $order['merchant_id'],
            'trade_no'     => $order['trade_no'],
            'out_trade_no' => $order['out_trade_no'],
            'type'         => $order['pay_type'],
            'name'         => $order['subject'] ?? '网络充值',
            'money'        => number_format((float)$order['amount'], 2, '.', ''),
            'trade_status' => 'TRADE_SUCCESS',
        ];

        $data['sign']      = self::makeSign($data, $merchantKey);
        $data['sign_type'] = 'MD5';

        return $data;
    }

    /**
     * 校验商户接收回调的响应文本是否正确 (标准易支付规范要求商户返回 success)
     */
    public static function callbackNotify($response): bool
    {
        if (is_string($response)) {
            return str_contains(strtolower(trim($response)), 'success');
        }
        return false;
    }
}
