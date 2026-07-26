<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 管理员后台与 API 通用 Session/Token 身份验证中间件
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $session = $request->session();
        $adminInfo = $session->get('admin_info');

        // 验证请求 Header 或 Session 登录状态
        $token = $request->header('authorization') ?? $request->get('token') ?? '';

        if (!$adminInfo && empty($token)) {
            // 如果是 API 请求，返回 JSON
            if ($request->isAjax() || str_contains($request->path(), '/api/')) {
                return json(['code' => 401, 'msg' => '管理员未登录或 Token 已过期，请重新登录'])->withStatus(401);
            }
            return redirect('/admin_login.html');
        }

        return $handler($request);
    }
}
