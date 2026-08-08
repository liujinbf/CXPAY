<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\service\order\FeeReservationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FeeReservationServiceTest extends TestCase
{
    public function testUsesDiscountBeforeCash(): void
    {
        $reservation = $this->service()->allocate('3.00', '10.00', '1.25');

        self::assertSame('3.00', $reservation->fee);
        self::assertSame('1.75', $reservation->cash);
        self::assertSame('1.25', $reservation->discount);
    }

    public function testUsesOnlyDiscountWhenDiscountCoversFee(): void
    {
        $reservation = $this->service()->allocate('1.00', '10.00', '5.00');

        self::assertSame('0.00', $reservation->cash);
        self::assertSame('1.00', $reservation->discount);
    }

    public function testRejectsInsufficientCombinedBalance(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('商户可用余额不足');

        $this->service()->allocate('3.00', '1.00', '1.50');
    }

    private function service(): FeeReservationService
    {
        if (!class_exists(FeeReservationService::class)) {
            self::fail('手续费预留服务尚未实现');
        }

        return new FeeReservationService();
    }
}
