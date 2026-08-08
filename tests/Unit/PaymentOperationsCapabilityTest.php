<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Contracts\OperationsStatusInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\PaymentEventReviewInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../plugins-src/wxpay-clerk-adapter/src/Driver.php';
require_once __DIR__ . '/../../plugins-src/wxpay-cloud-adapter/src/Driver.php';
require_once __DIR__ . '/../../plugins-src/alipay-scan-monitor/src/Driver.php';

final class PaymentOperationsCapabilityTest extends TestCase
{
    public function testCloudAndClerkDriversDeclareReviewAndOperationsCapabilities(): void
    {
        $drivers = [
            new \plugin\cxpay\wxpay_clerk_adapter\Driver(),
            new \plugin\cxpay\wxpay_cloud_adapter\Driver(),
            new \plugin\cxpay\alipay_scan_monitor\Driver(),
        ];

        foreach ($drivers as $driver) {
            self::assertInstanceOf(PaymentEventReviewInterface::class, $driver);
            self::assertInstanceOf(OperationsStatusInterface::class, $driver);
        }
    }

    public function testOrdinaryPaymentDriverDoesNotGainOperationsCapabilities(): void
    {
        $driver = new OrdinaryPaymentDriver();

        self::assertNotInstanceOf(PaymentEventReviewInterface::class, $driver);
        self::assertNotInstanceOf(OperationsStatusInterface::class, $driver);
    }
}

final class OrdinaryPaymentDriver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array { return []; }
    public function notify(array $params, array $config): array { return []; }
    public function query(string $tradeNo, array $config): array { return ['paid' => false]; }
    public function getMeta(): array { return ['name' => 'ordinary']; }
    public function upchannel(array $channelRow, array $config): array { return $config; }
}
