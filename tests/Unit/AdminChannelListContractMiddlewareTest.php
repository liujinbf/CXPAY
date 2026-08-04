<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\middleware\AdminChannelListContractMiddleware;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminChannelListContractMiddlewareTest extends TestCase
{
    public function testMiddlewareIsRegisteredGloballyAfterRequestTracing(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/middleware.php';

        self::assertSame([
            \app\middleware\RequestIdMiddleware::class,
            AdminChannelListContractMiddleware::class,
        ], $config['']);
    }

    public function testLegacySyntheticShapeIsDetectedForReplacement(): void
    {
        $method = new ReflectionMethod(AdminChannelListContractMiddleware::class, 'containsPersistedChannelShape');
        $middleware = new AdminChannelListContractMiddleware();

        self::assertFalse($method->invoke($middleware, [[
            'id' => 1,
            'code' => 'alipay_official',
            'name' => '支付宝官方网页支付',
            'enabled' => true,
            'configured' => true,
        ]]));
    }

    public function testPersistedChannelShapePassesThroughWithoutSecondQuery(): void
    {
        $method = new ReflectionMethod(AdminChannelListContractMiddleware::class, 'containsPersistedChannelShape');
        $middleware = new AdminChannelListContractMiddleware();

        self::assertTrue($method->invoke($middleware, [[
            'id' => 42,
            'c_type' => 'wxpay_app_asst',
            'online_status' => 1,
        ]]));
    }
}
