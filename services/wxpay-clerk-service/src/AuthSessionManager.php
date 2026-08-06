<?php

declare(strict_types=1);

namespace WxpayClerk;

/**
 * 店员微信账号登录会话管理器。
 *
 * 流程：
 * 1. CXPAY 调用 startAccountAuthorization → createSession()
 *    → 调用 gewe 生成登录 QR → 返回 {session_id, qr_url}
 * 2. CXPAY 展示 QR 给管理员，管理员用手机微信扫码
 * 3. CXPAY 轮询 pollSession() → gewe 检测扫码状态
 * 4. 登录成功 → gewe appId 与 wxid 写入 accounts 表 → 返回 CONFIRMED + account_id
 */
final class AuthSessionManager
{
    public function __construct(
        private readonly GeweApiClient $gewe,
        private readonly OrderStore    $store,
        private readonly string        $serviceBaseUrl,
        private readonly int           $sessionTtl = 300
    ) {}

    /**
     * 创建登录会话。
     *
     * @return array{session_id: string, qr_url: string, expires_at: int, status: string, message: string}
     */
    public function createSession(string $reference): array
    {
        $sessionId = bin2hex(random_bytes(16));

        // 向 gewe 请求登录二维码
        $loginData = $this->gewe->createLoginSession();
        $qrUrl     = (string)($loginData['qr_url'] ?? '');
        $appId     = (string)($loginData['appid']   ?? '');
        $uuid      = (string)($loginData['uuid']    ?? '');

        // 存储会话，qr_url 字段存储 "geweAppId:uuid" 供后续轮询使用
        $this->store->createAuthSession($sessionId, $reference, $this->sessionTtl);
        $this->store->updateAuthSession($sessionId, 'PENDING', $qrUrl . '|' . $appId . '|' . $uuid);

        // 注册本服务的 Webhook 回调地址到 gewe
        try {
            $callbackUrl = rtrim($this->serviceBaseUrl, '/') . '/wechat/message';
            $this->gewe->setCallback($appId, $callbackUrl);
        } catch (\Throwable) {
            // 注册回调失败不影响二维码展示，后续消息可能无法接收，但不影响登录流程
        }

        return [
            'session_id' => $sessionId,
            'qr_url'     => $qrUrl,
            'expires_at' => time() + $this->sessionTtl,
            'status'     => 'QR_READY',
            'message'    => '请用手机微信扫码完成店员账号登录绑定',
        ];
    }

    /**
     * 轮询登录会话状态。
     *
     * @return array{status: string, message: string, account_id?: string}
     */
    public function pollSession(string $sessionId): array
    {
        $session = $this->store->getAuthSession($sessionId);
        if ($session === null) {
            return ['status' => 'EXPIRED', 'message' => '会话不存在或已超时，请重新发起'];
        }

        $currentStatus = (string)($session['status'] ?? 'PENDING');
        if ($currentStatus === 'CONFIRMED') {
            return [
                'status'     => 'CONFIRMED',
                'account_id' => (string)($session['account_id'] ?? ''),
                'message'    => '店员账号登录绑定成功',
            ];
        }
        if ($currentStatus === 'FAILED') {
            return ['status' => 'FAILED', 'message' => '登录失败，二维码已过期'];
        }

        // 从 qr_url 字段解析出 gewe appId 和 uuid（存储格式：qrUrl|appId|uuid）
        $parts  = explode('|', (string)($session['qr_url'] ?? ''), 3);
        $appId  = $parts[1] ?? '';
        $uuid   = $parts[2] ?? '';

        if ($appId === '' || $uuid === '') {
            return ['status' => 'PENDING', 'message' => '等待扫码，请使用手机微信扫描二维码'];
        }

        // 向 gewe 查询扫码状态
        try {
            $result = $this->gewe->checkLoginStatus($appId, $uuid);
        } catch (\Throwable $e) {
            return ['status' => 'PENDING', 'message' => 'gewe 状态查询暂时失败，请稍后再试'];
        }

        $geweStatus = (string)($result['status'] ?? 'WAITING');

        return match ($geweStatus) {
            'CONFIRMED' => $this->handleConfirmed($sessionId, $appId, $result),
            'SCANNED'   => ['status' => 'SCANNED',  'message' => '已扫码，请在手机上点击确认登录'],
            'EXPIRED'   => $this->handleExpired($sessionId),
            'ERROR'     => $this->handleExpired($sessionId),
            default     => ['status' => 'PENDING', 'message' => '等待扫码，请使用手机微信扫描二维码'],
        };
    }

    /**
     * @param array<string, mixed> $geweResult
     * @return array{status: string, account_id: string, message: string}
     */
    private function handleConfirmed(string $sessionId, string $appId, array $geweResult): array
    {
        $wxid     = (string)($geweResult['wxid']     ?? '');
        $nickname = (string)($geweResult['nickname'] ?? '');

        // 以 gewe appId 为账号标识存入 accounts 表
        $accountId = $wxid !== '' ? $wxid : $appId;

        $this->store->upsertAccount($accountId, $nickname, $appId, 'ONLINE');
        $this->store->updateAuthSession($sessionId, 'CONFIRMED', '', $accountId);

        return [
            'status'     => 'CONFIRMED',
            'account_id' => $accountId,
            'message'    => "微信账号「{$nickname}」登录成功，已绑定为店员接收账号",
        ];
    }

    /** @return array{status: string, message: string} */
    private function handleExpired(string $sessionId): array
    {
        $this->store->updateAuthSession($sessionId, 'FAILED');
        return ['status' => 'FAILED', 'message' => '二维码已过期，请重新发起登录'];
    }
}
