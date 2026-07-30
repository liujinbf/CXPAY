<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use plugin\cxpay\wxpay_clerk_adapter\Driver;
use plugin\cxpay\wxpay_clerk_adapter\ProviderClient;
use support\UrlGuard;

require_once __DIR__ . '/../../plugins-src/wxpay-clerk-adapter/src/Driver.php';

final class WxpayClerkAdapterPluginTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->manifestPath = __DIR__ . '/../../plugins-src/wxpay-clerk-adapter/manifest.json';
    }

    public function testSourceManifestIsValid(): void
    {
        self::assertFileExists($this->manifestPath);
        $manifest = json_decode((string)file_get_contents($this->manifestPath), true);
        self::assertIsArray($manifest);
        self::assertSame('cxpay.wxpay.clerk_adapter', $manifest['id'] ?? null);
        self::assertSame('wxpay_clerk_adapter', $manifest['slug'] ?? null);
        self::assertSame('wxpay', $manifest['payment_type'] ?? null);
    }

    public function testAcceptsValidSignedPaymentCallback(): void
    {
        $driver = new Driver();
        $secret = str_repeat('a', 32);
        $now = time();
        $params = [
            'source_bill_id' => 'WX-CLERK-BILL-001',
            'out_trade_no'   => 'CX-ORDER-CLERK-1001',
            'money'          => '50.00',
            'occurred_at'    => (string)$now,
            'timestamp'      => (string)$now,
            'nonce'          => 'nonce-clerk-12345',
        ];
        $fields = $params;
        ksort($fields);
        $canonical = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $params['sign'] = hash_hmac('sha256', $canonical, $secret);

        $result = $driver->notify($params, ['callback_secret' => $secret]);
        self::assertTrue($result['success']);
        self::assertSame('CX-ORDER-CLERK-1001', $result['out_trade_no']);
        self::assertSame(50.0, $result['amount']);
    }

    public function testRejectsExpiredPaymentCallback(): void
    {
        $driver = new Driver();
        $secret = str_repeat('a', 32);
        $now = time() - 400; // 超出 300 秒时间窗口
        $params = [
            'source_bill_id' => 'WX-CLERK-BILL-002',
            'out_trade_no'   => 'CX-ORDER-CLERK-1002',
            'money'          => '20.00',
            'occurred_at'    => (string)$now,
            'timestamp'      => (string)$now,
            'nonce'          => 'nonce-clerk-54321',
        ];
        $fields = $params;
        ksort($fields);
        $canonical = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $params['sign'] = hash_hmac('sha256', $canonical, $secret);

        $result = $driver->notify($params, ['callback_secret' => $secret]);
        self::assertFalse($result['success']);
    }
}
