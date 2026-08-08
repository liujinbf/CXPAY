<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\model\UserMoneyLog;
use app\service\OrderService;
use Tests\Support\OrderDatabaseTestCase;

final class OrderClosingServiceTest extends OrderDatabaseTestCase
{
    public function testClosingPendingOrderRefundsReservedFeeExactlyOnce(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'pay_init_status' => 1,
            'pay_init_time' => time(),
        ]);
        $service = new OrderService();

        self::assertTrue($service->closePendingOrder((string)$order->trade_no, '测试关闭'));
        self::assertTrue($service->closePendingOrder((string)$order->trade_no, '重复关闭'));

        self::assertSame('10.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame(2, (int)$order->fresh()->status);
        self::assertSame(3, (int)$order->fresh()->fee_status);
        self::assertSame(3, (int)$order->fresh()->pay_init_status);
        self::assertSame(1, UserMoneyLog::count());
        self::assertSame('1.00', number_format((float)UserMoneyLog::first()->money, 2, '.', ''));
    }

    public function testExpiryBatchClosesOnlyExpiredPendingOrders(): void
    {
        $merchant = $this->merchant('8.00');
        $channel = $this->channel();
        $expired = $this->order($merchant, $channel, [
            'trade_no' => 'CX-EXPIRED', 'out_trade_no' => 'OUT-EXPIRED',
            'fee_amount' => '1.00', 'fee_status' => 1, 'expire_time' => time() - 1,
        ]);
        $active = $this->order($merchant, $channel, [
            'trade_no' => 'CX-ACTIVE', 'out_trade_no' => 'OUT-ACTIVE',
            'fee_amount' => '1.00', 'fee_status' => 1, 'expire_time' => time() + 300,
        ]);

        self::assertSame(1, (new OrderService())->expirePendingOrders());
        self::assertSame(2, (int)$expired->fresh()->status);
        self::assertSame(0, (int)$active->fresh()->status);
        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
    }
}
