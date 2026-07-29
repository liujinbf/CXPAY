<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use support\Authcode;

final class AuthcodeTest extends TestCase
{
    private const SECRET = 'unit-test-app-key-with-at-least-32-characters';

    public function testAesGcmRoundTripAndRandomNonce(): void
    {
        $authcode = new Authcode(self::SECRET);
        $first = $authcode->encrypt('敏感通道配置');
        $second = $authcode->encrypt('敏感通道配置');

        self::assertStringStartsWith('v2:', $first);
        self::assertNotSame($first, $second);
        self::assertSame('敏感通道配置', $authcode->decrypt($first));
        self::assertSame('敏感通道配置', $authcode->decrypt($second));
    }

    public function testAesGcmRejectsTamperedCipherText(): void
    {
        $authcode = new Authcode(self::SECRET);
        $cipherText = $authcode->encrypt('secret-value');
        $last = substr($cipherText, -1);
        $tampered = substr($cipherText, 0, -1) . ($last === 'A' ? 'B' : 'A');

        self::assertFalse($authcode->decrypt($tampered));
    }

    public function testStoredTamperedV2CipherTextFailsClosed(): void
    {
        $authcode = new Authcode(self::SECRET);
        $cipherText = $authcode->encrypt('secret-value');
        $tampered = substr($cipherText, 0, -1) . (substr($cipherText, -1) === 'A' ? 'B' : 'A');

        $this->expectException(RuntimeException::class);
        $authcode->decryptStored($tampered);
    }

    public function testStoredLegacyPlainTextRemainsReadableDuringMigration(): void
    {
        self::assertSame('legacy-plain-text', (new Authcode(self::SECRET))->decryptStored('legacy-plain-text'));
    }

    public function testEncryptionRequiresStrongAppKey(): void
    {
        $this->expectException(RuntimeException::class);
        (new Authcode('too-short'))->encrypt('secret-value');
    }

    public function testEmptyValueDoesNotRequireEncryptionKey(): void
    {
        $authcode = new Authcode('');
        self::assertSame('', $authcode->encrypt(''));
        self::assertSame('', $authcode->decrypt(''));
    }
}
