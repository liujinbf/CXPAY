<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Merchant;
use support\Authcode;
use support\Db;
use support\Request;
use support\Response;
use Throwable;
use Webman\Redis\Client as RedisClient;

/**
 * 支付宝官方 AppAuth 扫码代授权控制器
 *
 * 基于支付宝开放平台「第三方应用代商户授权 (AppAuth)」协议，
 * 商户手机支付宝扫码点同意后，系统自动换取 app_auth_token 并自动创建/激活当面付直连通道。
 */
class AlipayProtocolAdminController
{
    /**
     * 1. 发起扫码授权：生成官方授权链接与二维码
     */
    public function startAuth(Request $request): Response
    {
        try {
            $merchantId = 0;
            $isAdmin = false;
            try {
                $session = $request->session();
                $merchantId = (int)($session?->get('merchant_id') ?? 0);
                if ($session?->get('admin_info')) {
                    $isAdmin = true;
                }
            } catch (\Throwable) {}

            if (!$isAdmin) {
                $authHeader = (string)($request->header('authorization') ?? '');
                if (!empty($authHeader)) {
                    $token = str_ireplace('Bearer ', '', trim($authHeader));
                    try {
                        $authcode = new Authcode();
                        $decrypted = $authcode->decrypt($token);
                        if (!empty($decrypted) && str_contains($decrypted, 'admin')) {
                            $isAdmin = true;
                        }
                    } catch (\Throwable) {}
                }
            }

            if ($isAdmin) {
                $merchantId = 0;
            } elseif ($merchantId <= 0) {
                $merchantId = (int)($request->get('merchant_id') ?? $request->post('merchant_id') ?? 0);
                if ($merchantId <= 0) {
                    return json(['code' => 401, 'msg' => '请先登录后台']);
                }
            }

            $isvConfig = $this->getIsvConfig();
            $isvAppId = trim((string)($isvConfig['app_id'] ?? ''));
            // 若未配置自备私有 ISV，自动切换为云端总控母站 ISV 网关托管模式（全网代理商免资质直连）
            if ($isvAppId === '') {
                return $this->startCloudIsvAuth($merchantId);
            }

            // 生成唯一授权状态 State
            $state = 'ali_auth_' . bin2hex(random_bytes(12));
            $stateData = [
                'merchant_id' => $merchantId,
                'created_at'  => time(),
                'status'      => 'PENDING',
            ];

            // 存入 Redis 缓存 10 分钟 (600s)
            $this->setAuthState($state, $stateData, 600);

            // 构建支付宝官方授权 URL
            $baseUrl = $this->getBaseUrl($request);
            $redirectUri = $baseUrl . '/api/alipay/auth/callback';
            $authUrl = "https://openauth.alipay.com/oauth2/appToAppAuth.htm?app_id={$isvAppId}&redirect_uri=" . urlencode($redirectUri) . "&state={$state}";

            return json([
                'code' => 1,
                'msg'  => '获取授权二维码成功',
                'data' => [
                    'state'    => $state,
                    'auth_url' => $authUrl,
                    'qr_url'   => $authUrl, // 前端可通过 qrcode.js 直接渲染或生成
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '生成授权二维码失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 别名路由：getLoginQr
     */
    public function getLoginQr(Request $request): Response
    {
        return $this->startAuth($request);
    }

    /**
     * 2. 轮询授权状态 (前端 2 秒长轮询)
     */
    public function pollAuth(Request $request): Response
    {
        try {
            $state = trim((string)($request->get('state') ?? $request->post('state') ?? ''));
            if ($state === '') {
                return json(['code' => -1, 'msg' => '缺少授权 state 参数']);
            }

            $stateData = $this->getAuthState($state);
            if (!$stateData) {
                return json(['code' => -1, 'status' => 'EXPIRED', 'msg' => '授权二维码已过期，请刷新重新获取']);
            }

            // 若该会话属于云端总控母站托管模式，向云端母站轮询确权状态
            if (!empty($stateData['is_cloud_isv']) && ($stateData['status'] ?? '') !== 'SUCCESS') {
                return $this->pollCloudIsvAuth($state, $stateData);
            }

            $status = (string)($stateData['status'] ?? 'PENDING');
            if ($status === 'SUCCESS') {
                return json([
                    'code'   => 1,
                    'status' => 'SUCCESS',
                    'msg'    => '🎉 支付宝当面付已成功授权并激活！',
                    'data'   => [
                        'channel_id' => $stateData['channel_id'] ?? 0,
                        'seller_id'  => $stateData['seller_id'] ?? '',
                    ],
                ]);
            }

            if ($status === 'FAILED') {
                return json([
                    'code'   => -1,
                    'status' => 'FAILED',
                    'msg'    => '授权失败: ' . ($stateData['error'] ?? '商户取消了授权'),
                ]);
            }

            return json([
                'code'   => 0,
                'status' => 'PENDING',
                'msg'    => '等待手机支付宝扫码确认中...',
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '轮询异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 别名路由：pollQr
     */
    public function pollQr(Request $request): Response
    {
        return $this->pollAuth($request);
    }

    /**
     * 3. 支付宝官方授权回调 (用户在手机支付宝点击「同意授权」后跳转)
     */
    public function callback(Request $request): Response
    {
        $params = $request->get() + $request->post();
        $appAuthCode = trim((string)($params['app_auth_code'] ?? ''));
        $state = trim((string)($params['state'] ?? ''));

        if ($appAuthCode === '' || $state === '') {
            return response($this->renderHtmlResult(false, '授权失败：未收到有效的 app_auth_code 凭证'));
        }

        $stateData = $this->getAuthState($state);
        if (!$stateData) {
            return response($this->renderHtmlResult(false, '授权会话已过期，请返回商户后台重新扫码'));
        }

        $merchantId = (int)($stateData['merchant_id'] ?? 0);
        $isvConfig = $this->getIsvConfig();

        try {
            // 调用 alipay.open.auth.token.app 换取 app_auth_token
            $tokenResult = $this->exchangeAuthToken($appAuthCode, $isvConfig);
            $appAuthToken = (string)($tokenResult['app_auth_token'] ?? '');
            $appRefreshToken = (string)($tokenResult['app_refresh_token'] ?? '');
            $authAppId = (string)($tokenResult['auth_app_id'] ?? '');
            $userId = (string)($tokenResult['user_id'] ?? $tokenResult['open_id'] ?? '');

            if ($appAuthToken === '') {
                throw new \RuntimeException('换取商户授权令牌失败: 未返回 app_auth_token');
            }

            // 自动为商户创建/更新当面付通道
            $authcode = new Authcode();
            $channelConfig = [
                'app_auth_token'    => $appAuthToken,
                'app_refresh_token' => $appRefreshToken,
                'auth_app_id'       => $authAppId,
                'seller_id'         => $userId,
                'isv_mode'          => true,
                'authorized_at'     => date('Y-m-d H:i:s'),
            ];

            // 敏感配置使用 AES-256 加密保存
            $encryptedConfig = [];
            foreach ($channelConfig as $k => $v) {
                $encryptedConfig[$k] = is_string($v) ? $authcode->encrypt($v) : $v;
            }

            // 查找该商户是否已有当面付通道
            $channel = Channel::where('merchant_id', $merchantId)
                ->where('c_type', 'alipay_face_pay')
                ->first();

            $shortPid = substr($userId, -4);
            $title = "支付宝当面付 (扫码直连-尾号{$shortPid})";

            if ($channel) {
                $channel->title = $title;
                $channel->status = 1;
                $channel->online_status = 1;
                $channel->online_since = time();
                $channel->last_heartbeat_time = time();
                $channel->config = json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE);
                $channel->save();
            } else {
                $channel = Channel::create([
                    'merchant_id'         => $merchantId,
                    'title'               => $title,
                    'c_type'              => 'alipay_face_pay',
                    'pay_category'        => 'alipay',
                    'status'              => 1,
                    'online_status'       => 1,
                    'online_since'        => time(),
                    'last_heartbeat_time' => time(),
                    'weight'              => 100,
                    'single_min'          => '0.01',
                    'single_max'          => '0.00',
                    'day_max'             => '0.00',
                    'today_money'         => '0.00',
                    'config'              => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
                    'create_time'         => time(),
                ]);
            }

            // 标记状态为成功
            $stateData['status'] = 'SUCCESS';
            $stateData['channel_id'] = $channel->id;
            $stateData['seller_id'] = $userId;
            $this->setAuthState($state, $stateData, 600);

            return response($this->renderHtmlResult(true, "恭喜！支付宝当面付已成功授权绑定（商户 PID: {$userId}），您可以关闭此页面返回商户中心。"));
        } catch (Throwable $e) {
            $stateData['status'] = 'FAILED';
            $stateData['error'] = $e->getMessage();
            $this->setAuthState($state, $stateData, 600);

            return response($this->renderHtmlResult(false, '授权绑定失败: ' . $e->getMessage()));
        }
    }

    public function authPage(Request $request): Response
    {
        return $this->startAuth($request);
    }

    public function confirmAuth(Request $request): Response
    {
        return $this->callback($request);
    }

    /**
     * 调用支付宝 OpenAPI 换取 app_auth_token
     */
    private function exchangeAuthToken(string $appAuthCode, array $isvConfig): array
    {
        $appId = trim((string)($isvConfig['app_id'] ?? ''));
        $privateKey = trim((string)($isvConfig['private_key'] ?? ''));

        if ($appId === '' || $privateKey === '') {
            throw new \RuntimeException('平台未配置 ISV 主应用 APPID 或私钥');
        }

        $sysParams = [
            'app_id'      => $appId,
            'method'      => 'alipay.open.auth.token.app',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s'),
            'version'     => '1.0',
            'biz_content' => json_encode([
                'grant_type' => 'authorization_code',
                'code'       => $appAuthCode,
            ], JSON_UNESCAPED_UNICODE),
        ];

        $sign = $this->rsaSign($sysParams, $privateKey);
        $sysParams['sign'] = $sign;

        $ch = curl_init('https://openapi.alipay.com/gateway.do?charset=utf-8');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($sysParams),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        $json = json_decode((string)$raw, true);
        $resp = $json['alipay_open_auth_token_app_response'] ?? $json['error_response'] ?? null;

        if (!is_array($resp) || ($resp['code'] ?? '') !== '10000') {
            $msg = $resp['sub_msg'] ?? $resp['msg'] ?? '调用支付宝接口换取令牌失败';
            throw new \RuntimeException($msg);
        }

        return $resp;
    }

    /**
     * 获取平台全局 ISV 配置
     */
    private function getIsvConfig(): array
    {
        // 1. 尝试从 cx_config 数据库配置读取
        try {
            $dbConfigs = Db::table('cx_config')->whereIn('name', [
                'alipay_isv_app_id',
                'alipay_isv_private_key',
                'alipay_isv_public_key',
                'alipay_app_id',
                'alipay_private_key',
            ])->pluck('value', 'name')->toArray();

            $appId = $dbConfigs['alipay_isv_app_id'] ?? $dbConfigs['alipay_app_id'] ?? env('ALIPAY_ISV_APP_ID', env('ALIPAY_APP_ID', ''));
            $privateKey = $dbConfigs['alipay_isv_private_key'] ?? $dbConfigs['alipay_private_key'] ?? env('ALIPAY_ISV_PRIVATE_KEY', env('ALIPAY_PRIVATE_KEY', ''));
            $publicKey = $dbConfigs['alipay_isv_public_key'] ?? env('ALIPAY_ISV_PUBLIC_KEY', env('ALIPAY_PUBLIC_KEY', ''));

            return [
                'app_id'      => (string)$appId,
                'private_key' => (string)$privateKey,
                'public_key'  => (string)$publicKey,
            ];
        } catch (\Throwable) {
            return [
                'app_id'      => (string)env('ALIPAY_ISV_APP_ID', env('ALIPAY_APP_ID', '')),
                'private_key' => (string)env('ALIPAY_ISV_PRIVATE_KEY', env('ALIPAY_PRIVATE_KEY', '')),
                'public_key'  => (string)env('ALIPAY_ISV_PUBLIC_KEY', env('ALIPAY_PUBLIC_KEY', '')),
            ];
        }
    }

    private function rsaSign(array $params, string $privateKey): string
    {
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($k !== 'sign' && $v !== '' && !is_null($v)) {
                $signStr .= "{$k}={$v}&";
            }
        }
        $signStr = rtrim($signStr, '&');

        $cleanKey = str_replace(["\r", "\n", ' ', '-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', '-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'], '', $privateKey);
        $pem = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($cleanKey, 64, "\n") . "-----END RSA PRIVATE KEY-----";

        $res = openssl_pkey_get_private($pem);
        if (!$res) {
            $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split($cleanKey, 64, "\n") . "-----END PRIVATE KEY-----";
            $res = openssl_pkey_get_private($pem);
        }

        if (!$res) {
            throw new \RuntimeException('应用私钥格式非法，无法加载 RSA2 密钥');
        }

        openssl_sign($signStr, $binarySign, $res, OPENSSL_ALGO_SHA256);
        return base64_encode($binarySign);
    }

    private function setAuthState(string $state, array $data, int $ttl = 600): void
    {
        try {
            if (class_exists(RedisClient::class)) {
                $conn = RedisClient::connection();
                if ($conn !== null) {
                    $conn->setex('cx:alipay_auth:' . $state, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
                    return;
                }
            }
        } catch (\Throwable) {}

        @file_put_contents(sys_get_temp_dir() . '/ali_auth_' . md5($state) . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function getAuthState(string $state): ?array
    {
        try {
            if (class_exists(RedisClient::class)) {
                $conn = RedisClient::connection();
                if ($conn !== null) {
                    $json = $conn->get('cx:alipay_auth:' . $state);
                    if ($json) {
                        return json_decode($json, true);
                    }
                }
            }
        } catch (\Throwable) {}

        $f = sys_get_temp_dir() . '/ali_auth_' . md5($state) . '.json';
        if (file_exists($f)) {
            $c = @file_get_contents($f);
            return json_decode((string)$c, true);
        }

        return null;
    }

    private function getBaseUrl(Request $request): string
    {
        $configured = (string)config('app.url', '');
        if (filter_var($configured, FILTER_VALIDATE_URL)) {
            return rtrim($configured, '/');
        }
        $forwarded = strtolower((string)$request->header('x-forwarded-proto'));
        $scheme = in_array($forwarded, ['http', 'https'], true)
            ? $forwarded
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        return $scheme . '://' . $request->host();
    }

    private function renderHtmlResult(bool $success, string $message): string
    {
        $icon = $success ? '🎉' : '❌';
        $title = $success ? '支付宝当面付授权成功' : '授权未完成';
        $color = $success ? '#10b981' : '#ef4444';

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} - CXPAY</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 20px; padding: 36px 28px; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); }
        .icon { font-size: 54px; margin-bottom: 16px; }
        h2 { font-size: 20px; font-weight: 700; margin: 0 0 12px; color: #fff; }
        p { font-size: 14px; color: #94a3b8; line-height: 1.6; margin: 0 0 24px; word-break: break-all; }
        .btn { display: inline-block; width: 100%; padding: 12px 0; background: {$color}; color: #fff; text-decoration: none; border-radius: 12px; font-weight: 600; font-size: 14px; box-sizing: border-box; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{$icon}</div>
        <h2>{$title}</h2>
        <p>{$message}</p>
        <a href="javascript:window.close();" class="btn">关闭当前窗口</a>
    </div>
    <script>
        // 若在弹窗中打开，延迟2秒自动通知父窗口并关闭
        if (window.opener) {
            setTimeout(function() {
                try { window.opener.location.reload(); } catch(e) {}
                window.close();
            }, 2500);
        }
    </script>
</body>
</html>
HTML;
    }

    /**
     * 调用云端总控母站网关发起代授权 (全网代理商共享免资质托管模式)
     */
    private function startCloudIsvAuth(int $merchantId): Response
    {
        $cloudBase = $this->resolveCloudGatewayUrl();
        $startUrl = rtrim($cloudBase, '/') . '/api/gateway/v1/alipay-isv/start-auth';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $startUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['instance_id' => 'cxpay_instance', 'merchant_id' => $merchantId], JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string)$resp, true);

        if ($httpCode === 200 && !empty($json['code']) && !empty($json['data']['state'])) {
            $data = $json['data'];
            $state = (string)$data['state'];
            $authUrl = (string)$data['auth_url'];
            $expireSec = (int)($data['expire_seconds'] ?? 600);

            $stateData = [
                'merchant_id'  => $merchantId,
                'created_at'   => time(),
                'status'       => 'PENDING',
                'is_cloud_isv' => true,
            ];
            $this->setAuthState($state, $stateData, $expireSec);

            return json([
                'code' => 1,
                'msg'  => '获取授权二维码成功（云端总控免资质托管）',
                'data' => [
                    'state'    => $state,
                    'auth_url' => $authUrl,
                    'qr_url'   => $authUrl,
                ],
            ]);
        }

        $msg = $json['msg'] ?? '云端支付宝 ISV 网关连接异常';
        return json([
            'code' => -1,
            'msg'  => '📢 支付宝代授权服务提示：' . $msg . '（若自备服务商应用也可在系统后台配置私有凭证使用）。',
        ]);
    }

    /**
     * 轮询云端总控母站支付宝 ISV 网关的确权状态
     */
    private function pollCloudIsvAuth(string $state, array $stateData): Response
    {
        $merchantId = (int)($stateData['merchant_id'] ?? 0);
        $cloudBase = $this->resolveCloudGatewayUrl();
        $pollUrl = rtrim($cloudBase, '/') . '/api/gateway/v1/alipay-isv/poll-auth?state=' . urlencode($state);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $pollUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $json = json_decode((string)$resp, true);
        if (is_array($json) && !empty($json['data'])) {
            $status = (string)($json['data']['status'] ?? '');
            if ($status === 'success' && !empty($json['data']['app_auth_token'])) {
                $appAuthToken = (string)$json['data']['app_auth_token'];
                $userId = (string)($json['data']['user_id'] ?? '');
                $authAppId = (string)($json['data']['auth_app_id'] ?? '');

                // 绑定商户当面付通道
                $channel = $this->bindMerchantChannel($merchantId, $appAuthToken, '', $authAppId, $userId);

                $stateData['status'] = 'SUCCESS';
                $stateData['channel_id'] = $channel->id;
                $stateData['seller_id'] = $userId;
                $this->setAuthState($state, $stateData, 600);

                return json([
                    'code'   => 1,
                    'status' => 'SUCCESS',
                    'msg'    => '🎉 支付宝当面付已通过官方云网关成功授权并激活！',
                    'data'   => [
                        'channel_id' => $channel->id,
                        'seller_id'  => $userId,
                    ],
                ]);
            } elseif ($status === 'expired') {
                return json(['code' => -1, 'status' => 'EXPIRED', 'msg' => '授权二维码已过期，请刷新重新获取']);
            }
        }

        return json([
            'code'   => 0,
            'status' => 'PENDING',
            'msg'    => '等待手机支付宝扫码确认中（全网免资质托管）...',
        ]);
    }

    /**
     * 自动为商户创建/更新当面付直连通道
     */
    private function bindMerchantChannel(int $merchantId, string $appAuthToken, string $appRefreshToken, string $authAppId, string $userId): Channel
    {
        $authcode = new Authcode();
        $channelConfig = [
            'app_auth_token'    => $appAuthToken,
            'app_refresh_token' => $appRefreshToken,
            'auth_app_id'       => $authAppId,
            'seller_id'         => $userId,
            'isv_mode'          => true,
            'authorized_at'     => date('Y-m-d H:i:s'),
        ];

        $encryptedConfig = [];
        foreach ($channelConfig as $k => $v) {
            $encryptedConfig[$k] = is_string($v) ? $authcode->encrypt($v) : $v;
        }

        $channel = Channel::where('merchant_id', $merchantId)
            ->where('c_type', 'alipay_face_pay')
            ->first();

        $shortPid = substr($userId, -4);
        $title = "支付宝当面付 (扫码直连-尾号{$shortPid})";

        if ($channel) {
            $channel->title = $title;
            $channel->status = 1;
            $channel->online_status = 1;
            $channel->online_since = time();
            $channel->last_heartbeat_time = time();
            $channel->config = json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE);
            $channel->save();
        } else {
            $channel = Channel::create([
                'merchant_id'         => $merchantId,
                'title'               => $title,
                'c_type'              => 'alipay_face_pay',
                'pay_category'        => 'alipay',
                'status'              => 1,
                'online_status'       => 1,
                'online_since'        => time(),
                'last_heartbeat_time' => time(),
                'weight'              => 100,
                'single_min'          => '0.01',
                'single_max'          => '0.00',
                'day_max'             => '0.00',
                'today_money'         => '0.00',
                'config'              => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
                'create_time'         => time(),
            ]);
        }

        return $channel;
    }

    private function resolveCloudGatewayUrl(): string
    {
        if (function_exists('env')) {
            $envUrl = env('CLOUD_CONTROL_PLANE_URL');
            if (!empty($envUrl)) {
                return (string)$envUrl;
            }
        }
        return 'https://ops.cloud.fcwan.cn';
    }
}
