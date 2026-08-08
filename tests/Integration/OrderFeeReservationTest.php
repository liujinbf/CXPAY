<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\model\Callbill;
use app\model\UserMoneyLog;
use app\service\CallbillService;
use app\service\OrderService;
use Tests\Support\OrderDatabaseTestCase;

final class OrderFeeReservationTest extends OrderDatabaseTestCase
{
    public function testPaidOrderConsumesReservationWithoutSecondDeduction(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'pay_init_status' => 1,
            'pay_init_time' => time(),
        ]);

        self::assertTrue((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'UPSTREAM-1',
            100.00,
            (int)$channel->id,
            true
        ));
        self::assertTrue((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'UPSTREAM-RETRY',
            100.00,
            (int)$channel->id,
            true
        ));
        self::assertFalse((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'WRONG-CHANNEL',
            100.00,
            (int)$channel->id + 1,
            true
        ));

        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame(1, (int)$order->fresh()->status);
        self::assertSame(2, (int)$order->fresh()->fee_status);
        self::assertSame('consumed', (string)$order->fresh()->fee_reservation_status);
        self::assertSame(2, (int)$order->fresh()->pay_init_status);
        self::assertSame(0, UserMoneyLog::count());
        self::assertSame('100.00', number_format((float)$channel->fresh()->today_money, 2, '.', ''));
        self::assertSame(1, (int)$channel->fresh()->today_count);
    }

    public function testLegacyOrderWithoutReservationStillDeductsFeeOnPayment(): void
    {
        $merchant = $this->merchant('10.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel, ['fee_amount' => '0.00', 'fee_status' => 0]);

        self::assertTrue((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'UPSTREAM-LEGACY',
            100.00,
            (int)$channel->id,
            true
        ));

        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame('1.00', number_format((float)$order->fresh()->fee_amount, 2, '.', ''));
        self::assertSame(2, (int)$order->fresh()->fee_status);
        self::assertSame('-1.00', number_format((float)UserMoneyLog::first()->money, 2, '.', ''));
    }

    public function testDuplicateAssistantBillCannotSettleAnotherOrder(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel();
        $occurredAt = time();
        $firstOrder = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
        ]);
        $service = new CallbillService();

        $first = $service->processPush(
            'alipay_app_asst',
            'ANDROID_device-01',
            100.00,
            '支付宝成功收款100.00元',
            (int)$channel->id,
            'notification.alipay.0001',
            $occurredAt,
            hash('sha256', 'same-notification'),
            '1.0.0'
        );
        self::assertTrue($first['success']);
        self::assertSame(1, (int)$firstOrder->fresh()->status);

        $secondOrder = $this->order($merchant, $channel, [
            'trade_no' => 'CX-2',
            'out_trade_no' => 'OUT-2',
            'fee_amount' => '0.00',
            'fee_status' => 0,
        ]);
        $duplicate = $service->processPush(
            'alipay_app_asst',
            'ANDROID_device-01',
            100.00,
            '支付宝成功收款100.00元',
            (int)$channel->id,
            'notification.alipay.0001',
            $occurredAt,
            hash('sha256', 'same-notification'),
            '1.0.0'
        );

        self::assertTrue($duplicate['success']);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame(0, (int)$secondOrder->fresh()->status);
        self::assertSame(1, Callbill::count());
    }

    public function testExpiredAssistantBillIsSentToManualReview(): void
    {
        $merchant = $this->merchant('10.00');
        $channel = $this->channel();
        $occurredAt = time() - 60;
        $order = $this->order($merchant, $channel, [
            'status' => 2,
            'create_time' => $occurredAt - 30,
            'expire_time' => $occurredAt + 30,
        ]);

        $result = (new CallbillService())->processPush(
            'wxpay_app_asst',
            'ANDROID_device-01',
            100.00,
            '微信支付收款100.00元',
            (int)$channel->id,
            'notification.wxpay.0001',
            $occurredAt,
            hash('sha256', 'expired-notification'),
            '1.0.0'
        );

        self::assertFalse($result['success']);
        self::assertSame(3, (int)Callbill::first()->status);
        self::assertSame(2, (int)$order->fresh()->status);
    }

    public function testManualReviewStillEnforcesChannelAndAmount(): void
    {
        $merchant = $this->merchant('10.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel);
        $bill = Callbill::create([
            'device_id' => 'ANDROID_device-01',
            'source_bill_id' => 'notification.review.0001',
            'app_name' => 'alipay_app_asst',
            'money' => '99.99',
            'remark' => '待复核账单',
            'channel_id' => (int)$channel->id,
            'occurred_at' => time(),
            'raw_hash' => hash('sha256', 'review-bill'),
            'client_version' => '1.0.0',
            'status' => 3,
            'create_time' => time(),
        ]);
        $service = new CallbillService();

        $rejected = $service->reviewMatch((int)$bill->id, (string)$order->trade_no, 'tester');
        self::assertFalse($rejected['success']);
        self::assertSame(3, (int)$bill->fresh()->status);
        self::assertSame(0, (int)$order->fresh()->status);

        $bill->money = '100.00';
        $bill->save();
        $accepted = $service->reviewMatch((int)$bill->id, (string)$order->trade_no, 'tester');
        self::assertTrue($accepted['success']);
        self::assertSame(1, (int)$bill->fresh()->status);
        self::assertSame((int)$order->id, (int)$bill->fresh()->order_id);
        self::assertSame(1, (int)$order->fresh()->status);
    }
}
