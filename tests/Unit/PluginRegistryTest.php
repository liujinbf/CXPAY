<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Plugin\PluginManifest;
use app\payment\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

final class PluginRegistryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cxpay-registry-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testInstalledPluginDefaultsToDisabled(): void
    {
        $registry = new PluginRegistry($this->directory . '/registry.json');
        $manifest = PluginManifest::fromJson(json_encode([
            'schema' => 1,
            'id' => 'cxpay.alipay.demo',
            'slug' => 'alipay_demo',
            'name' => '支付宝测试插件',
            'version' => '1.2.3',
            'publisher' => 'cxpay.official',
            'payment_type' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => 'push',
            'drivers' => [[
                'code' => 'alipay_demo',
                'class' => 'plugin\\cxpay\\alipay_demo\\Driver',
                'file' => 'src/Driver.php',
            ]],
        ], JSON_THROW_ON_ERROR));

        $registry->recordInstall($manifest, '/safe/plugin/path');
        self::assertFalse($registry->isEnabled($manifest->id()));

        $registry->setEnabled($manifest->id(), true);
        self::assertTrue($registry->isEnabled($manifest->id()));
        self::assertSame('1.2.3', $registry->get($manifest->id())['active_version']);
    }
}
