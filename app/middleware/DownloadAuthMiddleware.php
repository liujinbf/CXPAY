<?php

declare(strict_types=1);

namespace app\middleware;

use Illuminate\Database\Capsule\Manager as DB;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * APK / 源码下载鉴权中间件
 *
 * 校验下载请求是否携带有效的一次性签名 token（?dl_token=xxx），
 * 或者请求方是已登录的管理员 Session。
 *
 * dl_token 由 AdminAuthController 或 CloudLicenseService 颁发，
 * 存储在 Redis Key: cx:dl_token:{sha256(token)}，TTL 300s，一次性消费。
 *
 * 用法：
 *   Route::get('/download/...')->middleware([DownloadAuthMiddleware::class])
 */
class DownloadAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 1. 已登录管理员 Session 直接放行
        $session   = $request->session();
        $adminInfo = $session->get('admin_info');
        if (!empty($adminInfo)) {
            return $handler($request);
        }

        // 2. 校验一次性下载 token
        $dlToken = trim((string)($request->get('dl_token') ?? $request->header('X-DL-Token', '')));
        if ($dlToken === '') {
            return $this->forbidden('缺少下载授权凭据，请从管理后台获取有效的下载链接');
        }

        $redisKey = 'cx:dl_token:' . hash('sha256', $dlToken);
        try {
            $redis = \Webman\Redis\Client::connection();
            $meta  = $redis->get($redisKey);
            if (!$meta) {
                return $this->forbidden('下载链接已过期或已被使用，请重新获取');
            }
            // 一次性消费：验证通过后立即删除
            $redis->del($redisKey);
        } catch (\Throwable) {
            // Redis 不可用时拒绝（安全优先）
            return $this->forbidden('下载服务暂时不可用，请稍后重试');
        }

        return $handler($request);
    }

    private function forbidden(string $msg): Response
    {
        return response(
            json_encode(['code' => 403, 'msg' => $msg], JSON_UNESCAPED_UNICODE),
            403,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
