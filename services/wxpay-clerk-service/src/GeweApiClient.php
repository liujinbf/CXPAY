<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

/**
 * gewe 微信客户端 API 适配器。
 *
 * gewe 项目：https://github.com/Devo919/Gewechat
 * API 文档：http://gewe-api.apifox.cn/
 *
 * 本类封装了自动配置流程所需的最小 API 集：
 * - 获取登录二维码（用于商户扫码绑定店员）
 * - 轮询登录状态
 * - 查询账号在线状态
 * - 退出登录
 */
final class GeweApiClient
{
    private const TIMEOUT         = 10;
    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly string $geweApiUrl,
        private readonly string $geweApiToken = ''
    ) {}

    /**
     * 创建新的 App 实例并获取登录二维码。
     *
     * @return array{appid: string, qr_url: string, uuid: string}
     */
    public function createLoginSession(): array
    {
        // Step 1：获取或创建 App 实例
        $app = $this->post('/v2/api/login/getLoginQrCode', [
            'appId' => '', // 留空让 gewe 自动分配新 appId
        ]);

        $appId  = (string)($app['data']['appId']  ?? '');
        $qrUrl  = (string)($app['data']['qrImgBase64'] ?? $app['data']['qrUrl'] ?? '');
        $uuid   = (string)($app['data']['uuid']   ?? '');

        if ($appId === '' || $qrUrl === '') {
            throw new RuntimeException('gewe 未返回有效的登录二维码');
        }

        return ['appid' => $appId, 'qr_url' => $qrUrl, 'uuid' => $uuid];
    }

    /**
     * 轮询扫码登录状态。
     *
     * @return array{status: string, wxid?: string, nickname?: string}
     *   status: WAITING | SCANNED | CONFIRMED | EXPIRED | ERROR
     */
    public function checkLoginStatus(string $appId, string $uuid): array
    {
        $resp = $this->post('/v2/api/login/checkLogin', [
            'appId' => $appId,
            'uuid'  => $uuid,
        ]);

        $code     = (int)($resp['data']['status']   ?? 0);
        $wxid     = (string)($resp['data']['wxid']    ?? '');
        $nickname = (string)($resp['data']['nickName'] ?? '');

        $status = match ($code) {
            0       => 'WAITING',
            1       => 'SCANNED',
            2       => 'CONFIRMED',
            400, 50 => 'EXPIRED',
            default => 'ERROR',
        };

        return array_filter(['status' => $status, 'wxid' => $wxid, 'nickname' => $nickname]);
    }

    /**
     * 查询账号是否在线（心跳检测）。
     *
     * @return array{online: bool, nickname: string}
     */
    public function getAccountStatus(string $appId): array
    {
        try {
            $resp = $this->post('/v2/api/login/isOnline', ['appId' => $appId]);
            $online = (bool)($resp['data'] ?? false);
            return ['online' => $online, 'nickname' => ''];
        } catch (RuntimeException) {
            return ['online' => false, 'nickname' => ''];
        }
    }

    /**
     * 退出指定 App 实例的登录状态。
     */
    public function logout(string $appId): void
    {
        try {
            $this->post('/v2/api/login/logout', ['appId' => $appId]);
        } catch (RuntimeException) {
            // 忽略退出失败
        }
    }

    /**
     * 设置消息推送回调地址（告知 gewe 将消息 Webhook 到本服务）。
     */
    public function setCallback(string $appId, string $callbackUrl): void
    {
        $this->post('/v2/api/setCallback', [
            'appId'       => $appId,
            'callbackUrl' => $callbackUrl,
        ]);
    }

    // ─── 内部 HTTP 方法 ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $url  = rtrim($this->geweApiUrl, '/') . $path;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->geweApiToken !== '') {
            $headers[] = 'X-GEWE-TOKEN: ' . $this->geweApiToken;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false, // gewe 内网部署通常无 TLS
        ]);

        $resp    = (string)curl_exec($ch);
        $errMsg  = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errMsg !== '') {
            throw new RuntimeException("gewe API 连接失败 [{$path}]: {$errMsg}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("gewe API HTTP {$httpCode} [{$path}]");
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException("gewe API 响应格式错误 [{$path}]");
        }
        // gewe 返回 ret=200 表示成功
        if ((int)($data['ret'] ?? 0) !== 200) {
            $msg = (string)($data['msg'] ?? '未知错误');
            throw new RuntimeException("gewe API 业务错误 [{$path}]: {$msg}");
        }

        return $data;
    }
}
