<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\service\order\OrderNumberGenerator;
use PHPUnit\Framework\TestCase;

final class OrderNumberGeneratorTest extends TestCase
{
    public function testGeneratesOpaqueUniqueOrderNumbersAcrossFreshInstances(): void
    {
        if (!class_exists(OrderNumberGenerator::class)) {
            self::fail('安全订单号生成器尚未实现');
        }

        $ids = [];
        for ($i = 0; $i < 2000; $i++) {
            $id = (new OrderNumberGenerator())->generate();
            self::assertMatchesRegularExpression('/^CX\d{14}[A-F0-9]{20}$/', $id);
            $ids[$id] = true;
        }

        self::assertCount(2000, $ids);
    }
}
