<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\PaymentManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentManagerTest extends TestCase
{
    #[DataProvider('personalQrDriverProvider')]
    public function testPersonalQrDriverIsAvailable(string $driverName, string $monitorMode): void
    {
        self::assertTrue(PaymentManager::has($driverName));
        self::assertSame($driverName, PaymentManager::make($driverName)->getMeta()['name']);
        self::assertSame($monitorMode, PaymentManager::monitorMode($driverName));
    }

    #[DataProvider('merchantDriverProvider')]
    public function testMerchantPaymentDriverCannotBeSelected(string $driverName): void
    {
        self::assertFalse(PaymentManager::has($driverName));
        self::assertArrayNotHasKey($driverName, PaymentManager::getRegisteredDrivers());
    }

    #[DataProvider('epayDriverProvider')]
    public function testEpayDriverIsAvailableAndHasRequiredInputs(string $driverName): void
    {
        self::assertTrue(PaymentManager::has($driverName), "易支付驱动 [{$driverName}] 应已启用");
        $meta = PaymentManager::make($driverName)->getMeta();
        self::assertSame($driverName, $meta['name']);
        self::assertTrue($meta['available'] ?? false, "驱动 available 应为 true");
        $inputNames = array_column($meta['inputs'] ?? [], 'name');
        foreach (['api_url', 'pid', 'key'] as $required) {
            self::assertContains($required, $inputNames, "驱动 [{$driverName}] 缺少必填配置项 [{$required}]");
        }
    }

    public function testUnknownDriverFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentManager::make('driver_that_does_not_exist');
    }

    public function testDisabledMerchantDriverFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaymentManager::make('alipay_official');
    }

    public function testPluginCannotOverrideBuiltinDriverCode(): void
    {
        self::assertTrue(PaymentManager::has('alipay_app_asst'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('冲突');
        PaymentManager::registerPluginDriver(
            'alipay_app_asst',
            FakePluginPaymentDriver::class,
            'cxpay.alipay.malicious'
        );
    }

    #[DataProvider('assistantDriverProvider')]
    public function testAssistantDriversRequireBoundDevice(
        string $driverName,
        string $qrField
    ): void {
        $config = [
            $qrField => 'https://pay.example.com/static/qr-content',
            'notify_secret' => str_repeat('s', 32),
        ];

        $rejected = PaymentManager::make($driverName)->upchannel([], $config);
        self::assertSame(-1, $rejected['code']);

        $config['device_id'] = 'ANDROID_device-01';
        $accepted = PaymentManager::make($driverName)->upchannel([], $config);
        self::assertSame($config, $accepted);
    }

    public function testAssistantDriverRejectsWeakPushSecret(): void
    {
        $result = PaymentManager::make('alipay_app_asst')->upchannel([], [
            'qr_code_url' => 'https://qr.alipay.com/example',
            'device_id' => 'ANDROID_device-01',
            'notify_secret' => 'too-short',
        ]);

        self::assertSame(-1, $result['code']);
        self::assertStringContainsString('32至128', $result['msg']);
    }

    public function testAssistantDriverNeverUsesFallbackQrCode(): void
    {
        $result = PaymentManager::make('wxpay_app_asst')->pay([
            'trade_no' => 'CX-1',
            'out_trade_no' => 'OUT-1',
            'money' => '10.01',
        ], []);

        self::assertSame('', $result['pay_url']);
    }

    public static function assistantDriverProvider(): array
    {
        return [
            '支付宝安卓助手' => ['alipay_app_asst', 'qr_code_url'],
            '微信安卓助手' => ['wxpay_app_asst', 'qr_code_url'],
            '微信PC助手' => ['wxpay_recpt_afk_pc', 'qr_code_url'],
            'QQ安卓助手' => ['qqpay_app_asst', 'qr_url'],
        ];
    }

    public static function personalQrDriverProvider(): array
    {
        return [
            '支付宝安卓监控' => ['alipay_app_asst', MonitorableDriverInterface::MODE_PUSH],
            '支付宝旧版外部账单回调' => ['alipay_scan_bill', MonitorableDriverInterface::MODE_CALLBACK],
            '微信安卓监控' => ['wxpay_app_asst', MonitorableDriverInterface::MODE_PUSH],
            '微信PC监控' => ['wxpay_recpt_afk_pc', MonitorableDriverInterface::MODE_PUSH],
            '微信外部账单回调' => ['wxpay_protocol_cloud', MonitorableDriverInterface::MODE_CALLBACK],
            'QQ安卓监控' => ['qqpay_app_asst', MonitorableDriverInterface::MODE_PUSH],
            'QQ外部账单回调' => ['qqpay_protocol_cloud', MonitorableDriverInterface::MODE_CALLBACK],
        ];
    }

    public static function merchantDriverProvider(): array
    {
        // 官方商户驱动需要外部 SDK 初始化，保持 available=false 不可直接选中
        return [
            '支付宝官方商户支付' => ['alipay_official'],
            '微信官方商户支付' => ['wxpay_official'],
        ];
    }

    public static function epayDriverProvider(): array
    {
        return [
            '通用易支付 MD5 驱动' => ['epay_generic'],
            'QQ 钱包易支付驱动'   => ['qqpay_epay'],
        ];
    }
}

final class FakePluginPaymentDriver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        return [];
    }

    public function notify(array $params, array $config): array
    {
        return [];
    }

    public function query(string $tradeNo, array $config): array
    {
        return [];
    }

    public function getMeta(): array
    {
        return ['name' => 'alipay_app_asst'];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        return $config;
    }
}
