<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Shared;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Shared\Http\ApiErrorResponder;
use PHPUnit\Framework\TestCase;

final class ApiErrorResponderTest extends TestCase
{
    public function testProducesStableSafeErrorEnvelope(): void
    {
        $exception = new CloudException(
            ErrorCode::RATE_LIMITED,
            '请求过于频繁',
            429,
            true,
            ['retry_after' => 60]
        );

        $response = (new ApiErrorResponder())->respond($exception, 'req-20260809');
        $payload = json_decode($response->rawBody(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(0, $payload['code']);
        self::assertSame('CLOUD_RATE_LIMITED', $payload['error_code']);
        self::assertSame('请求过于频繁', $payload['msg']);
        self::assertSame('req-20260809', $payload['request_id']);
        self::assertTrue($payload['retryable']);
        self::assertSame(['retry_after' => 60], $payload['data']);
        self::assertArrayNotHasKey('trace', $payload);
        self::assertStringNotContainsString(__FILE__, $response->rawBody());
    }
}
