<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Contracts\AccountCapabilityDetectorInterface;
use app\payment\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;
use plugin\cxpay\wxpay_cloud_adapter\Driver;

require_once __DIR__ . '/../../plugins-src/wxpay-cloud-adapter/src/Driver.php';

final class WxpayCloudAdapterPluginTest extends TestCase
{
    public function testSourceManifestIsValid(): void
    {
        $json = file_get_contents(__DIR__ . '/../../plugins-src/wxpay-cloud-adapter/manifest.json');
        $manifest = PluginManifest::fromJson((string)$json);

        self::assertSame('cxpay.wxpay.cloud_adapter', $manifest->id());
        self::assertSame('1.3.0', $manifest->version());
        self::assertSame('wxpay_cloud_adapter', $manifest->drivers()[0]['code']);
    }

    public function testAcceptsValidSignedPaymentCallback(): void
    {
        $secret = str_repeat('c', 32);
        $params = [
            'source_bill_id' => 'WX-BILL-10001',
            'out_trade_no' => 'CX-TRADE-10001',
            'money' => '10.01',
            'occurred_at' => (string)time(),
            'timestamp' => (string)time(),
            'nonce' => 'nonce-10001',
        ];
        $signed = $params;
        ksort($signed);
        $params['sign'] = hash_hmac(
            'sha256',
            http_build_query($signed, '', '&', PHP_QUERY_RFC3986),
            $secret
        );

        $result = (new Driver())->notify($params, ['callback_secret' => $secret]);

        self::assertTrue($result['success']);
        self::assertSame('CX-TRADE-10001', $result['out_trade_no']);
        self::assertSame('WX-BILL-10001', $result['trade_no']);
        self::assertSame(10.01, $result['amount']);
    }

    public function testRejectsExpiredPaymentCallback(): void
    {
        $secret = str_repeat('c', 32);
        $params = [
            'source_bill_id' => 'WX-BILL-OLD',
            'out_trade_no' => 'CX-TRADE-OLD',
            'money' => '1.00',
            'occurred_at' => (string)(time() - 301),
            'timestamp' => (string)(time() - 301),
            'nonce' => 'nonce-old',
        ];
        $signed = $params;
        ksort($signed);
        $params['sign'] = hash_hmac('sha256', http_build_query($signed, '', '&', PHP_QUERY_RFC3986), $secret);

        self::assertFalse((new Driver())->notify($params, ['callback_secret' => $secret])['success']);
    }

    public function testAcceptsPreviousCallbackSecretDuringRotationGracePeriod(): void
    {
        $oldSecret = str_repeat('o', 32);
        $params = [
            'source_bill_id' => 'WX-BILL-ROTATION',
            'out_trade_no' => 'CX-TRADE-ROTATION',
            'money' => '3.00',
            'occurred_at' => (string)time(),
            'timestamp' => (string)time(),
            'nonce' => 'nonce-rotation-001',
        ];
        $signed = $params;
        ksort($signed);
        $params['sign'] = hash_hmac(
            'sha256',
            http_build_query($signed, '', '&', PHP_QUERY_RFC3986),
            $oldSecret
        );

        $result = (new Driver())->notify($params, [
            'callback_secret' => str_repeat('n', 32),
            'callback_secret_previous' => $oldSecret,
        ]);
        self::assertTrue($result['success']);
    }

    public function testAcceptsDelayedBillWithFreshDeliveryTimestamp(): void
    {
        $secret = str_repeat('c', 32);
        $params = [
            'source_bill_id' => 'WX-BILL-DELAYED',
            'out_trade_no' => 'CX-TRADE-DELAYED',
            'money' => '2.00',
            'occurred_at' => (string)(time() - 1800),
            'timestamp' => (string)time(),
            'nonce' => 'nonce-delayed',
        ];
        $signed = $params;
        ksort($signed);
        $params['sign'] = hash_hmac('sha256', http_build_query($signed, '', '&', PHP_QUERY_RFC3986), $secret);

        self::assertTrue((new Driver())->notify($params, ['callback_secret' => $secret])['success']);
    }

    public function testUnavailableProviderNeverBecomesNotOpened(): void
    {
        $result = (new Driver())->detectAccountCapabilities([]);

        self::assertSame(AccountCapabilityDetectorInterface::STATUS_TEMPORARY_ERROR, $result['status']);
        self::assertNotSame(AccountCapabilityDetectorInterface::STATUS_RECEIPT_NOT_OPENED, $result['status']);
    }
}
