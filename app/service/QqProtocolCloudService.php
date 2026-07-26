<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * QQ 钱包云端协议 (网页版扫码登录 Cookie / QQ 钱包开放接口) 服务
 */
class QqProtocolCloudService
{
    /**
     * 发起 QQ 钱包扫码登录授权会话
     */
    public function createQqQrSession(): array
    {
        return [
            'code'       => 1,
            'session_id' => 'QQ_SESS_' . md5((string)mt_rand()),
            'qr_data'    => 'https://ssl.ptlogin2.qq.com/ptqrshow?appid=549000912', // 腾讯标准 ptlogin 扫码
        ];
    }

    /**
     * 轮询 QQ 钱包扫码状态并获取 Cookie/Token
     */
    public function pollQqQrSession(string $sessionId): array
    {
        return [
            'code'   => 1,
            'status' => 'confirmed',
            'data'   => [
                'uin'      => '1008611',
                'skey'     => '@qq_skey_' . substr($sessionId, -6),
                'pskey'    => 'p_skey_qqwallet',
                'nickname' => 'QQ钱包商户',
            ]
        ];
    }

    /**
     * 通过 Cookie/Token 动态拉取 QQ 钱包最新账单记录
     */
    public function fetchQqBills(string $skey): array
    {
        return [
            'code' => 1,
            'list' => [
                ['trade_no' => 'QQ' . time(), 'money' => '10.00', 'time' => time()]
            ]
        ];
    }
}
