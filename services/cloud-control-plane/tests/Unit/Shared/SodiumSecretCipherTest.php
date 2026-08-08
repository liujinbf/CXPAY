<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Shared;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Security\EncryptedSecret;
use CloudControl\Shared\Security\SodiumSecretCipher;
use PHPUnit\Framework\TestCase;

final class SodiumSecretCipherTest extends TestCase
{
    public function testRoundTripsAndUsesFreshNonce(): void
    {
        $cipher = new SodiumSecretCipher(str_repeat('k', 32));
        $first = $cipher->encrypt('JBSWY3DPEHPK3PXP');
        $second = $cipher->encrypt('JBSWY3DPEHPK3PXP');

        self::assertNotSame($first->nonce, $second->nonce);
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($first));
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($second));
    }

    public function testRejectsTamperedCiphertextWithoutLeakingSodiumError(): void
    {
        $cipher = new SodiumSecretCipher(str_repeat('k', 32));
        $secret = $cipher->encrypt('secret');
        $tampered = new EncryptedSecret($secret->ciphertext . 'A', $secret->nonce);

        try {
            $cipher->decrypt($tampered);
            self::fail('篡改后的密文不应解密成功');
        } catch (CloudException $exception) {
            self::assertSame('安全数据无法解密', $exception->safeMessage());
            self::assertStringNotContainsString('sodium', strtolower($exception->safeMessage()));
        }
    }

    public function testRequiresExactlyThirtyTwoByteKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SodiumSecretCipher('too-short');
    }
}
