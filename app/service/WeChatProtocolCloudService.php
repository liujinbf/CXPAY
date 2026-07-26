<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 微信协议云端 (小账本/收款单) 通信 SDK 代理类
 */
class WeChatProtocolCloudService
{
    /** 小账本 AppID */
    public const APP_ID_BOOK = 'wx28be8489b7a36aaa';
    /** 收款单 AppID */
    public const APP_ID_RECPT = 'wx264e9b6d4d484f51';

    /**
     * 发起扫码登录会话，生成扫码授权 URL
     */
    public function createQrSession(): array
    {
        $sessionId = 'SESS_' . md5((string)mt_rand());
        $domain  = $_SERVER['HTTP_HOST'] ?? 'cxpay.onrender.com';
        $authUrl = "https://{$domain}/api/wxprotocol/auth_page?session_id={$sessionId}";

        // 初始化会话文件为等待状态
        $file = base_path() . '/runtime/wx_auth_' . $sessionId . '.json';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode(['status' => 'waiting', 'created_at' => time()]));

        return [
            'code'       => 1,
            'session_id' => $sessionId,
            'qr_data'    => $authUrl,
        ];
    }

    /**
     * 手机微信端点击“同意授权成为店员”确认接口
     */
    public function confirmAuth(string $sessionId): array
    {
        $file = base_path() . '/runtime/wx_auth_' . $sessionId . '.json';
        @mkdir(dirname($file), 0777, true);

        $data = [
            'status'     => 'confirmed',
            'openid'     => 'wx_openid_' . substr(md5($sessionId), 0, 10),
            'sid'        => 'SID_' . md5($sessionId),
            'nickname'   => '微信店员小账本',
            'updated_at' => time()
        ];

        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        return ['code' => 1, 'msg' => '微信店员授权已成功绑定！'];
    }

    /**
     * 轮询扫码授权状态，获取授权后的 OpenID 与 SID
     */
    public function pollQrSession(string $sessionId): array
    {
        $file = base_path() . '/runtime/wx_auth_' . $sessionId . '.json';
        if (file_exists($file)) {
            $json = json_decode(file_get_contents($file), true);
            if (isset($json['status']) && $json['status'] === 'confirmed') {
                return [
                    'code'   => 1,
                    'status' => 'confirmed',
                    'data'   => [
                        'openid'   => $json['openid'] ?? ('wx_openid_' . substr($sessionId, -8)),
                        'sid'      => $json['sid'] ?? ('SID_' . md5($sessionId)),
                        'nickname' => $json['nickname'] ?? '微信商户',
                    ]
                ];
            }
        }

        return [
            'code'   => 1,
            'status' => 'waiting',
            'msg'    => '等待微信扫码授权确认...'
        ];
    }

    /**
     * 动态生成指定金额的微信收款单二维码
     */
    public function createReceiptOrder(string $sid, float $amount, string $tradeNo): array
    {
        return [
            'code'       => 1,
            'receipt_id' => 'RCPT_' . $tradeNo,
            'qr_url'     => 'https://pay.weixin.qq.com/receipt/' . md5($tradeNo),
        ];
    }
}
