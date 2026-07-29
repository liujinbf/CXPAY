<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use support\BillSourceProtocol;

final class BillSourceProtocolTest extends TestCase
{
    public function testBearerTokenRequiresStrongUrlSafeValue(): void
    {
        $token = str_repeat('A', 32) . '_-safe';
        self::assertSame($token, BillSourceProtocol::bearerToken('Bearer ' . $token));
        self::assertNull(BillSourceProtocol::bearerToken('Bearer short'));
        self::assertNull(BillSourceProtocol::bearerToken('Basic ' . $token));
    }

    public function testBillIsNormalizedAgainstBoundPaymentType(): void
    {
        $bill = BillSourceProtocol::normalizeBill([
            'source_bill_id' => 'WX202607281234567890',
            'pay_type' => 'wxpay',
            'money' => '12.3',
            'occurred_at' => '1785211200',
            'remark' => '微信到账',
            'collector_id' => 'ANDROID_PHONE_01',
        ], 'wxpay', 1785211201);

        self::assertSame('12.30', $bill['money']);
        self::assertSame(1785211200, $bill['occurred_at']);
        self::assertSame('ANDROID_PHONE_01', $bill['collector_id']);
    }

    public function testBillRejectsCrossChannelPaymentType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillSourceProtocol::normalizeBill([
            'source_bill_id' => 'WX202607281234567890',
            'pay_type' => 'alipay',
            'money' => '12.30',
            'occurred_at' => '1785211200',
            'collector_id' => 'ANDROID_PHONE_01',
        ], 'wxpay', 1785211201);
    }

    public function testCursorRejectsNonNumericInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillSourceProtocol::cursor('1 OR 1=1');
    }
}
