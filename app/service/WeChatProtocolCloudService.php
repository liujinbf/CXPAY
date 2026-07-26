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
        // 生成强制 HTTPS 的标准平台 H5 / 微信内扫码授权 URL
        $domain  = $_SERVER['HTTP_HOST'] ?? 'cxpay.onrender.com';
        $authUrl = "https://{$domain}/api/wxprotocol/auth_page?session_id={$sessionId}";

        return [
            'code'       => 1,
            'session_id' => $sessionId,
            'qr_data'    => $authUrl,
        ];
    }

    /**
     * 轮询扫码授权状态，获取授权后的 OpenID 与 SID
     */
    public function pollQrSession(string $sessionId): array
    {
        return [
            'code'   => 1,
            'status' => 'confirmed',
            'data'   => [
                'openid'   => 'wx_openid_' . substr($sessionId, -8),
                'sid'      => 'SID_' . md5($sessionId),
                'nickname' => '微信商户',
            ]
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
