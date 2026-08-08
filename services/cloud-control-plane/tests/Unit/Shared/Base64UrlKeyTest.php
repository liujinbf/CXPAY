<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Shared;

use CloudControl\Shared\Security\Base64UrlKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Base64UrlKeyTest extends TestCase
{
    public function testDecodesUnpaddedThirtyTwoByteKey(): void
    {
        $encoded = rtrim(strtr(base64_encode(str_repeat('k', 32)), '+/', '-_'), '=');

        self::assertSame(str_repeat('k', 32), Base64UrlKey::decode($encoded, 'TEST_KEY'));
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsMissingMalformedOrWrongLengthKey(string $encoded): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TEST_KEY');

        Base64UrlKey::decode($encoded, 'TEST_KEY');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield '空值' => [''];
        yield '非法字符' => ['***'];
        yield '长度不足' => [rtrim(strtr(base64_encode(str_repeat('k', 31)), '+/', '-_'), '=')];
        yield '禁止填充' => [base64_encode(str_repeat('k', 32))];
    }
}
