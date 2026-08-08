<?php

declare(strict_types=1);

namespace app\service\order;

/**
 * 一笔手续费在现金余额和套餐抵扣金之间的预留分配。
 */
final class FeeReservation
{
    public function __construct(
        public readonly string $fee,
        public readonly string $cash,
        public readonly string $discount,
    ) {
    }
}
