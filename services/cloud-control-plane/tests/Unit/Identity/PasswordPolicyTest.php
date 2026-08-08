<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Domain\PasswordPolicy;
use CloudControl\Shared\Error\CloudException;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testUsesArgon2idForValidPassword(): void
    {
        $policy = new PasswordPolicy();
        $hash = $policy->hash('Correct-Horse-2026!');

        self::assertTrue(password_verify('Correct-Horse-2026!', $hash));
        self::assertSame(PASSWORD_ARGON2ID, password_get_info($hash)['algo']);
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(CloudException::class);

        (new PasswordPolicy())->hash('short');
    }
}
