<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Application\BeginTotpSetup;
use CloudControl\Identity\Application\ConfirmTotpSetup;
use CloudControl\Identity\Application\DisableTotp;
use CloudControl\Identity\Application\VerifyTotp;
use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\Totp;
use CloudControl\Identity\Domain\User;
use CloudControl\Shared\Security\SodiumSecretCipher;
use CloudControl\Tests\Fakes\InMemoryTotpSetupStore;
use CloudControl\Tests\Fakes\InMemoryTotpReplayGuard;
use CloudControl\Tests\Fakes\InMemoryUserRepository;
use CloudControl\Tests\Fakes\PassthroughTransactionManager;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TotpSetupTest extends TestCase
{
    public function testPendingSecretIsNotEnabledBeforeCorrectConfirmation(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $users = new InMemoryUserRepository();
        $user = User::pendingEmail('user-1', EmailAddress::fromString('user@example.com'), $clock->now());
        $user->completeEmailRegistration('客户用户', 'password-hash', $clock->now());
        $user->activate($clock->now());
        $users->findOrCreatePending($user);
        $setups = new InMemoryTotpSetupStore();
        $view = (new BeginTotpSetup($users, $setups, $clock))->handle(
            'user-1',
            'CXPAY Cloud',
            'user@example.com'
        );

        self::assertStringStartsWith('otpauth://totp/', $view->provisioningUri);
        self::assertFalse($users->get('user@example.com')->totpEnabled());

        $pending = $setups->find('user-1');
        self::assertNotNull($pending);
        $totp = new Totp();
        $code = $totp->at(
            \CloudControl\Shared\Security\Base32::decode($pending->secretBase32),
            $clock->now()->getTimestamp()
        );
        (new ConfirmTotpSetup(
            $users,
            $setups,
            $totp,
            new SodiumSecretCipher(str_repeat('k', 32)),
            new PassthroughTransactionManager(),
            $clock
        ))->handle('user-1', $code);

        self::assertTrue($users->get('user@example.com')->totpEnabled());
        self::assertNull($setups->find('user-1'));
    }

    public function testSameTimeStepCannotBeVerifiedTwice(): void
    {
        [$users, $clock, $cipher, $totp, $code] = $this->enabledUser();
        $verify = new VerifyTotp($users, $totp, $cipher, new InMemoryTotpReplayGuard());

        self::assertTrue($verify->handle('user-1', $code, $clock->now()));
        self::assertFalse($verify->handle('user-1', $code, $clock->now()));
    }

    public function testOfficialMemberCannotDisableTotp(): void
    {
        [$users, $clock, $cipher, $totp, $code] = $this->enabledUser();
        $disable = new DisableTotp(
            $users,
            new VerifyTotp($users, $totp, $cipher, new InMemoryTotpReplayGuard()),
            new PassthroughTransactionManager(),
            $clock
        );

        $this->expectException(\CloudControl\Shared\Error\CloudException::class);
        $disable->handle('user-1', 'Correct-Horse-2026!', $code, 'OFFICIAL');
    }

    public function testCustomerDisableRequiresCurrentPasswordAndTotp(): void
    {
        [$users, $clock, $cipher, $totp, $code] = $this->enabledUser();
        $disable = new DisableTotp(
            $users,
            new VerifyTotp($users, $totp, $cipher, new InMemoryTotpReplayGuard()),
            new PassthroughTransactionManager(),
            $clock
        );

        try {
            $disable->handle('user-1', 'wrong-password', $code, 'CUSTOMER');
            self::fail('错误密码不能停用 TOTP');
        } catch (\CloudControl\Shared\Error\CloudException) {
            self::assertTrue($users->get('user@example.com')->totpEnabled());
        }

        $disable->handle('user-1', 'Correct-Horse-2026!', $code, 'CUSTOMER');
        self::assertFalse($users->get('user@example.com')->totpEnabled());
    }

    /** @return array{InMemoryUserRepository, FrozenClock, SodiumSecretCipher, Totp, string} */
    private function enabledUser(): array
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $users = new InMemoryUserRepository();
        $user = User::pendingEmail('user-1', EmailAddress::fromString('user@example.com'), $clock->now());
        $user->completeEmailRegistration(
            '客户用户',
            password_hash('Correct-Horse-2026!', PASSWORD_ARGON2ID),
            $clock->now()
        );
        $user->activate($clock->now());
        $cipher = new SodiumSecretCipher(str_repeat('k', 32));
        $secretBase32 = \CloudControl\Shared\Security\Base32::encodeUnpadded(str_repeat('t', 20));
        $user->enableTotp($cipher->encrypt($secretBase32), $clock->now());
        $users->findOrCreatePending($user);
        $totp = new Totp();
        $code = $totp->at(str_repeat('t', 20), $clock->now()->getTimestamp());

        return [$users, $clock, $cipher, $totp, $code];
    }
}
