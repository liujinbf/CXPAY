<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use support\Sign;

final class SignTest extends TestCase
{
    public function testMakeSignIncludesAllBusinessFieldsInSortedOrder(): void
    {
        $params = [
            'z' => '9',
            's' => '4',
            'a' => '1',
            'm' => '3',
            'c' => '2',
            'empty' => '',
            'sign_type' => 'MD5',
        ];

        self::assertSame(
            md5('a=1&c=2&m=3&s=4&z=9secret'),
            Sign::makeSign($params, 'secret')
        );
    }

    public function testVerifySignRejectsTamperingAndInvalidFormat(): void
    {
        $data = ['pid' => 'M10001', 'money' => '10.00'];
        $data['sign'] = Sign::makeSign($data, 'secret');

        self::assertTrue(Sign::verifySign($data, 'secret'));

        $data['money'] = '1000.00';
        self::assertFalse(Sign::verifySign($data, 'secret'));

        $data['sign'] = 'not-a-md5-signature';
        self::assertFalse(Sign::verifySign($data, 'secret'));
    }

    public function testCallbackOnlyAcceptsExactSuccessResponse(): void
    {
        self::assertTrue(Sign::callbackNotify("  SUCCESS\n"));
        self::assertFalse(Sign::callbackNotify('not success'));
        self::assertFalse(Sign::callbackNotify(['success']));
    }

    public function testNotifyPayloadUsesMerchantPidAndFixedMoneyPrecision(): void
    {
        $payload = Sign::buildMerchantNotifyData([
            'trade_no' => 'CX202607280001',
            'out_trade_no' => 'ORDER-1',
            'pay_type' => 'alipay',
            'subject' => '测试订单',
            'amount' => 1,
        ], 'M10001', 'merchant-secret');

        self::assertSame('M10001', $payload['pid']);
        self::assertSame('1.00', $payload['money']);
        self::assertTrue(Sign::verifySign($payload, 'merchant-secret'));
    }
}
