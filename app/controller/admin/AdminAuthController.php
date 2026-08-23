<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\AlertNotificationService;
use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;
use support\LoginRateLimiter;

/**
 * 管理员认证、二次验证与会话生命周期控制器
 */
final class AdminAuthController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 管理员登录认证接口（第一阶段：账号+密码）
     *
     * 若系统启用了二次验证码（admin_verify_code_enabled=1），密码验证通过后
     * 返回 code=2，前端需继续调用 POST /api/admin/login/verify 输入静态验证码。
     * 若未启用，直接颁发正式 Token，与旧版行为完全兼容。
     */
    public function login(\support\Request $request): string
    {
        $params   = $request->post();
        $account  = trim((string)($params['account'] ?? ''));
        $password = trim((string)($params['password'] ?? ''));

        if (empty($account) || empty($password)) {
            return json_encode(['code' => -1, 'msg' => '管理员账号与密码不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp() . '|' . strtolower($account);
        if (LoginRateLimiter::tooManyAttempts('admin', $rateLimitId)) {
            return json_encode(['code' => -1, 'msg' => '登录失败次数过多，请5分钟后重试'], JSON_UNESCAPED_UNICODE);
        }

        // 从数据库读取管理员账号和 bcrypt 密码哈希
        $row = DB::table('cx_config')->where('name', 'admin_account')->first();
        $storedAccount = $row ? (string)$row->value : 'admin';

        $rowPwd = DB::table('cx_config')->where('name', 'admin_password_hash')->first();
        $storedHash = $rowPwd ? (string)$rowPwd->value : '';

        // 兼容旧版：若数据库中仍为明文密码，自动迁移为 bcrypt
        if (!empty($storedHash) && !str_starts_with($storedHash, '$2y$')) {
            if ($account !== $storedAccount || $password !== $storedHash) {
                LoginRateLimiter::increment('admin', $rateLimitId);
                return json_encode(['code' => -1, 'msg' => '管理员账号或密码错误'], JSON_UNESCAPED_UNICODE);
            }
            $newHash = password_hash($storedHash, PASSWORD_BCRYPT, ['cost' => 12]);
            DB::table('cx_config')->where('name', 'admin_password_hash')
                ->update(['value' => $newHash, 'title' => '管理员密码Bcrypt哈希']);
            $storedHash = $newHash;
        } elseif (empty($storedHash)) {
            return json_encode(['code' => -1, 'msg' => '系统尚未初始化管理员密码，请联系部署人员'], JSON_UNESCAPED_UNICODE);
        }

        // 校验账号 + bcrypt 密码
        if ($account !== $storedAccount || !password_verify($password, $storedHash)) {
            LoginRateLimiter::increment('admin', $rateLimitId);
            return json_encode(['code' => -1, 'msg' => '管理员账号或密码错误'], JSON_UNESCAPED_UNICODE);
        }

        LoginRateLimiter::clear('admin', $rateLimitId);
        if (password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $storedHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            DB::table('cx_config')->where('name', 'admin_password_hash')->update(['value' => $storedHash]);
        }

        // 检查是否启用二次验证码
        $verifyEnabled = (int)DB::table('cx_config')
            ->where('name', 'admin_verify_code_enabled')->value('value');

        if ($verifyEnabled === 1) {
            // 将"已通过密码验证"状态存入 Session，有效期 5 分钟
            $pendingToken = bin2hex(random_bytes(16));
            $session = $request->session();
            $session->set('admin_pending_login', [
                'account'    => $account,
                'token'      => $pendingToken,
                'expires_at' => time() + 300,
                'ip'         => $request->getRemoteIp(),
            ]);

            return json_encode([
                'code'          => 2,
                'msg'           => '密码验证通过，请输入二次验证码',
                'pending_token' => $pendingToken,
            ], JSON_UNESCAPED_UNICODE);
        }

        // 未启用二次验证，直接颁发正式 Token
        LoginRateLimiter::clear('admin', $rateLimitId);
        return $this->issueAdminToken($request, $account);
    }

    /**
     * 管理员登录第二阶段：验证静态验证码，成功后颁发正式 Token
     *
     * POST /api/admin/login/verify
     * Body: { pending_token: "xxx", verify_code: "123456" }
     */
    public function verifyLoginCode(\support\Request $request): string
    {
        $params       = $request->post();
        $pendingToken = trim((string)($params['pending_token'] ?? ''));
        $inputCode    = trim((string)($params['verify_code'] ?? ''));

        if (empty($pendingToken) || empty($inputCode)) {
            return json_encode(['code' => -1, 'msg' => '验证参数不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $session = $request->session();
        $pending = $session->get('admin_pending_login');

        // 校验 pending 状态：令牌匹配且未过期
        if (empty($pending)
            || !hash_equals((string)($pending['token'] ?? ''), $pendingToken)
            || (int)($pending['expires_at'] ?? 0) < time()
        ) {
            return json_encode(['code' => -1, 'msg' => '验证码已失效，请重新登录'], JSON_UNESCAPED_UNICODE);
        }

        // 验证码失败次数限制（同一 pending 最多 5 次）
        $failKey = 'cx:admin_verify_fail:' . $pendingToken;
        try {
            $redis    = \Webman\Redis\Client::connection();
            $failCnt  = (int)$redis->get($failKey);
            if ($failCnt >= 5) {
                $session->forget('admin_pending_login');
                return json_encode(['code' => -1, 'msg' => '验证码错误次数过多，请重新登录'], JSON_UNESCAPED_UNICODE);
            }
        } catch (\Throwable) {
            $redis   = null;
            $failCnt = 0;
        }

        // 读取并解密存储的静态验证码
        $storedEncrypted = (string)DB::table('cx_config')
            ->where('name', 'admin_verify_code')->value('value');
        $storedCode = '';
        if ($storedEncrypted !== '') {
            try {
                $storedCode = $this->authcode->decryptStored($storedEncrypted);
            } catch (\Throwable) {
                $storedCode = '';
            }
        }

        if ($storedCode === '' || !hash_equals($storedCode, $inputCode)) {
            // 记录失败次数
            if (isset($redis)) {
                $redis->incr($failKey);
                $redis->expire($failKey, 300);
            }
            return json_encode(['code' => -1, 'msg' => '验证码错误'], JSON_UNESCAPED_UNICODE);
        }

        // 验证通过，清除 pending 状态，颁发正式 Token
        $account = (string)($pending['account'] ?? '');
        $session->forget('admin_pending_login');
        if (isset($redis)) {
            $redis->del($failKey);
        }

        $rateLimitId = $request->getRemoteIp() . '|' . strtolower($account);
        LoginRateLimiter::clear('admin', $rateLimitId);

        return $this->issueAdminToken($request, $account);
    }

    /**
     * 颁发正式管理员 Token 并写入 Session（密码验证或验证码验证通过后调用）
     */
    private function issueAdminToken(\support\Request $request, string $account): string
    {
        $tokenSalt = (string)DB::table('cx_config')->where('name', 'token_salt')->value('value');
        if (strlen($tokenSalt) < 32) {
            return json_encode(['code' => -1, 'msg' => '系统 Token 盐未安全初始化，请重新执行安装配置'], JSON_UNESCAPED_UNICODE);
        }

        // Token 版本号：密码修改后版本号递增，旧 Token 自动失效
        $tokenVersion = (int)(DB::table('cx_config')->where('name', 'admin_token_version')->value('value') ?: 1);
        $tokenExpire  = time() + 7200; // 2小时有效期
        $tokenRaw     = $account . '|' . $tokenExpire . '|v' . $tokenVersion;
        $tokenSign    = hash_hmac('sha256', $tokenRaw, $tokenSalt);
        $token        = base64_encode($tokenRaw . '|' . $tokenSign);

        $session = $request->session();
        $session->set('admin_info', [
            'username'      => $account,
            'login_time'    => time(),
            'token_expire'  => $tokenExpire,
            'token_version' => $tokenVersion,
            'role'          => 'root',
        ]);
        $request->sessionRegenerateId(true);

        // 异步派发管理员登录通知
        try {
            (new AlertNotificationService())->dispatchAdmin('admin_login', [
                'ip' => $request->getRemoteIp(),
            ]);
        } catch (\Throwable) {
        }

        return json_encode([
            'code' => 1,
            'msg'  => '登录验证成功！正在跳转总控台...',
            'data' => [
                'token'    => $token,
                'username' => $account,
                'expire'   => $tokenExpire,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 管理员退出登录
     *
     * 同时将当前 Bearer Token 写入 Redis 黑名单（cx:token_bl:*），
     * TTL = Token 剩余有效期，确保旧 Token 在自然过期前不可复用。
     */
    public function logout(\support\Request $request): string
    {
        $session   = $request->session();
        $adminInfo = $session->get('admin_info');

        // 将当前 Token 加入黑名单（仅处理 Bearer 无状态 Token 场景）
        $rawToken = $request->header('authorization') ?? '';
        $rawToken = trim(str_ireplace('Bearer ', '', trim((string)$rawToken)));
        if ($rawToken !== '' && !empty($adminInfo['token_expire'])) {
            $ttl = max(1, (int)$adminInfo['token_expire'] - time());
            try {
                \Webman\Redis\Client::connection()->setex(
                    'cx:token_bl:' . hash('sha256', $rawToken),
                    $ttl,
                    '1'
                );
            } catch (\Throwable) {
                // 黑名单写入失败不阻断退出流程
            }
        }

        $session->forget('admin_info');
        $request->sessionRegenerateId(true);

        return json_encode(['code' => 1, 'msg' => '已成功退出登录'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 颁发一次性 APK / 源码下载授权 Token
     *
     * POST /api/admin/download/token
     * 仅限已登录管理员调用；Token 写入 Redis，TTL 300s，消费后立即删除。
     * 前端使用方式：/download/CXPayAssistant_latest.apk?dl_token={token}
     */
    public function issueDownloadToken(\support\Request $request): string
    {
        $ttl      = 300;
        $rawToken = bin2hex(random_bytes(24)); // 48 字符随机 token
        $redisKey = 'cx:dl_token:' . hash('sha256', $rawToken);

        $adminInfo = $request->session()->get('admin_info');
        $meta = json_encode([
            'issued_by' => $adminInfo['username'] ?? 'unknown',
            'issued_at' => time(),
            'ip'        => $request->getRemoteIp(),
        ], JSON_UNESCAPED_UNICODE);

        try {
            \Webman\Redis\Client::connection()->setex($redisKey, $ttl, $meta);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => 'Redis 服务异常，无法颁发下载凭据'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'code' => 1,
            'msg'  => '下载凭据已生成，有效期 ' . $ttl . ' 秒',
            'data' => [
                'dl_token'   => $rawToken,
                'expires_in' => $ttl,
                'download_urls' => [
                    'apk_latest' => '/download/CXPayAssistant_latest.apk?dl_token=' . $rawToken,
                    'apk'        => '/download/CXPayAssistant.apk?dl_token=' . $rawToken,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Token 滑动续期接口（P2）
     *
     * GET /api/admin/token/refresh
     * 需携带有效 Bearer Token（由 AdminAuthMiddleware 验证）。
     * 旧 Token 立即加入黑名单，颁发新 Token（重置 2h 有效期）。
     * 前端应在 Token 到期前 10 分钟主动调用。
     */
    public function refreshToken(\support\Request $request): string
    {
        $adminInfo = $request->context['admin_info'] ?? $request->session()->get('admin_info');
        if (empty($adminInfo)) {
            return json_encode(['code' => -1, 'msg' => '未登录或会话已失效'], JSON_UNESCAPED_UNICODE);
        }

        // 将旧 Token 写入黑名单
        $oldToken = trim(str_ireplace('Bearer ', '', trim((string)($request->header('authorization') ?? ''))));
        if ($oldToken !== '' && !empty($adminInfo['token_expire'])) {
            $ttl = max(1, (int)$adminInfo['token_expire'] - time());
            try {
                \Webman\Redis\Client::connection()->setex(
                    'cx:token_bl:' . hash('sha256', $oldToken),
                    $ttl,
                    '1'
                );
            } catch (\Throwable) {}
        }

        // 颁发新 Token
        $tokenSalt = (string)DB::table('cx_config')->where('name', 'token_salt')->value('value');
        if (strlen($tokenSalt) < 32) {
            return json_encode(['code' => -1, 'msg' => 'Token 盐配置异常'], JSON_UNESCAPED_UNICODE);
        }
        $tokenVersion  = (int)(DB::table('cx_config')->where('name', 'admin_token_version')->value('value') ?: 1);
        $username      = (string)($adminInfo['username'] ?? '');
        $role          = (string)($adminInfo['role'] ?? 'root');
        $tokenExpire   = time() + 7200;
        $accountStr    = ($role !== 'root') ? 'sub:' . $username : $username;
        $tokenRaw      = $accountStr . '|' . $tokenExpire . '|v' . $tokenVersion;
        $newToken      = base64_encode($tokenRaw . '|' . hash_hmac('sha256', $tokenRaw, $tokenSalt));

        // 更新 Session
        $newAdminInfo = array_merge($adminInfo, [
            'token_expire'  => $tokenExpire,
            'token_version' => $tokenVersion,
        ]);
        $request->session()->set('admin_info', $newAdminInfo);

        return json_encode([
            'code' => 1,
            'msg'  => 'Token 已刷新',
            'data' => [
                'token'      => $newToken,
                'expire'     => $tokenExpire,
                'expires_in' => 7200,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
