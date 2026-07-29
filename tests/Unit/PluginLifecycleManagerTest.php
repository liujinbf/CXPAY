<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Plugin\PluginLifecycleManager;
use app\payment\Plugin\PluginManifest;
use app\payment\Plugin\PluginRegistry;
use PHPUnit\Framework\TestCase;

final class PluginLifecycleManagerTest extends TestCase
{
    private string $directory;
    private string $pluginRoot;
    private PluginRegistry $registry;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cxpay-lifecycle-' . bin2hex(random_bytes(6));
        $this->pluginRoot = $this->directory . '/plugins';
        mkdir($this->pluginRoot, 0700, true);
        $this->registry = new PluginRegistry($this->directory . '/registry.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testRollbackSwitchesActiveVersionOnlyWhenDisabled(): void
    {
        $this->installVersion('1.0.0');
        $this->installVersion('1.1.0');

        $result = (new PluginLifecycleManager($this->pluginRoot, $this->registry))
            ->rollback('cxpay.wxpay.lifecycle', '1.0.0');

        self::assertSame('1.0.0', $result['version']);
        self::assertSame('1.0.0', $this->registry->get('cxpay.wxpay.lifecycle')['active_version']);
        self::assertCount(2, $this->registry->get('cxpay.wxpay.lifecycle')['versions']);
    }

    public function testUninstallRemovesAllVersionsAndRegistry(): void
    {
        $this->installVersion('1.0.0');
        $this->installVersion('1.1.0');

        (new PluginLifecycleManager($this->pluginRoot, $this->registry))
            ->uninstall('cxpay.wxpay.lifecycle');

        self::assertNull($this->registry->get('cxpay.wxpay.lifecycle'));
        self::assertDirectoryDoesNotExist($this->pluginRoot . '/wxpay_lifecycle');
    }

    private function installVersion(string $version): void
    {
        $data = [
            'schema' => 1,
            'id' => 'cxpay.wxpay.lifecycle',
            'slug' => 'wxpay_lifecycle',
            'name' => '生命周期测试插件',
            'version' => $version,
            'publisher' => 'cxpay.official',
            'payment_type' => 'wxpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => 'callback',
            'drivers' => [[
                'code' => 'wxpay_lifecycle',
                'class' => 'plugin\\cxpay\\wxpay_lifecycle\\Driver',
                'file' => 'src/Driver.php',
            ]],
        ];
        $manifest = PluginManifest::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
        $path = $this->pluginRoot . '/wxpay_lifecycle/' . $version;
        mkdir($path . '/src', 0700, true);
        file_put_contents($path . '/manifest.json', json_encode($data, JSON_THROW_ON_ERROR));
        file_put_contents($path . '/src/Driver.php', '<?php');
        $this->registry->recordInstall($manifest, $path);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
