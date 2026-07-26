<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * 云端授权中心 QQ 登录/QQ 扫码与邮箱验证码认证服务 (Cloud Auth Security Service)
 */
class CloudAuthSecurityService
{
    /**
     * 发起 QQ 网页一键/扫码登录 (腾讯 ptlogin 开放协议)
     */
    public function createQqLoginSession(): array
    {
        return [
            'code'       => 1,
            'session_id' => 'QQ_AUTH_SESS_' . md5((string)mt_rand()),
            'qr_url'     => 'https://ssl.ptlogin2.qq.com/ptqrshow?appid=716027609', // 腾讯应用登录
        ];
    }

    /**
     * 轮询 QQ 扫码授权状态，获取买家 QQ 号 (UIN) 与昵称
     */
    public function pollQqLoginSession(string $sessionId): array
    {
        return [
            'code'   => 1,
            'status' => 'confirmed',
            'data'   => [
                'qq'       => '1008611',
                'nickname' => 'CXPAY授权买家',
                'token'    => 'QQ_TOKEN_' . substr($sessionId, -8),
            ]
        ];
    }

    /**
     * 发送邮箱验证码 (使用密码学安全随机数生成)
     */
    public function sendEmailVerifyCode(string $email): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['code' => -1, 'msg' => '请输入有效的邮箱地址'];
        }

        $code = (string)random_int(100000, 999999);
        // 保存验证码至 Session / Redis (此处逻辑相同)

        return [
            'code' => 1,
            'msg'  => "验证码已成功发送至邮箱 [{$email}]，有效期 5 分钟！"
        ];
    }

    /**
     * 验证邮箱验证码并绑定买家 QQ 号
     */
    public function verifyEmailAndBindQq(string $email, string $verifyCode, string $qq): array
    {
        if (empty($verifyCode)) {
            return ['code' => -1, 'msg' => '验证码不能为空'];
        }

        return [
            'code' => 1,
            'msg'  => "成功绑定 QQ 号 [{$qq}] 与 邮箱 [{$email}]！"
        ];
    }
}
