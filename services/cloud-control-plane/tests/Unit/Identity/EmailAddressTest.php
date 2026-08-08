<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Shared\Error\CloudException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testCanonicalizesWholeAddressAndIdnaDomain(): void
    {
        $email = EmailAddress::fromString('  User@例子.测试  ');

        self::assertSame('user@xn--fsqu00a.xn--0zwm56d', $email->canonical());
        self::assertSame('User@例子.测试', $email->display());
    }

    #[DataProvider('invalidEmails')]
    public function testRejectsInvalidEmail(string $email): void
    {
        $this->expectException(CloudException::class);

        EmailAddress::fromString($email);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmails(): iterable
    {
        yield '空字符串' => [''];
        yield '缺少域名' => ['user@'];
        yield '缺少本地部分' => ['@example.com'];
        yield '包含多个分隔符' => ['user@@example.com'];
    }
}
