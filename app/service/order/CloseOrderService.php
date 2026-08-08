<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\Merchant;
use app\model\Order;
use app\model\UserMoneyLog;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;
use Throwable;

/**
 * 关闭待支付订单并按原资金来源释放手续费。
 */
final class CloseOrderService
{
    public function close(string $tradeNo, string $reason = '订单关闭'): bool
    {
        $targetOrder = Order::where('trade_no', $tradeNo)->first();
        if (!$targetOrder) {
            return false;
        }
        if ((int)$targetOrder->status === 2) {
            return true;
        }
        if ((int)$targetOrder->status !== 0) {
            return false;
        }
        $targetOrderId = (int)$targetOrder->id;
        $targetMerchantId = (int)$targetOrder->merchant_id;

        try {
            return DB::connection()->transaction(function () use (
                $targetOrderId,
                $targetMerchantId,
                $reason
            ): bool {
                $merchant = Merchant::where('id', $targetMerchantId)->lockForUpdate()->first();
                $order = Order::where('id', $targetOrderId)->lockForUpdate()->first();
                if (!$order) {
                    return false;
                }
                if ((int)$order->status === 2) {
                    return true;
                }
                if ((int)$order->status !== 0) {
                    return false;
                }

                $fee = $this->normalizeMoney($order->fee_amount ?? 0);
                if ((int)($order->fee_status ?? 0) === 1 && bccomp($fee, '0.00', 2) > 0) {
                    $reservationStatus = (string)($order->fee_reservation_status ?? 'legacy');
                    if ($reservationStatus === 'consumed') {
                        $order->fee_status = 2;
                    } elseif ($reservationStatus === 'released') {
                        $order->fee_status = 3;
                    } else {
                        if (!$merchant) {
                            throw new RuntimeException('订单所属商户不存在，无法释放手续费');
                        }

                        $before = $this->normalizeMoney($merchant->money);
                        $beforeDiscount = $this->normalizeMoney($merchant->plan_fee_discount_balance ?? 0);
                        if ($reservationStatus === 'reserved') {
                            $cashRefund = $this->normalizeMoney($order->fee_reserved_cash ?? 0);
                            $discountRefund = $this->normalizeMoney($order->fee_reserved_discount ?? 0);
                        } else {
                            $cashRefund = $fee;
                            $discountRefund = '0.00';
                            error_log('[FeeReservation] legacy refund trade_no=' . (string)$order->trade_no);
                        }

                        $after = bcadd($before, $cashRefund, 2);
                        $afterDiscount = bcadd($beforeDiscount, $discountRefund, 2);
                        $merchant->money = $after;
                        $merchant->plan_fee_discount_balance = $afterDiscount;
                        $merchant->save();
                        UserMoneyLog::log(
                            (int)$merchant->id,
                            $cashRefund,
                            $before,
                            $after,
                            "{$reason}，释放订单 {$order->trade_no} 手续费（现金 ¥{$cashRefund}，套餐抵扣金 ¥{$discountRefund}）"
                        );
                        $order->fee_status = 3;
                        $order->fee_reservation_status = 'released';
                    }
                }

                $order->status = 2;
                if ((int)($order->pay_init_status ?? 0) === 1) {
                    $order->pay_init_status = 3;
                }
                $order->save();
                return true;
            }, 3);
        } catch (Throwable $e) {
            error_log('[CloseOrderService] 关闭订单失败 trade_no=' . $tradeNo . ' error=' . $e->getMessage());
            return false;
        }
    }

    public function expire(int $limit = 500): int
    {
        $tradeNumbers = Order::where('status', 0)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', time())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('trade_no');

        $closed = 0;
        foreach ($tradeNumbers as $tradeNo) {
            if ($this->close((string)$tradeNo, '订单超时')) {
                $closed++;
            }
        }
        return $closed;
    }

    private function normalizeMoney(mixed $amount): string
    {
        return is_numeric($amount) ? number_format((float)$amount, 2, '.', '') : '0.00';
    }
}
