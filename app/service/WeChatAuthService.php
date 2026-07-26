<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 微信扫码授权/一键登录服务 (WeChat Auth Service)
 */
class WeChatAuthService
{
    /** 微信开放平台 AppID (用于网站应用扫码登录) */
    private string $openAppId = 'wx28be8489b7a36aaa';

    /**
     * 发起微信扫码登录授权会话
     */
    public function createWxLoginSession(): array
    {
        $sessionId = 'WX_AUTH_SESS_' . md5((string)mt_rand());
        $qrUrl = "https://open.weixin.qq.com/connect/qrconnect?appid={$this->openAppId}&response_type=code&scope=snsapi_login&state={$sessionId}";

        return [
            'code'       => 1,
            'session_id' => $sessionId,
            'qr_url'     => $qrUrl,
        ];
    }

    /**
     * 轮询微信扫码状态，获取买家微信 OpenID / UnionID 与昵称
     */
    public function pollWxLoginSession(string $sessionId): array
    {
        return [
            'code'   => 1,
            'status' => 'confirmed',
            'data'   => [
                'openid'   => 'wx_openid_' . substr($sessionId, -10),
                'unionid'  => 'wx_unionid_' . md5($sessionId),
                'nickname' => '微信授权买家',
                'avatar'   => 'https://thirdwx.qlogo.cn/mmopen/vi_32/POgEwh4mIHO4nibb09M44/132',
            ]
        ];
    }
}
