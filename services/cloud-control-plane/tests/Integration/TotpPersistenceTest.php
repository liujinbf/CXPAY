<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Identity\Application\BeginTotpSetup;
use CloudControl\Identity\Application\ConfirmTotpSetup;
use CloudControl\Identity\Application\VerifyTotp;
use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\Totp;
use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Infrastructure\PdoUserRepository;
use CloudControl\Identity\Infrastructure\RedisTotpReplayGuard;
use CloudControl\Identity\Infrastructure\RedisTotpSetupStore;
use CloudControl\Shared\Database\MigrationRunner;
use CloudControl\Shared\Database\PdoTransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Security\Base32;
use CloudControl\Shared\Security\SodiumSecretCipher;
use CloudControl\Tests\Support\FrozenClock;
use CloudControl\Tests\Support\MySqlTestCase;
use DateTimeImmutable;
use Predis\Client;

final class TotpPersistenceTest extends MySqlTestCase
{
    private Client $redis;
    private FrozenClock $clock;
    private PdoUserRepository $users;
    private SodiumSecretCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        if ((string)getenv('CLOUD_TEST_REDIS_HOST') === '') {
            self::markTestSkipped('未配置 Redis 测试环境');
        }
        (new MigrationRunner($this->pdo()))->migrate(dirname(__DIR__, 2) . '/migrations');
        $this->redis = new Client([
            'scheme' => 'tcp',
            'host' => (string)getenv('CLOUD_TEST_REDIS_HOST'),
            'port' => (int)getenv('CLOUD_TEST_REDIS_PORT'),
        ]);
        $this->redis->flushdb();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $this->users = new PdoUserRepository($this->pdo());
        $this->cipher = new SodiumSecretCipher(str_repeat('k', 32));
    }

    public function testConfirmedSecretIsEncryptedAndSameStepCannotReplay(): void
    {
        $user = $this->activeUser('user-1', 'user@example.com');
        $setups = new RedisTotpSetupStore($this->redis, $this->clock, 'cxpay-cloud-test:totp-setup:');
        (new BeginTotpSetup($this->users, $setups, $this->clock))->handle(
            $user->id(),
            'CXPAY Cloud',
            $user->emailCanonical()
        );
        $pending = $setups->find($user->id());
        self::assertNotNull($pending);
        $totp = new Totp();
        $code = $totp->at(Base32::decode($pending->secretBase32), $this->clock->now()->getTimestamp());
        (new ConfirmTotpSetup(
            $this->users,
            $setups,
            $totp,
            $this->cipher,
            new PdoTransactionManager($this->pdo()),
            $this->clock
        ))->handle($user->id(), $code);

        $row = $this->pdo()->query(
            'SELECT totp_secret_ciphertext, totp_secret_nonce, totp_enabled_at FROM cloud_users WHERE id = '
            . $this->pdo()->quote($user->id())
        )->fetch();
        self::assertNotNull($row['totp_secret_ciphertext']);
        self::assertNotNull($row['totp_secret_nonce']);
        self::assertNotNull($row['totp_enabled_at']);
        self::assertStringNotContainsString($pending->secretBase32, (string)$row['totp_secret_ciphertext']);

        $verify = new VerifyTotp(
            $this->users,
            $totp,
            $this->cipher,
            new RedisTotpReplayGuard($this->redis, 'cxpay-cloud-test:totp-replay:')
        );
        self::assertTrue($verify->handle($user->id(), $code, $this->clock->now()));
        self::assertFalse($verify->handle($user->id(), $code, $this->clock->now()));
    }

    public function testWrongCodeDoesNotEnableTotp(): void
    {
        $user = $this->activeUser('user-2', 'other@example.com');
        $setups = new RedisTotpSetupStore($this->redis, $this->clock, 'cxpay-cloud-test:totp-setup:');
        (new BeginTotpSetup($this->users, $setups, $this->clock))->handle(
            $user->id(),
            'CXPAY Cloud',
            $user->emailCanonical()
        );
        $pending = $setups->find($user->id());
        self::assertNotNull($pending);
        $validCode = (new Totp())->at(
            Base32::decode($pending->secretBase32),
            $this->clock->now()->getTimestamp()
        );
        $wrongCode = $validCode === '000000' ? '000001' : '000000';

        try {
            (new ConfirmTotpSetup(
                $this->users,
                $setups,
                new Totp(),
                $this->cipher,
                new PdoTransactionManager($this->pdo()),
                $this->clock
            ))->handle($user->id(), $wrongCode);
            self::fail('错误 TOTP 不应启用');
        } catch (CloudException) {
        }

        self::assertFalse($this->users->findById($user->id())?->totpEnabled());
    }

    private function activeUser(string $id, string $email): User
    {
        $user = User::pendingEmail($id, EmailAddress::fromString($email), $this->clock->now());
        $user->completeEmailRegistration(
            '客户用户',
            password_hash('Correct-Horse-2026!', PASSWORD_ARGON2ID),
            $this->clock->now()
        );
        $user->activate($this->clock->now());
        (new PdoTransactionManager($this->pdo()))->run(function () use ($user): void {
            $this->users->findOrCreatePending($user);
            $this->users->save($user);
        });
        return $user;
    }
}
