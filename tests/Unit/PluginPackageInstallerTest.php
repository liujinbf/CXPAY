<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Plugin\PluginException;
use app\payment\Plugin\PluginPackageInstaller;
use app\payment\Plugin\PluginRegistry;
use PharData;
use PHPUnit\Framework\TestCase;

final class PluginPackageInstallerTest extends TestCase
{
    private string $directory;
    private string $pluginRoot;
    private string $keysDirectory;
    private PluginRegistry $registry;
    private $privateKey;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cxpay-package-' . bin2hex(random_bytes(6));
        $this->pluginRoot = $this->directory . '/plugins';
        $this->keysDirectory = $this->directory . '/keys';
        mkdir($this->pluginRoot, 0700, true);
        mkdir($this->keysDirectory, 0700, true);
        $this->registry = new PluginRegistry($this->directory . '/registry.json');
        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($this->privateKey);
        $details = openssl_pkey_get_details($this->privateKey);
        file_put_contents($this->keysDirectory . '/cxpay.official.pem', $details['key']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testInstallsValidSignedPackageAsDisabled(): void
    {
        $package = $this->createPackage(false);
        $result = $this->installer()->install($package);

        self::assertSame('cxpay.wxpay.signed_demo', $result['id']);
        self::assertFalse($result['enabled']);
        self::assertFileExists($this->pluginRoot . '/wxpay_signed_demo/1.0.0/manifest.json');
        self::assertFalse($this->registry->isEnabled($result['id']));
    }

    public function testRejectsPackageModifiedAfterSigning(): void
    {
        $package = $this->createPackage(true);

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('哈希校验失败');
        $this->installer()->install($package);
    }

    public function testRejectsValidlySignedPackageUsingRemovedDriverCode(): void
    {
        $package = $this->createPackage(false, 'wxpay_protocol_cloud');

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('已永久移除');
        $this->installer()->install($package);
    }

    private function installer(): PluginPackageInstaller
    {
        return new PluginPackageInstaller(
            $this->pluginRoot,
            $this->keysDirectory,
            $this->registry,
            2 * 1024 * 1024,
            512 * 1024,
            20,
        );
    }

    private function createPackage(
        bool $tamper,
        string $driverCode = 'wxpay_signed_demo'
    ): string
    {
        $manifest = json_encode([
            'schema' => 1,
            'id' => 'cxpay.wxpay.signed_demo',
            'slug' => 'wxpay_signed_demo',
            'name' => '微信签名测试插件',
            'version' => '1.0.0',
            'publisher' => 'cxpay.official',
            'payment_type' => 'wxpay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => 'callback',
            'drivers' => [[
                'code' => $driverCode,
                'class' => 'plugin\\cxpay\\wxpay_signed_demo\\Driver',
                'file' => 'src/Driver.php',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $driver = "<?php\nnamespace plugin\\cxpay\\wxpay_signed_demo;\nfinal class Driver {}\n";
        $hashes = [
            'manifest.json' => hash('sha256', $manifest),
            'src/Driver.php' => hash('sha256', $driver),
        ];
        $payload = PluginPackageInstaller::canonicalJson([
            'algorithm' => 'rsa-sha256',
            'publisher' => 'cxpay.official',
            'files' => $hashes,
        ]);
        openssl_sign($payload, $rawSignature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $signature = json_encode([
            'algorithm' => 'rsa-sha256',
            'publisher' => 'cxpay.official',
            'files' => $hashes,
            'signature' => base64_encode($rawSignature),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $zip = $this->directory . '/source-' . bin2hex(random_bytes(4)) . '.zip';
        $archive = new PharData($zip, 0, null, \Phar::ZIP);
        $archive->addFromString('manifest.json', $manifest);
        $archive->addFromString('signature.json', $signature);
        $archive->addFromString('src/Driver.php', $tamper ? $driver . "// tampered\n" : $driver);
        unset($archive);
        $package = substr($zip, 0, -4) . '.cxpay-plugin';
        rename($zip, $package);
        return $package;
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
