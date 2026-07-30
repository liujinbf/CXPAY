<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Request ID 链路追踪中间件
 *
 * 职责：
 *   1. 为每个请求生成或透传唯一 request_id（trace ID），并注入 request->context
 *   2. 在响应头中回写 X-Request-ID，便于前端/网关日志关联
 *   3. 写入 PHP error_log 前缀，使该请求的异步日志具备可追溯的唯一标识
 *
 * 集成方式：在 config/middleware.php 中注册为全局中间件（最前置）：
 *   return ['' => [app\middleware\RequestIdMiddleware::class, ...]];
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 优先复用上游网关（Nginx/CDN/API Gateway）透传的 Trace ID
        $requestId = trim((string)($request->header('x-request-id') ?? ''));

        // 若缺失或格式不合法，自动生成一个新 ID
        if ($requestId === '' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $requestId)) {
            $requestId = $this->generateId();
        }

        // 注入 request->context，供所有控制器/服务读取
        $request->context['request_id'] = $requestId;

        // 在响应头回写 X-Request-ID，用于前端日志关联
        $response = $handler($request);
        $response->withHeader('X-Request-ID', $requestId);

        return $response;
    }

    /**
     * 生成符合格式要求的 Request ID
     * 格式：时间戳(10位hex) + 随机(16位hex) = 26位
     */
    private function generateId(): string
    {
        return sprintf('%08x%s', time(), bin2hex(random_bytes(8)));
    }
}
