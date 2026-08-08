<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use plugin\cxpay\wxpay_clerk_adapter\Driver;
use plugin\cxpay\wxpay_clerk_adapter\ProviderClient;
use RuntimeException;
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
        self::assertStringNotContainsString('官方到账通知', (string) ($manifest['description'] ?? ''));
        self::assertStringNotContainsString('零封号', (string) ($manifest['description'] ?? ''));
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

    public function testRejectsHttpProviderConfiguration(): void
    {
        $driver = new Driver();
        $config = $this->validConfig();
        $config['monitor_base_url'] = 'http://93.184.216.34';

        $result = $driver->upchannel(['status' => 0], $config);

        self::assertSame(-1, $result['code']);
        self::assertStringContainsString('HTTPS', $result['msg']);
    }

    public function testProviderClientDoesNotFollowRedirect(): void
    {
        $successBody = '{"status":"OK"}';
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://93.184.216.34/redirected']),
            new Response(200, [
                'X-CXPAY-Signature' => hash_hmac('sha256', $successBody, str_repeat('c', 32)),
            ], $successBody),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $provider = new ProviderClient($client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 302');
        $provider->operationsStatus($this->validConfig());
    }

    public function testPayRequiresExplicitOrderAcceptance(): void
    {
        $body = '{"accepted":false}';
        $mock = new MockHandler([new Response(200, [
            'X-CXPAY-Signature' => hash_hmac('sha256', $body, str_repeat('c', 32)),
        ], $body)]);
        $provider = new ProviderClient(new Client(['handler' => HandlerStack::create($mock)]));
        $driver = new Driver($provider);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('没有确认订单登记');
        $driver->pay([
            'trade_no' => 'CX-PAY-REJECTED',
            'money' => '1.23',
            'expire_time' => time() + 300,
        ], $this->validConfig());
    }

    public function testRejectsMalformedOrFutureCallbackFacts(): void
    {
        $driver = new Driver();
        $params = $this->signedCallback([
            'source_bill_id' => 'short',
            'occurred_at' => (string) (time() + 600),
        ]);

        $result = $driver->notify($params, $this->validConfig());

        self::assertFalse($result['success']);
    }

    /** @return array<string, mixed> */
    private function validConfig(): array
    {
        return [
            'qr_url' => 'wxp://test-qr-code',
            'monitor_base_url' => 'https://93.184.216.34',
            'account_id' => '',
            'client_id' => 'client-test',
            'client_secret' => str_repeat('s', 32),
            'callback_secret' => str_repeat('c', 32),
        ];
    }

    /** @param array<string, string> $overrides @return array<string, string> */
    private function signedCallback(array $overrides): array
    {
        $params = array_merge([
            'source_bill_id' => 'WX-CLERK-BILL-VALID-001',
            'out_trade_no' => 'CX-ORDER-CLERK-VALID-001',
            'money' => '12.30',
            'occurred_at' => (string) time(),
            'timestamp' => (string) time(),
            'nonce' => 'nonce-clerk-valid-001',
        ], $overrides);
        $fields = $params;
        ksort($fields);
        $params['sign'] = hash_hmac(
            'sha256',
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            str_repeat('c', 32)
        );
        return $params;
    }
}
