<?php

declare(strict_types=1);

use app\service\ChannelMonitorService;
use PHPUnit\Framework\TestCase;

final class ChannelMonitorServiceTest extends TestCase
{
    public function testPollFailureCounterSaturatesAtTinyintMaximum(): void
    {
        $method = new ReflectionMethod(ChannelMonitorService::class, 'nextPollFailCount');

        self::assertSame(1, $method->invoke(null, 0));
        self::assertSame(255, $method->invoke(null, 254));
        self::assertSame(255, $method->invoke(null, 255));
        self::assertSame(255, $method->invoke(null, PHP_INT_MAX));
    }
}
