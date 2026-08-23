<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 全局 API 限流中间件（P1）
 *
 * 按 IP + 路由前缀分组，滑动窗口计数存 Redis。
 * 超限返回 HTTP 429 Too Many Requests。
 *
 * 默认规则（可在 config/middleware.php 配置参数覆盖）：
 *   - /api/admin/*     : 120 次 / 60 秒（管理后台）
 *   - /submit.php      : 60  次 / 60 秒（下单网关）
 *   - /mapi.php        : 60  次 / 60 秒（下单网关）
 *   - /api/appasst/*   : 200 次 / 60 秒（助手上报）
 *   - /api/merchant/*  : 120 次 / 60 秒（商户 API）
 *   - 其余接口          : 300 次 / 60 秒（默认宽松）
 *
 * 挂载示例（config/middleware.php）：
 *   [app\middleware\ApiRateLimitMiddleware::class]
 */
class ApiRateLimitMiddleware implements MiddlewareInterface
{
    private const RULES = [
        // [路径前缀, 最大请求数, 窗口秒数]
        ['/submit.php',    60,  60],
        ['/mapi.php',      60,  60],
        ['/api/admin/',   120,  60],
        ['/api/merchant/',120,  60],
        ['/api/appasst/', 200,  60],
        ['/',             300,  60], // 默认兜底规则（放最后）
    ];

    public function process(Request $request, callable $handler): Response
    {
        $path = $request->path();

        // 健康探针不限流
        if ($path === '/health' || str_starts_with($path, '/health/')) {
            return $handler($request);
        }

        [$limit, $window] = $this->matchRule($path);
        $ip  = $request->getRemoteIp();
        $key = 'cx:rate:' . hash('sha256', $ip . $path . floor(time() / $window));

        try {
            $redis = \Webman\Redis\Client::connection();
            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $window);
            }
            if ($count > $limit) {
                $retryAfter = $window - (time() % $window);
                return response(
                    json_encode([
                        'code'        => 429,
                        'msg'         => '请求过于频繁，请稍后再试',
                        'retry_after' => $retryAfter,
                    ], JSON_UNESCAPED_UNICODE),
                    429,
                    [
                        'Content-Type'   => 'application/json; charset=utf-8',
                        'Retry-After'    => (string)$retryAfter,
                        'X-RateLimit-Limit'     => (string)$limit,
                        'X-RateLimit-Remaining' => '0',
                        'X-RateLimit-Reset'     => (string)(time() + $retryAfter),
                    ]
                );
            }

            $remaining = max(0, $limit - $count);
            $response  = $handler($request);
            $response->withHeaders([
                'X-RateLimit-Limit'     => (string)$limit,
                'X-RateLimit-Remaining' => (string)$remaining,
            ]);
            return $response;
        } catch (\Throwable) {
            // Redis 不可用时 fail-open，正常处理请求（不因限流组件故障拦截正常流量）
            return $handler($request);
        }
    }

    /** 匹配路径前缀，返回 [limit, window] */
    private function matchRule(string $path): array
    {
        foreach (self::RULES as [$prefix, $limit, $window]) {
            if ($prefix === '/' || str_starts_with($path, $prefix)) {
                return [$limit, $window];
            }
        }
        return [300, 60];
    }
}
