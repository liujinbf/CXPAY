<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WxpayClerk\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__, 2)
        . '/services/wxpay-clerk-service/src/'
        . str_replace('\\', '/', $relative)
        . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

abstract class WxpayClerkDatabaseTestCase extends TestCase
{
    protected string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'cxpay-wxclerk-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }
}
