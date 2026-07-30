<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\middleware\RequestIdMiddleware;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * RequestIdMiddleware 单元测试（任务10）
 *
 * 验证中间件正确生成/透传 X-Request-ID，并注入 request->context。
 */
final class RequestIdMiddlewareTest extends TestCase
{
    /**
     * 测试1：无 X-Request-ID 请求头时，自动生成新 ID（26位十六进制）
     */
    public function testGeneratesIdWhenHeaderAbsent(): void
    {
        $middleware = new RequestIdMiddleware();

        $capturedId = null;
        $handler    = function (Request $request) use (&$capturedId): Response {
            $capturedId = $request->context['request_id'] ?? null;
            return new Response(200, [], 'ok');
        };

        $request = $this->makeRequest();
        $middleware->process($request, $handler);

        self::assertNotNull($capturedId, '应自动生成 request_id 并注入到 context');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{24}$/',
            $capturedId,
            '自动生成的 ID 应为 24 位十六进制字符串'
        );
    }

    /**
     * 测试2：请求头中有合法 X-Request-ID 时，透传不修改
     */
    public function testPassesThroughValidExistingRequestId(): void
    {
        $middleware   = new RequestIdMiddleware();
        $existingId   = 'abc123xyz-traceId-from-gateway';
        $capturedId   = null;

        $handler = function (Request $request) use (&$capturedId): Response {
            $capturedId = $request->context['request_id'] ?? null;
            return new Response(200, [], 'ok');
        };

        $request = $this->makeRequest(['x-request-id' => $existingId]);
        $middleware->process($request, $handler);

        self::assertSame($existingId, $capturedId, '合法的上游 X-Request-ID 应被透传');
    }

    /**
     * 测试3：请求头中 X-Request-ID 格式不合法时，忽略并重新生成
     */
    public function testReplacesInvalidRequestId(): void
    {
        $middleware = new RequestIdMiddleware();
        $capturedId = null;

        $handler = function (Request $request) use (&$capturedId): Response {
            $capturedId = $request->context['request_id'] ?? null;
            return new Response(200, [], 'ok');
        };

        // 注入含非法字符的 ID
        $request = $this->makeRequest(['x-request-id' => '../../etc/passwd']);
        $middleware->process($request, $handler);

        self::assertNotSame('../../etc/passwd', $capturedId, '非法 ID 应被丢弃并重新生成');
        self::assertNotNull($capturedId);
    }

    /**
     * 测试4：响应头中必须包含 X-Request-ID
     */
    public function testResponseContainsRequestIdHeader(): void
    {
        $middleware = new RequestIdMiddleware();
        $handler    = fn(Request $r): Response => new Response(200, [], 'ok');

        $request  = $this->makeRequest();
        $response = $middleware->process($request, $handler);

        // 从响应头中读取
        $responseId = $response->getHeader('X-Request-ID');
        self::assertNotEmpty($responseId, '响应头必须包含 X-Request-ID');
    }

    // ─── 辅助方法 ──────────────────────────────────────────────────────────

    /**
     * 创建带有指定 headers 的模拟 Request 对象
     * （使用 Webman 内部使用的 Workerman\Protocols\Http\Request 兼容方式）
     */
    private function makeRequest(array $headers = []): Request
    {
        // 构造 HTTP 原始报文
        $headerLines  = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        foreach ($headers as $key => $val) {
            $headerLines .= "{$key}: {$val}\r\n";
        }
        $headerLines .= "\r\n";

        $request = new Request($headerLines);
        // 初始化 context（Webman Request 需要手动设置）
        $request->context = [];
        return $request;
    }
}
