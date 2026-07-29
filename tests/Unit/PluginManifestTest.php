<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Plugin\PluginException;
use app\payment\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class PluginManifestTest extends TestCase
{
    public function testAcceptsPersonalQrPlugin(): void
    {
        $manifest = PluginManifest::fromJson(json_encode($this->validManifest(), JSON_THROW_ON_ERROR));

        self::assertSame('cxpay.wxpay.demo', $manifest->id());
        self::assertSame('wxpay_demo', $manifest->drivers()[0]['code']);
    }

    public function testRejectsOfficialMerchantPlugin(): void
    {
        $data = $this->validManifest();
        $data['collection_mode'] = 'official_merchant';

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('个人收款码');
        PluginManifest::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testRejectsPathTraversal(): void
    {
        $data = $this->validManifest();
        $data['drivers'][0]['file'] = '../outside.php';

        $this->expectException(PluginException::class);
        PluginManifest::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testRejectsDriverFromAnotherPaymentType(): void
    {
        $data = $this->validManifest();
        $data['drivers'][0]['code'] = 'alipay_wrong_type';

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('支付类型不一致');
        PluginManifest::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function validManifest(): array
    {
        return [
            'schema' => 1,
            'id' => 'cxpay.wxpay.demo',
            'slug' => 'wxpay_demo',
            'name' => '微信测试插件',
            'version' => '1.0.0',
            'publisher' => 'cxpay.official',
            'payment_type' => 'wxpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => 'callback',
            'drivers' => [[
                'code' => 'wxpay_demo',
                'class' => 'plugin\\cxpay\\wxpay_demo\\Driver',
                'file' => 'src/Driver.php',
            ]],
        ];
    }
}
