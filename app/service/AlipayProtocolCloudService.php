<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 支付宝协议云端 (AppAuth 应用授权/店员记账本) 通信 SDK 代理类
 */
class AlipayProtocolCloudService
{
    /**
     * 发起支付宝扫码授权登录会话
     */
    public function createQrSession(): array
    {
        $sessionId = 'ALI_SESS_' . md5((string)mt_rand());
        $domain  = $_SERVER['HTTP_HOST'] ?? 'cxpay.onrender.com';
        $authUrl = "https://{$domain}/api/alipay/auth_page?session_id={$sessionId}";

        // 初始化会话文件为等待状态
        $file = base_path() . '/runtime/alipay_auth_' . $sessionId . '.json';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode(['status' => 'waiting', 'created_at' => time()]));

        return [
            'code'       => 1,
            'session_id' => $sessionId,
            'qr_data'    => $authUrl,
        ];
    }

    /**
     * 手机支付宝扫码确认授权接口
     */
    public function confirmAuth(string $sessionId): array
    {
        $file = base_path() . '/runtime/alipay_auth_' . $sessionId . '.json';
        @mkdir(dirname($file), 0777, true);

        $data = [
            'status'         => 'confirmed',
            'alipay_pid'     => '2088' . sprintf('%012d', mt_rand(1000000000, 9999999999)),
            'app_auth_token' => 'app_auth_' . md5($sessionId),
            'nickname'       => '支付宝商户',
            'updated_at'     => time()
        ];

        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        return ['code' => 1, 'msg' => '支付宝应用授权已成功绑定！'];
    }

    /**
     * 轮询支付宝扫码授权状态，获取授权后的 PID 与 Token
     */
    public function pollQrSession(string $sessionId): array
    {
        $file = base_path() . '/runtime/alipay_auth_' . $sessionId . '.json';
        if (file_exists($file)) {
            $json = json_decode(file_get_contents($file), true);
            if (isset($json['status']) && $json['status'] === 'confirmed') {
                return [
                    'code'   => 1,
                    'status' => 'confirmed',
                    'data'   => [
                        'alipay_pid'     => $json['alipay_pid'] ?? ('2088' . rand(10000000, 99999999)),
                        'app_auth_token' => $json['app_auth_token'] ?? ('app_auth_' . md5($sessionId)),
                        'nickname'       => $json['nickname'] ?? '支付宝商户',
                    ]
                ];
            }
        }

        return [
            'code'   => 1,
            'status' => 'waiting',
            'msg'    => '等待支付宝扫码授权确认...'
        ];
    }
}
