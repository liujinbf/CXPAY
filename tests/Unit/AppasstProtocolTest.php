<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use support\AppasstProtocol;

final class AppasstProtocolTest extends TestCase
{
    public function testCanonicalSignatureIsStable(): void
    {
        $params = [
            'version' => '2',
            'channel_id' => 12,
            'device_id' => 'ANDROID_device-01',
            'event' => 'bill',
            'pay_type' => 'alipay',
            'money' => '10',
            'source_bill_id' => 'notification:alipay:10001',
            'occurred_at' => 1785196800,
            'timestamp' => 1785196801,
            'nonce' => 'abcdefghijklmnop',
            'client_version' => '1.0.0',
        ];

        self::assertSame(
            '2|12|ANDROID_device-01|bill|alipay|10.00|notification:alipay:10001|1785196800|1785196801|abcdefghijklmnop|1.0.0',
            AppasstProtocol::canonicalize($params)
        );
        self::assertTrue(AppasstProtocol::verify(
            $params,
            'test-secret',
            AppasstProtocol::sign($params, 'test-secret')
        ));
    }

    public function testSignatureRejectsTamperedBillIdentity(): void
    {
        $params = [
            'channel_id' => 12,
            'device_id' => 'ANDROID_device-01',
            'event' => 'bill',
            'pay_type' => 'alipay',
            'money' => '10.00',
            'source_bill_id' => 'notification:alipay:10001',
            'occurred_at' => 1785196800,
            'timestamp' => 1785196801,
            'nonce' => 'abcdefghijklmnop',
            'client_version' => '1.0.0',
        ];
        $signature = AppasstProtocol::sign($params, 'test-secret');
        $params['source_bill_id'] = 'notification:alipay:10002';

        self::assertFalse(AppasstProtocol::verify($params, 'test-secret', $signature));
    }

    public function testSignatureRejectsTamperedPaymentType(): void
    {
        $params = [
            'version' => '2',
            'channel_id' => 12,
            'device_id' => 'ANDROID_device-01',
            'event' => 'bill',
            'pay_type' => 'alipay',
            'money' => '10.00',
            'source_bill_id' => 'notification:alipay:10001',
            'occurred_at' => 1785196800,
            'timestamp' => 1785196801,
            'nonce' => 'abcdefghijklmnop',
            'client_version' => '2.0.0',
        ];
        $signature = AppasstProtocol::sign($params, 'test-secret');
        $params['pay_type'] = 'wxpay';

        self::assertFalse(AppasstProtocol::verify($params, 'test-secret', $signature));
    }
}
