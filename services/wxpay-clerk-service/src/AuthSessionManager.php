<?php

declare(strict_types=1);

namespace WxpayClerk;

final class AuthSessionManager
{
    public function __construct(
        private readonly GeweApiClientInterface $gewe,
        private readonly AuthSessionRepository $sessions,
        private readonly AccountRepository $accounts,
        private readonly string $webhookUrl,
        private readonly int $sessionTtl = 300
    ) {
    }

    /** @return array{session_id: string, qr_url: string, expires_at: int, status: string, message: string} */
    public function createSession(string $reference): array
    {
        $sessionId = bin2hex(random_bytes(16));
        $login = $this->gewe->createLoginSession();
        $qrUrl = (string) ($login['qr_url'] ?? '');
        $appId = (string) ($login['appid'] ?? '');
        $uuid = (string) ($login['uuid'] ?? '');
        if ($qrUrl === '' || $appId === '' || $uuid === '') {
            throw new ApiException(503, 'Gewe 未返回完整登录会话');
        }

        $now = time();
        $this->sessions->create($sessionId, $reference, $this->sessionTtl, $now);
        $this->sessions->update($sessionId, 'PENDING', $qrUrl . '|' . $appId . '|' . $uuid);
        $this->gewe->setCallback($appId, $this->webhookUrl);

        return [
            'session_id' => $sessionId,
            'qr_url' => $qrUrl,
            'expires_at' => $now + $this->sessionTtl,
            'status' => 'QR_READY',
            'message' => '请用手机微信扫码完成店员账号登录绑定',
        ];
    }

    /** @return array{status: string, message: string, account_id?: string} */
    public function pollSession(string $sessionId): array
    {
        $session = $this->sessions->findActive($sessionId);
        if ($session === null) {
            return ['status' => 'EXPIRED', 'message' => '会话不存在或已超时，请重新发起'];
        }
        $status = (string) $session['status'];
        if ($status === 'CONFIRMED') {
            return [
                'status' => 'CONFIRMED',
                'account_id' => (string) $session['account_id'],
                'message' => '店员账号登录绑定成功',
            ];
        }
        if ($status === 'FAILED') {
            return ['status' => 'FAILED', 'message' => '登录失败，二维码已过期'];
        }

        $parts = explode('|', (string) $session['qr_url'], 3);
        $appId = $parts[1] ?? '';
        $uuid = $parts[2] ?? '';
        if ($appId === '' || $uuid === '') {
            return ['status' => 'PENDING', 'message' => '等待扫码，请使用手机微信扫描二维码'];
        }
        try {
            $result = $this->gewe->checkLoginStatus($appId, $uuid);
        } catch (\Throwable) {
            return ['status' => 'PENDING', 'message' => 'Gewe 状态查询暂时失败，请稍后再试'];
        }

        return match ((string) ($result['status'] ?? 'WAITING')) {
            'CONFIRMED' => $this->confirm($sessionId, $appId, $result),
            'SCANNED' => ['status' => 'SCANNED', 'message' => '已扫码，请在手机上点击确认登录'],
            'EXPIRED', 'ERROR' => $this->fail($sessionId),
            default => ['status' => 'PENDING', 'message' => '等待扫码，请使用手机微信扫描二维码'],
        };
    }

    /** @param array<string, mixed> $result @return array{status: string, account_id: string, message: string} */
    private function confirm(string $sessionId, string $appId, array $result): array
    {
        $wxid = (string) ($result['wxid'] ?? '');
        $nickname = (string) ($result['nickname'] ?? '');
        $accountId = $wxid !== '' ? $wxid : $appId;
        $this->accounts->save($accountId, $nickname, $appId, 'ONLINE');
        $this->sessions->update($sessionId, 'CONFIRMED', '', $accountId);
        return [
            'status' => 'CONFIRMED',
            'account_id' => $accountId,
            'message' => "微信账号「{$nickname}」登录成功，已绑定为店员接收账号",
        ];
    }

    /** @return array{status: string, message: string} */
    private function fail(string $sessionId): array
    {
        $this->sessions->update($sessionId, 'FAILED');
        return ['status' => 'FAILED', 'message' => '二维码已过期，请重新发起登录'];
    }
}
