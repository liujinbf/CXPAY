<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 管理员后台与 API 通用 Session/Token 身份验证中间件
 *
 * 支持两类管理员账号：
 *   1. 超级管理员（cx_config 中的 admin 账号，role=root）— 全权限，不受 RBAC 约束
 *   2. 子管理员（cx_admin 表，role=operator/finance/support）— 受 cx_admin_permission 白名单约束
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!$this->isSameOrigin($request)) {
            return $this->unauthorized($request, '请求来源校验失败');
        }
        $session   = $request->session();
        $adminInfo = $session->get('admin_info');

        // Session 未登录时，尝试解析 Bearer Token 进行无状态校验
        if (!$adminInfo) {
            $rawToken = $request->header('authorization') ?? '';
            // 兼容 "Bearer xxx" 格式
            $rawToken = str_ireplace('Bearer ', '', trim((string)$rawToken));

            if (!empty($rawToken)) {
                $adminInfo = $this->verifyToken($rawToken);
                if (!$adminInfo) {
                    return $this->unauthorized($request, 'Token 签名无效或已过期，请重新登录');
                }
                // 将解析成功的 adminInfo 写回 Session，延长会话
                $session->set('admin_info', $adminInfo);
            } else {
                // 既无 Session 也无 Token
                return $this->unauthorized($request, '管理员未登录，请先登录');
            }
        }

        // Session 有效时，额外检查过期时间
        $tokenExpire = (int)($adminInfo['token_expire'] ?? 0);
        if ($tokenExpire > 0 && time() > $tokenExpire) {
            $session->forget('admin_info');
            return $this->unauthorized($request, '登录状态已过期，请重新登录');
        }

        // RBAC 权限检查（root 角色跳过）
        $role = (string)($adminInfo['role'] ?? 'root');
        if ($role !== 'root') {
            if (!$this->checkPermission($role, $request->method(), $request->path())) {
                return response(
                    json_encode(['code' => 403, 'msg' => '权限不足，当前角色无法执行此操作'], JSON_UNESCAPED_UNICODE),
                    403,
                    ['Content-Type' => 'application/json; charset=utf-8']
                );
            }
        }

        // 将当前管理员信息注入 request->context，便于控制器读取
        $request->context['admin_info'] = $adminInfo;

        return $handler($request);
    }

    /**
     * 检查角色是否有权访问指定路径
     * 从 cx_admin_permission 表前缀匹配（有缓存保护）
     */
    protected function checkPermission(string $role, string $method, string $path): bool
    {
        $cacheKey = 'cx:admin_perm:' . $role;
        $perms    = null;

        try {
            $redis  = \Webman\Redis\Client::connection();
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $perms = json_decode($cached, true);
            }
        } catch (\Throwable) {
        }

        if (!is_array($perms)) {
            try {
                $rows  = DB::table('cx_admin_permission')->where('role', $role)->get();
                $perms = $rows->map(fn($r) => [
                    'path_prefix' => (string)$r->path_prefix,
                    'method'      => strtoupper((string)$r->method),
                ])->all();

                // 权限缓存 60 秒（避免每次请求都查库）
                if (isset($redis)) {
                    $redis->setex($cacheKey, 60, json_encode($perms, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable) {
                // 数据库不可用时 fail-open（不拦截），避免权限表故障封锁所有操作
                return true;
            }
        }

        $upperMethod = strtoupper($method);
        foreach ($perms as $perm) {
            $methodOk = $perm['method'] === '*' || $perm['method'] === $upperMethod;
            if ($methodOk && str_starts_with($path, $perm['path_prefix'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析并校验 HMAC-SHA256 签名 Token
     */
    protected function verifyToken(string $token): ?array
    {
        try {
            $decoded = base64_decode($token, true);
            if ($decoded === false) {
                return null;
            }

            $parts = explode('|', $decoded);
            // 新格式：account|expire|vN|sign（4段）
            // 旧格式：account|expire|sign（3段，向后兼容）
            if (count($parts) === 4) {
                [$account, $expireStr, $versionStr, $sign] = $parts;
                $tokenVersion = (int)ltrim($versionStr, 'v');
            } elseif (count($parts) === 3) {
                [$account, $expireStr, $sign] = $parts;
                $tokenVersion = 0; // 旧格式不带版本号
            } else {
                return null;
            }

            $expire = (int)$expireStr;
            if (time() > $expire) {
                return null;
            }

            // 取服务端盐并重算签名
            $tokenSalt = (string)DB::table('cx_config')->where('name', 'token_salt')->value('value');
            if (strlen($tokenSalt) < 32) {
                return null;
            }
            // 重建原始 tokenRaw（不含签名段）
            $tokenRaw = count($parts) === 4
                ? $account . '|' . $expireStr . '|' . $versionStr
                : $account . '|' . $expireStr;
            $expectedSign = hash_hmac('sha256', $tokenRaw, $tokenSalt);

            if (!hash_equals($expectedSign, $sign)) {
                return null;
            }

            // 版本号校验：如果数据库有版本号记录，且 Token 携带的版本号小于当前版本，拒绝
            if ($tokenVersion > 0) {
                $currentVersion = (int)(DB::table('cx_config')
                    ->where('name', 'admin_token_version')->value('value') ?: 1);
                if ($tokenVersion < $currentVersion) {
                    return null; // Token 已被密码修改操作失效
                }
            }

            // 判断是子管理员 Token 还是超级管理员 Token
            // 子管理员 Token 前缀约定为 "sub:{username}"
            $role = 'root';
            if (str_starts_with($account, 'sub:')) {
                $subUsername = substr($account, 4);
                $subAdmin    = DB::table('cx_admin')
                    ->where('username', $subUsername)
                    ->where('status', 1)
                    ->first();
                if (!$subAdmin) {
                    return null;
                }
                $role    = (string)$subAdmin->role;
                $account = $subUsername;
            }

            return [
                'username'     => $account,
                'token_expire' => $expire,
                'role'         => $role,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSameOrigin(Request $request): bool
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $origin = trim((string)$request->header('origin'));
        if ($origin === '') {
            return true;
        }
        $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $requestHost = strtolower(explode(':', $request->host())[0]);
        return $originHost !== '' && hash_equals($requestHost, $originHost);
    }

    /**
     * 统一返回未授权响应
     */
    protected function unauthorized(Request $request, string $msg): Response
    {
        if ($request->isAjax() || str_contains($request->path(), '/api/')) {
            return json(['code' => 401, 'msg' => $msg])->withStatus(401);
        }
        return redirect('/admin_login.html');
    }
}
