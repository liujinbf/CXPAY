<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Identity\Application\CompleteEmailRegistration;
use CloudControl\Identity\Application\CompleteEmailRegistrationCommand;
use CloudControl\Identity\Application\RegistrationChallenge;
use CloudControl\Identity\Application\RequestEmailCode;
use CloudControl\Identity\Application\RequestEmailCodeCommand;
use CloudControl\Identity\Domain\PasswordPolicy;
use CloudControl\Identity\Infrastructure\PdoEmailVerificationRepository;
use CloudControl\Identity\Infrastructure\PdoUserRepository;
use CloudControl\Identity\Port\RegistrationChallengeStore;
use CloudControl\Shared\Database\MigrationRunner;
use CloudControl\Shared\Database\PdoTransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Tests\Fakes\FakeEmailSender;
use CloudControl\Tests\Fakes\InMemoryRateLimiter;
use CloudControl\Tests\Fakes\InMemoryRegistrationChallengeStore;
use CloudControl\Tests\Fakes\SequentialIdGenerator;
use CloudControl\Tests\Support\FrozenClock;
use CloudControl\Tests\Support\MySqlTestCase;
use DateTimeImmutable;
use RuntimeException;

final class EmailRegistrationPersistenceTest extends MySqlTestCase
{
    private FakeEmailSender $sender;
    private FrozenClock $clock;
    private SequentialIdGenerator $ids;

    protected function setUp(): void
    {
        parent::setUp();
        (new MigrationRunner($this->pdo()))->migrate(dirname(__DIR__, 2) . '/migrations');
        $this->sender = FakeEmailSender::successful();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $this->ids = new SequentialIdGenerator();
    }

    public function testSuccessfulRegistrationPersistsPendingIdentityAndConsumesCode(): void
    {
        $this->requestCode();
        $this->complete(new InMemoryRegistrationChallengeStore())->handle($this->validCommand());

        $user = $this->pdo()->query(
            "SELECT status, email_verified_at, password_hash FROM cloud_users WHERE email_canonical = 'user@example.com'"
        )->fetch();
        $verification = $this->pdo()->query(
            'SELECT consumed_at FROM cloud_email_verifications ORDER BY created_at DESC LIMIT 1'
        )->fetch();

        self::assertSame('PENDING_IDENTITY', $user['status']);
        self::assertNotNull($user['email_verified_at']);
        self::assertTrue(password_verify('Correct-Horse-2026!', $user['password_hash']));
        self::assertNotNull($verification['consumed_at']);
    }

    public function testWrongCodeCommitsAttemptWithoutConsumingRecord(): void
    {
        $this->requestCode();
        $command = $this->validCommand();
        $wrong = $command->code === '000000' ? '000001' : '000000';

        try {
            $this->complete(new InMemoryRegistrationChallengeStore())->handle(
                new CompleteEmailRegistrationCommand(
                    $command->email,
                    $wrong,
                    $command->displayName,
                    $command->password,
                    $command->requestedIp
                )
            );
            self::fail('错误验证码不应通过');
        } catch (CloudException) {
        }

        $record = $this->pdo()->query(
            'SELECT attempts, consumed_at FROM cloud_email_verifications ORDER BY created_at DESC LIMIT 1'
        )->fetch();
        self::assertSame(1, (int)$record['attempts']);
        self::assertNull($record['consumed_at']);
    }

    public function testChallengeStoreFailureRollsBackUserAndVerification(): void
    {
        $this->requestCode();
        $store = new class implements RegistrationChallengeStore {
            public function save(RegistrationChallenge $challenge): void
            {
                throw new RuntimeException('Redis 不可用');
            }
            public function find(string $rawToken): ?RegistrationChallenge { return null; }
            public function delete(string $rawToken): void
            {
                throw new RuntimeException('Redis 清理同样失败');
            }
        };

        try {
            $this->complete($store)->handle($this->validCommand());
            self::fail('挑战存储失败必须回滚');
        } catch (RuntimeException $exception) {
            self::assertSame('Redis 不可用', $exception->getMessage());
        }

        $userStatus = $this->pdo()->query(
            "SELECT status FROM cloud_users WHERE email_canonical = 'user@example.com'"
        )->fetchColumn();
        $consumedAt = $this->pdo()->query(
            'SELECT consumed_at FROM cloud_email_verifications ORDER BY created_at DESC LIMIT 1'
        )->fetchColumn();
        self::assertSame('PENDING_EMAIL', $userStatus);
        self::assertNull($consumedAt);
    }

    private function requestCode(): void
    {
        (new RequestEmailCode(
            new PdoUserRepository($this->pdo()),
            new PdoEmailVerificationRepository($this->pdo()),
            $this->sender,
            new InMemoryRateLimiter(),
            new PdoTransactionManager($this->pdo()),
            $this->clock,
            $this->ids,
            str_repeat('p', 32)
        ))->handle(new RequestEmailCodeCommand('User@Example.com', '127.0.0.1'));
    }

    private function complete(RegistrationChallengeStore $store): CompleteEmailRegistration
    {
        return new CompleteEmailRegistration(
            new PdoUserRepository($this->pdo()),
            new PdoEmailVerificationRepository($this->pdo()),
            $store,
            new PdoTransactionManager($this->pdo()),
            $this->clock,
            $this->ids,
            new PasswordPolicy(),
            str_repeat('p', 32)
        );
    }

    private function validCommand(): CompleteEmailRegistrationCommand
    {
        return new CompleteEmailRegistrationCommand(
            'user@example.com',
            $this->sender->lastCodeFor('user@example.com'),
            '客户用户',
            'Correct-Horse-2026!',
            '127.0.0.1'
        );
    }
}
