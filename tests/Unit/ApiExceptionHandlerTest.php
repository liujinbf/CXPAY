<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\exception\Handler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use support\exception\PageNotFoundException;
use Webman\Http\Request;

final class ApiExceptionHandlerTest extends TestCase
{
    public function testUnknownApiRouteKeepsHttp404Status(): void
    {
        $request = new Request("POST /api/cloud/plugin/order/notify HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");
        $handler = new Handler(new NullLogger(), false);

        $response = $handler->render($request, new PageNotFoundException());
        $payload = json_decode((string)$response->rawBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(-1, $payload['code']);
        self::assertSame('404 Not Found', $payload['msg']);
        self::assertNull($payload['trace']);
    }
}
