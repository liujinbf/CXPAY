<?php

declare(strict_types=1);

namespace Tests\Unit;

use AlipayMonitorCloud\Application;
use AlipayMonitorCloud\Database;
use AlipayMonitorCloud\PrincipalKeyManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../services/alipay-monitor-cloud/src/Database.php';
require_once __DIR__ . '/../../services/alipay-monitor-cloud/src/PrincipalKeyManager.php';
require_once __DIR__ . '/../../services/alipay-monitor-cloud/src/Application.php';

final class AlipayMonitorCloudTest extends TestCase
{
    private string $tempDir;
    private string $dsn;
    private string $masterKey;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amc-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0700, true);
        $this->dsn = 'sqlite:' . $this->tempDir . DIRECTORY_SEPARATOR . 'amc.sqlite';
        $this->masterKey = base64_encode(random_bytes(32));
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testDatabaseInitializationAndKeyManager(): void
    {
        $db = new Database($this->dsn);
        $keyManager = new PrincipalKeyManager($db->pdo(), base64_decode($this->masterKey));

        $keyManager->setKey('client_001', 'request', str_repeat('k', 32));
        $retrieved = $keyManager->getActiveRequestSecret('client_001');

        self::assertSame(str_repeat('k', 32), $retrieved);
    }

    public function testApplicationSignedRequestHandling(): void
    {
        $db = new Database($this->dsn);
        $keyManager = new PrincipalKeyManager($db->pdo(), base64_decode($this->masterKey));
        $clientSecret = str_repeat('s', 32);
        $keyManager->setKey('client_test', 'request', $clientSecret);

        $app = new Application($db, $this->masterKey);

        $body = json_encode(['out_trade_no' => 'AMC-ORD-1001', 'amount' => '100.00', 'expires_at' => time() + 300]);
        $timestamp = (string)time();
        $nonce = 'nonce_12345678';
        $canonical = implode("\n", ['POST', '/v1/orders', $timestamp, $nonce, hash('sha256', $body)]);
        $sign = hash_hmac('sha256', $canonical, $clientSecret);

        $headers = [
            'X-CXPAY-Client'    => 'client_test',
            'X-CXPAY-Timestamp' => $timestamp,
            'X-CXPAY-Nonce'     => $nonce,
            'X-CXPAY-Signature' => $sign,
        ];

        [$status, $res] = $app->handle('POST', '/v1/orders', $headers, $body);

        self::assertSame(200, $status);
        self::assertSame('AMC-ORD-1001', $res['out_trade_no']);
    }
}
