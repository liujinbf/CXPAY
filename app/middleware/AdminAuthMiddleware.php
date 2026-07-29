<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 管理员后台与 API 通用 Session/Token 身份验证中间件
 * 修复：Token 必须通过 HMAC-SHA256 签名验证 + 过期时间校验，而非仅判断非空
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

        return $handler($request);
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
            if (count($parts) !== 3) {
                return null;
            }

            [$account, $expireStr, $sign] = $parts;
            $expire = (int)$expireStr;

            // 校验过期时间
            if (time() > $expire) {
                return null;
            }

            // 取服务端盐并重算签名
            $tokenSalt = (string)DB::table('cx_config')->where('name', 'token_salt')->value('value');
            if (strlen($tokenSalt) < 32) {
                return null;
            }
            $tokenRaw    = $account . '|' . $expireStr;
            $expectedSign = hash_hmac('sha256', $tokenRaw, $tokenSalt);

            if (!hash_equals($expectedSign, $sign)) {
                return null;
            }

            return [
                'username'     => $account,
                'token_expire' => $expire,
                'role'         => 'root',
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
