<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Drivers\EpayGeneric\Driver;
use app\payment\EpayUpstreamException;
use app\payment\EpayUpstreamGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Sign;

final class EpayGenericDriverTest extends TestCase
{
    private function guard(): EpayUpstreamGuard
    {
        $records = [
            'pay.example.test' => ['8.8.8.8'],
            'upstream.example.test' => ['1.1.1.1'],
        ];

        return new EpayUpstreamGuard(
            static fn(string $host): array =>
                $records[$host] ?? [],
            'https://pay.example.test',
            '127.0.0.1',
            8787
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function params(string $type = 'alipay'): array
    {
        return [
            'type' => $type,
            'trade_no' => 'CX202608050001',
            'out_trade_no' => 'MERCHANT-001',
            'notify_url' =>
                'https://merchant.example.test/notify',
            'return_url' =>
                'https://merchant.example.test/return',
            'name' => '测试订单',
            'money' => '0.01',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function config(string $mode = 'submit'): array
    {
        return [
            'api_url' =>
                'https://upstream.example.test',
            'pid' => '10001',
            'key' => 'test-secret',
            'mode' => $mode,
        ];
    }

    public function testMetadataClarifiesOptionalExternalRole(): void
    {
        $meta = (
            new Driver($this->guard())
        )->getMeta();

        self::assertSame(
            'epay_generic',
            $meta['name']
        );
        self::assertSame(
            '外部易支付上游（可选）',
            $meta['title']
        );
        self::assertStringContainsString(
            '第三方',
            $meta['description']
        );
        self::assertStringContainsString(
            '不是 CXPAY 对下游商户',
            $meta['description']
        );
    }

    public function testSubmitModeSupportsAllThreePaymentTypes(): void
    {
        $driver = new Driver($this->guard());

        foreach (
            ['alipay', 'wxpay', 'qqpay']
            as $type
        ) {
            $result = $driver->pay(
                $this->params($type),
                $this->config()
            );

            self::assertSame(
                'url',
                $result['type']
            );

            self::assertStringStartsWith(
                'https://upstream.example.test/submit.php?',
                $result['pay_url']
            );

            $query = [];
            parse_str(
                (string)parse_url(
                    $result['pay_url'],
                    PHP_URL_QUERY
                ),
                $query
            );

            self::assertSame(
                $type,
                $query['type']
            );
            self::assertTrue(
                Sign::verifySign(
                    $query,
                    'test-secret'
                )
            );
        }
    }

    public function testUpchannelRejectsCurrentCxpayInstance(): void
    {
        $driver = new Driver($this->guard());
        $config = $this->config();
        $config['api_url'] =
            'https://pay.example.test';

        self::assertSame(
            [
                'code' => -1,
                'msg' =>
                    '外部易支付上游不能指向当前 CXPAY 实例',
            ],
            $driver->upchannel([], $config)
        );
    }

    public function testPaySafetyFailureIsNotConvertedToSubmitUrl(): void
    {
        $transportCalled = false;

        $transport = static function (
            string $url,
            array $data,
            array $target
        ) use (&$transportCalled): string|false {
            $transportCalled = true;
            return false;
        };

        $driver = new Driver(
            $this->guard(),
            $transport
        );

        $config = $this->config('mapi');
        $config['api_url'] =
            'https://pay.example.test';

        try {
            $driver->pay(
                $this->params(),
                $config
            );

            self::fail(
                '安全拒绝不得回退为 submit URL'
            );
        } catch (EpayUpstreamException $e) {
            self::assertSame(
                EpayUpstreamGuard::REJECTED_MESSAGE,
                $e->getMessage()
            );
            self::assertFalse($transportCalled);
        }
    }

    public function testMapiUsesValidatedTargetAndReturnsQrcode(): void
    {
        $captured = null;

        $transport = static function (
            string $url,
            array $data,
            array $target
        ) use (&$captured): string {
            $captured = [
                'url' => $url,
                'data' => $data,
                'target' => $target,
            ];

            return json_encode(
                [
                    'code' => 1,
                    'qrcode' =>
                        'https://qr.example.test/code',
                ],
                JSON_THROW_ON_ERROR
            );
        };

        $driver = new Driver(
            $this->guard(),
            $transport
        );

        $result = $driver->pay(
            $this->params('qqpay'),
            $this->config('mapi')
        );

        self::assertSame(
            'qrcode',
            $result['type']
        );
        self::assertSame(
            'https://qr.example.test/code',
            $result['pay_url']
        );

        self::assertIsArray($captured);

        self::assertSame(
            'https://upstream.example.test/mapi.php',
            $captured['url']
        );

        self::assertSame(
            [
                'scheme' => 'https',
                'host' =>
                    'upstream.example.test',
                'port' => 443,
                'ip' => '1.1.1.1',
            ],
            $captured['target']
        );

        self::assertSame(
            'qqpay',
            $captured['data']['type']
        );

        self::assertTrue(
            Sign::verifySign(
                $captured['data'],
                'test-secret'
            )
        );
    }

    public function testMapiOrdinaryNetworkFailureFallsBackToValidatedSubmit(): void
    {
        $capturedTarget = null;

        $transport = static function (
            string $url,
            array $data,
            array $target
        ) use (&$capturedTarget): string|false {
            $capturedTarget = $target;

            throw new RuntimeException(
                'temporary network failure'
            );
        };

        $driver = new Driver(
            $this->guard(),
            $transport
        );

        $result = $driver->pay(
            $this->params(),
            $this->config('mapi')
        );

        self::assertSame(
            'url',
            $result['type']
        );
        self::assertStringStartsWith(
            'https://upstream.example.test/submit.php?',
            $result['pay_url']
        );
        self::assertSame(
            '1.1.1.1',
            $capturedTarget['ip']
        );
    }

    public function testNotifyMd5VerificationRemainsCompatible(): void
    {
        $params = [
            'pid' => '10001',
            'out_trade_no' =>
                'CX202608050001',
            'trade_no' =>
                'UPSTREAM-001',
            'money' => '0.01',
            'trade_status' =>
                'TRADE_SUCCESS',
        ];

        $params['sign'] = Sign::makeSign(
            $params,
            'test-secret'
        );
        $params['sign_type'] = 'MD5';

        $result = (
            new Driver($this->guard())
        )->notify(
            $params,
            $this->config()
        );

        self::assertTrue(
            $result['success']
        );
        self::assertSame(
            'CX202608050001',
            $result['out_trade_no']
        );
        self::assertSame(
            0.01,
            $result['amount']
        );
    }

    public function testQueryRemainsCallbackOnly(): void
    {
        $result = (
            new Driver($this->guard())
        )->query(
            'CX202608050001',
            $this->config()
        );

        self::assertSame(
            ['paid' => false],
            $result
        );
    }
}
