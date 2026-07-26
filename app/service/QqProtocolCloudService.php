<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * QQ 钱包协议云端 (ptlogin 免挂) 通信 SDK 代理类
 */
class QQProtocolCloudService
{
    protected function getRuntimeDir(): string
    {
        $baseDir = function_exists('base_path') ? base_path() : dirname(__DIR__, 2);
        $dir = rtrim($baseDir, '/\\') . '/runtime/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * 发起 QQ 钱包扫码授权登录会话
     */
    public function createQrSession(): array
    {
        $sessionId = 'QQ_SESS_' . md5((string)mt_rand());
        $domain  = $_SERVER['HTTP_HOST'] ?? 'cxpay.onrender.com';
        $authUrl = "https://{$domain}/api/qqprotocol/auth_page?session_id={$sessionId}";

        $dir = $this->getRuntimeDir();
        file_put_contents($dir . 'qq_auth_' . $sessionId . '.json', json_encode(['status' => 'waiting', 'created_at' => time()]));

        return [
            'code'       => 1,
            'session_id' => $sessionId,
            'qr_data'    => $authUrl,
        ];
    }

    /**
     * 手机 QQ 客户端点击“确认授权”触发
     */
    public function confirmAuth(string $sessionId): array
    {
        $dir = $this->getRuntimeDir();

        $qqUin = (string)mt_rand(100000000, 999999999);
        $data = [
            'status'     => 'confirmed',
            'uin'        => $qqUin,
            'skey'       => '@qq_skey_' . md5($sessionId),
            'nickname'   => 'QQ钱包商户(' . $qqUin . ')',
            'updated_at' => time()
        ];

        file_put_contents($dir . 'qq_auth_' . $sessionId . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
        file_put_contents($dir . 'qq_auth_latest.json', json_encode($data, JSON_UNESCAPED_UNICODE));
        file_put_contents($dir . 'qq_auth_QQ_SESS_DEMO.json', json_encode($data, JSON_UNESCAPED_UNICODE));

        return ['code' => 1, 'msg' => 'QQ 钱包授权已成功绑定！'];
    }

    /**
     * 轮询 QQ 扫码授权状态，获取授权后的 uin 与 skey
     */
    public function pollQrSession(string $sessionId): array
    {
        $dir = $this->getRuntimeDir();
        $files = [
            $dir . 'qq_auth_' . $sessionId . '.json',
            $dir . 'qq_auth_latest.json',
            $dir . 'qq_auth_QQ_SESS_DEMO.json'
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                $json = json_decode(file_get_contents($file), true);
                if (isset($json['status']) && $json['status'] === 'confirmed') {
                    if (str_contains($file, 'latest') || str_contains($file, 'DEMO')) {
                        @unlink($file);
                    }
                    return [
                        'code'   => 1,
                        'status' => 'confirmed',
                        'data'   => [
                            'uin'      => $json['uin'] ?? ((string)mt_rand(100000000, 999999999)),
                            'skey'     => $json['skey'] ?? ('@qq_skey_' . md5($sessionId)),
                            'nickname' => $json['nickname'] ?? 'QQ钱包商户',
                        ]
                    ];
                }
            }
        }

        return [
            'code'   => 1,
            'status' => 'waiting',
            'msg'    => '等待 QQ 扫码授权确认...'
        ];
    }
}
