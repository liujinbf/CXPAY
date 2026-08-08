<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Port\EmailSender;
use RuntimeException;

final class FakeEmailSender implements EmailSender
{
    /** @var array<string, string> */
    private array $codes = [];

    private function __construct(private readonly bool $fails = false)
    {
    }

    public static function successful(): self
    {
        return new self();
    }

    public static function alwaysFail(): self
    {
        return new self(true);
    }

    public function sendVerificationCode(EmailAddress $email, string $code): void
    {
        if ($this->fails) {
            throw new RuntimeException('模拟邮件投递失败');
        }

        $this->codes[$email->canonical()] = $code;
    }

    public function lastCodeFor(string $emailCanonical): string
    {
        return $this->codes[$emailCanonical] ?? '';
    }
}
