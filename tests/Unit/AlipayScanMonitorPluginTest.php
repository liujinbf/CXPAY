<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use app\payment\PaymentManager;
use app\payment\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;
use plugin\cxpay\alipay_scan_monitor\Driver;

require_once __DIR__ . '/../../plugins-src/alipay-scan-monitor/src/Driver.php';

final class AlipayScanMonitorPluginTest extends TestCase
{
    public function testManifestDeclaresPersonalQrCallbackPlugin(): void
    {
        $json = file_get_contents(__DIR__ . '/../../plugins-src/alipay-scan-monitor/manifest.json');
        $manifest = PluginManifest::fromJson((string)$json);

        self::assertSame('cxpay.alipay.scan_monitor', $manifest->id());
        self::assertSame('1.2.0', $manifest->version());
        self::assertSame('alipay_scan_monitor', $manifest->drivers()[0]['code']);
        self::assertSame(MonitorableDriverInterface::MODE_CALLBACK, (new Driver())->monitorMode());
        self::assertInstanceOf(AccountAuthorizationInterface::class, new Driver());
        self::assertFalse(PaymentManager::has('alipay_scan_bill'));
        self::assertArrayNotHasKey(
            'alipay_scan_bill',
            PaymentManager::getRegisteredDrivers()
        );
    }

    public function testAllowsDisabledChannelBeforeQrAuthorization(): void
    {
        $result = (new Driver())->upchannel(['status' => 0], [
            'qr_url' => 'https://qr.alipay.com/example',
            'monitor_base_url' => 'https://example.com',
            'account_id' => '',
            'client_id' => 'cxpay-client-01',
            'client_secret' => str_repeat('s', 32),
            'callback_secret' => str_repeat('c', 32),
        ]);

        self::assertSame('', $result['account_id']);
    }

    public function testRejectsEnablingChannelBeforeQrAuthorization(): void
    {
        $result = (new Driver())->upchannel(['status' => 1], [
            'qr_url' => 'https://qr.alipay.com/example',
            'monitor_base_url' => 'https://example.com',
            'account_id' => '',
            'client_id' => 'cxpay-client-01',
            'client_secret' => str_repeat('s', 32),
            'callback_secret' => str_repeat('c', 32),
        ]);

        self::assertSame(-1, $result['code']);
        self::assertStringContainsString('支付宝扫码登录', $result['msg']);
    }

    public function testAcceptsFreshSignedCallback(): void
    {
        $secret = str_repeat('a', 32);
        $params = $this->callbackParams();
        $params['sign'] = $this->sign($params, $secret);

        $result = (new Driver())->notify($params, ['callback_secret' => $secret]);

        self::assertTrue($result['success']);
        self::assertSame('CX-ALIPAY-ORDER-0001', $result['out_trade_no']);
        self::assertSame('ALIPAY-BILL-202607290001', $result['trade_no']);
        self::assertSame(18.88, $result['amount']);
    }

    public function testRejectsLegacyTokenWithoutHmacSignature(): void
    {
        $params = $this->callbackParams();
        $params['token'] = str_repeat('t', 32);

        self::assertFalse((new Driver())->notify($params, [
            'callback_secret' => str_repeat('a', 32),
        ])['success']);
    }

    public function testRejectsExpiredDeliveryEvenWhenSignatureIsCorrect(): void
    {
        $secret = str_repeat('a', 32);
        $params = $this->callbackParams();
        $params['timestamp'] = (string)(time() - 301);
        $params['sign'] = $this->sign($params, $secret);

        self::assertFalse((new Driver())->notify($params, ['callback_secret' => $secret])['success']);
    }

    public function testAcceptsPreviousCallbackSecretDuringRotation(): void
    {
        $oldSecret = str_repeat('o', 32);
        $params = $this->callbackParams();
        $params['sign'] = $this->sign($params, $oldSecret);

        self::assertTrue((new Driver())->notify($params, [
            'callback_secret' => str_repeat('n', 32),
            'callback_secret_previous' => $oldSecret,
        ])['success']);
    }

    public function testRejectsIncompleteOrWeakChannelConfiguration(): void
    {
        $result = (new Driver())->upchannel([], [
            'qr_url' => 'https://qr.alipay.com/example',
            'monitor_base_url' => 'http://127.0.0.1:8787',
            'account_id' => 'alipay-account-01',
            'client_id' => 'cxpay-client-01',
            'client_secret' => 'short',
            'callback_secret' => 'short',
        ]);

        self::assertSame(-1, $result['code']);
    }

    public function testRejectsInvalidOrderBeforeCallingCloudService(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('订单登记参数不完整');
        (new Driver())->pay([
            'trade_no' => 'CX-INVALID-ORDER',
            'out_trade_no' => 'OUT-INVALID',
            'money' => '50000.01',
            'expire_time' => time() + 300,
        ], []);
    }

    /** @return array<string, string> */
    private function callbackParams(): array
    {
        return [
            'source_bill_id' => 'ALIPAY-BILL-202607290001',
            'out_trade_no' => 'CX-ALIPAY-ORDER-0001',
            'money' => '18.88',
            'occurred_at' => (string)time(),
            'timestamp' => (string)time(),
            'nonce' => 'nonce-alipay-00000001',
        ];
    }

    /** @param array<string, string> $params */
    private function sign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params);
        return hash_hmac(
            'sha256',
            http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            $secret
        );
    }
}
