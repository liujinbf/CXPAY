<?php

declare(strict_types=1);

namespace app\service\order;

use RuntimeException;

/**
 * 计算手续费的资金来源，不负责持久化账户变更。
 */
final class FeeReservationService
{
    public function allocate(string $fee, string $cashBalance, string $discountBalance): FeeReservation
    {
        $fee = $this->money($fee);
        $cashBalance = $this->money($cashBalance);
        $discountBalance = $this->money($discountBalance);

        if (bccomp(bcadd($cashBalance, $discountBalance, 2), $fee, 2) < 0) {
            throw new RuntimeException(
                "商户可用余额不足（需手续费 ¥{$fee}，充值余额 ¥{$cashBalance}，套餐抵扣金 ¥{$discountBalance}）"
            );
        }

        $discount = bccomp($discountBalance, $fee, 2) >= 0 ? $fee : $discountBalance;
        $cash = bcsub($fee, $discount, 2);

        return new FeeReservation($fee, $cash, $discount);
    }

    private function money(mixed $amount): string
    {
        return is_numeric($amount) ? bcadd((string)$amount, '0.00', 2) : '0.00';
    }
}
