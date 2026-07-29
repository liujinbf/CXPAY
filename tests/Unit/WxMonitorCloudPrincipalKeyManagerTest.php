<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WxMonitorCloud\Database;
use WxMonitorCloud\PrincipalKeyManager;
use WxMonitorCloud\SecretVault;

final class WxMonitorCloudPrincipalKeyManagerTest extends TestCase
{
    public function testRevokedRequestKeyCannotFallBackToLegacySecret(): void
    {
        $pdo = Database::connect('sqlite::memory:');
        $vault = new SecretVault(base64_encode(random_bytes(32)));
        $legacy = str_repeat('a', 32);
        $pdo->prepare(
            'INSERT INTO principals(id, role, request_secret, response_secret, callback_url, status, created_at)
             VALUES(?, ?, ?, ?, ?, 1, ?)'
        )->execute(['collector-key-test', 'collector', $vault->encrypt($legacy), '', '', time()]);
        $manager = new PrincipalKeyManager($pdo, $vault);
        self::assertSame([$legacy], $manager->verificationSecrets([
            'id' => 'collector-key-test',
            'request_secret' => $vault->encrypt($legacy),
        ]));

        $rotated = $manager->rotate('collector-key-test', 'request', 0, str_repeat('b', 32));
        self::assertSame([str_repeat('b', 32)], $manager->verificationSecrets([
            'id' => 'collector-key-test',
            'request_secret' => $vault->encrypt($legacy),
        ]));
        self::assertTrue($manager->revoke('collector-key-test', $rotated['id']));
        self::assertSame([], $manager->verificationSecrets([
            'id' => 'collector-key-test',
            'request_secret' => $vault->encrypt($legacy),
        ]));
        self::assertFalse($manager->revoke('collector-key-test', $rotated['id']));
    }

    public function testOnlyActiveResponseKeyCanSignAndItMustBeRotatedBeforeRevocation(): void
    {
        $pdo = Database::connect('sqlite::memory:');
        $vault = new SecretVault(base64_encode(random_bytes(32)));
        $legacyResponse = str_repeat('c', 32);
        $pdo->prepare(
            'INSERT INTO principals(id, role, request_secret, response_secret, callback_url, status, created_at)
             VALUES(?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            'client-key-test', 'client', $vault->encrypt(str_repeat('a', 32)),
            $vault->encrypt($legacyResponse), 'https://example.com/callback', time(),
        ]);
        $manager = new PrincipalKeyManager($pdo, $vault);
        $rotated = $manager->rotate('client-key-test', 'response', 60, str_repeat('d', 32), 60);
        $principal = $pdo->query("SELECT * FROM principals WHERE id = 'client-key-test'")->fetch();
        self::assertSame($legacyResponse, $manager->activeSecret($principal, 'response'));
        $keys = $manager->list('client-key-test');
        self::assertCount(2, $keys);
        try {
            $manager->rotate('client-key-test', 'response', 60, str_repeat('e', 32), 30);
            self::fail('不应允许叠加多个待生效密钥');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('已有待生效密钥', $e->getMessage());
        }
        $oldKeyId = (string)array_values(array_filter(
            $keys,
            static fn (array $key): bool => $key['id'] !== $rotated['id']
        ))[0]['id'];
        try {
            $manager->revoke('client-key-test', $oldKeyId);
            self::fail('新响应密钥生效前不应允许吊销当前密钥');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('等待新密钥生效', $e->getMessage());
        }

        $pdo->prepare('UPDATE principal_keys SET not_before = 1 WHERE id = ?')->execute([$rotated['id']]);
        self::assertSame(str_repeat('d', 32), $manager->activeSecret($principal, 'response'));
        self::assertTrue($manager->revoke('client-key-test', $oldKeyId));
    }
}
