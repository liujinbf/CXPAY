<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\CloudInstanceClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CloudClientSoftwareDistributionTest extends TestCase
{
    private string $tempIdentityFile;
    private string $tempPublicDir;

    protected function setUp(): void
    {
        $this->tempIdentityFile = sys_get_temp_dir() . '/test_instance_' . bin2hex(random_bytes(6)) . '.json';
        $this->tempPublicDir = sys_get_temp_dir() . '/test_public_' . bin2hex(random_bytes(6));
        @mkdir($this->tempPublicDir . '/download', 0777, true);
        @mkdir($this->tempPublicDir . '/downloads', 0777, true);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempIdentityFile)) {
            @unlink($this->tempIdentityFile);
        }
        $this->recursiveRmdir($this->tempPublicDir);
    }

    public function testRejectsUnsupportedSoftwareType(): void
    {
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
        $this->expectException(InvalidArgumentException::class);
        $client->ensureClientSoftware('unsupported_binary_package');
    }

    public function testReturnsLocalCachedSoftwareWhenPresent(): void
    {
        // 模拟本地已存在 > 10KB 的 APK 母包
        $localApk = public_path() . '/download/CXPayAssistant.apk';
        $alreadyExisted = file_exists($localApk);
        $originalContent = $alreadyExisted ? file_get_contents($localApk) : null;

        @mkdir(dirname($localApk), 0777, true);
        $dummyPayload = str_repeat('X', 1024 * 15);
        file_put_contents($localApk, $dummyPayload);

        try {
            $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
            $path = $client->ensureClientSoftware('cxpay_assistant_apk');

            $this->assertNotNull($path);
            $this->assertSame($localApk, $path);
            $this->assertGreaterThan(10240, filesize($path));
        } finally {
            if ($alreadyExisted && $originalContent !== null) {
                file_put_contents($localApk, $originalContent);
            } elseif (!$alreadyExisted && file_exists($localApk)) {
                @unlink($localApk);
            }
        }
    }

    public function testDownloadsClientSoftwareFromCloudWhenMissing(): void
    {
        $dummyZipContent = str_repeat('PK', 1024 * 8); // 16KB mock zip
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/zip'], $dummyZipContent),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com', $httpClient);

        // 确保目标文件先不存在
        $targetPcZip = public_path() . '/downloads/CXPayMonitor-v1.3.5-Release.zip';
        $backup = null;
        if (file_exists($targetPcZip)) {
            $backup = file_get_contents($targetPcZip);
            @unlink($targetPcZip);
        }

        try {
            $path = $client->ensureClientSoftware('cxpay_monitor_pc');
            $this->assertNotNull($path);
            $this->assertSame($targetPcZip, $path);
            $this->assertFileExists($targetPcZip);
            $this->assertGreaterThan(10240, filesize($targetPcZip));
        } finally {
            if ($backup !== null) {
                file_put_contents($targetPcZip, $backup);
            } elseif (file_exists($targetPcZip)) {
                @unlink($targetPcZip);
            }
        }
    }

    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->recursiveRmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
