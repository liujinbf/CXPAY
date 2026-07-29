<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\UrlGuard;

final class UrlGuardTest extends TestCase
{
    public static function unsafeUrls(): array
    {
        return [
            '环回地址' => ['http://127.0.0.1/callback'],
            '私有地址' => ['https://192.168.1.10/notify'],
            '链路本地地址' => ['http://169.254.169.254/latest/meta-data'],
            '本地主机名' => ['http://localhost/callback'],
            '携带用户凭证' => ['https://user:pass@example.com/notify'],
            '不允许的协议' => ['file:///etc/passwd'],
            '不允许的端口' => ['https://8.8.8.8:8443/notify'],
            '缺少主机' => ['/relative/path'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeOutboundUrls(string $url): void
    {
        self::assertNull(UrlGuard::resolve($url));
    }

    public function testPrivateAddressCanOnlyBeEnabledExplicitly(): void
    {
        self::assertSame(
            ['host' => '192.168.1.10', 'port' => 443, 'ip' => '192.168.1.10'],
            UrlGuard::resolve('https://192.168.1.10/notify', true)
        );
    }
}
