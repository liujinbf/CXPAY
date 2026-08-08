<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Domain\Totp;
use CloudControl\Shared\Security\Base32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    #[DataProvider('rfc6238Sha1Vectors')]
    public function testMatchesRfc6238Sha1Vectors(int $timestamp, string $expected): void
    {
        $totp = new Totp(period: 30, digits: 8, algorithm: 'sha1');

        self::assertSame($expected, $totp->at('12345678901234567890', $timestamp));
    }

    /** @return iterable<string, array{int, string}> */
    public static function rfc6238Sha1Vectors(): iterable
    {
        yield '59 秒' => [59, '94287082'];
        yield '1111111109 秒' => [1111111109, '07081804'];
        yield '1111111111 秒' => [1111111111, '14050471'];
        yield '1234567890 秒' => [1234567890, '89005924'];
        yield '2000000000 秒' => [2000000000, '69279037'];
        yield '20000000000 秒' => [20000000000, '65353130'];
    }

    public function testVerificationWindowReturnsExactMatchingTimeStep(): void
    {
        $totp = new Totp(period: 30, digits: 6, algorithm: 'sha1');
        $timestamp = 1700000000;
        $code = $totp->at('12345678901234567890', $timestamp - 30);

        self::assertSame(
            intdiv($timestamp, 30) - 1,
            $totp->matchingStep('12345678901234567890', $code, $timestamp, 1)
        );
        self::assertNull($totp->matchingStep('12345678901234567890', '000000', $timestamp, 1));
    }

    public function testOfficialTenantRequiresTotp(): void
    {
        $totp = new Totp();

        self::assertTrue($totp->requiredForTenantType('OFFICIAL'));
        self::assertFalse($totp->requiredForTenantType('CUSTOMER'));
        self::assertFalse($totp->requiredForTenantType('AGENT'));
    }

    public function testTwentyByteSecretMatchesRfc4648Base32Encoding(): void
    {
        $expected = 'OR2HI5DUOR2HI5DUOR2HI5DUOR2HI5DU';

        self::assertSame($expected, Base32::encodeUnpadded(str_repeat('t', 20)));
        self::assertSame(str_repeat('t', 20), Base32::decode($expected));
    }
}
