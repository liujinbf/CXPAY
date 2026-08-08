<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Application\CompleteEmailRegistration;
use CloudControl\Identity\Application\CompleteEmailRegistrationCommand;
use CloudControl\Identity\Application\RequestEmailCode;
use CloudControl\Identity\Application\RequestEmailCodeCommand;
use CloudControl\Identity\Domain\PasswordPolicy;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tests\Fakes\FakeEmailSender;
use CloudControl\Tests\Fakes\InMemoryEmailVerificationRepository;
use CloudControl\Tests\Fakes\InMemoryRateLimiter;
use CloudControl\Tests\Fakes\InMemoryRegistrationChallengeStore;
use CloudControl\Tests\Fakes\InMemoryUserRepository;
use CloudControl\Tests\Fakes\PassthroughTransactionManager;
use CloudControl\Tests\Fakes\SequentialIdGenerator;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CompleteEmailRegistrationTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemoryEmailVerificationRepository $verifications;
    private InMemoryRegistrationChallengeStore $challenges;
    private FakeEmailSender $sender;
    private FrozenClock $clock;
    private SequentialIdGenerator $ids;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->verifications = new InMemoryEmailVerificationRepository();
        $this->challenges = new InMemoryRegistrationChallengeStore();
        $this->sender = FakeEmailSender::successful();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $this->ids = new SequentialIdGenerator();
    }

    public function testVerifiedEmailContinuesOnlyAsPendingIdentity(): void
    {
        $this->requestCode('User@Example.com');
        $code = $this->sender->lastCodeFor('user@example.com');

        $challenge = $this->complete()->handle(new CompleteEmailRegistrationCommand(
            'user@example.com',
            $code,
            '客户用户',
            'Correct-Horse-2026!',
            '127.0.0.1'
        ));

        self::assertSame(UserStatus::PENDING_IDENTITY, $challenge->status);
        self::assertSame('user@example.com', $challenge->emailCanonical);
        self::assertFalse($challenge->isActive());
        self::assertNotSame('', $challenge->token);
        self::assertNotNull($this->challenges->find($challenge->token));
    }

    public function testWrongCodeIsLimitedAndNeverConsumesVerification(): void
    {
        $this->requestCode('user@example.com');
        $realCode = $this->sender->lastCodeFor('user@example.com');
        $wrongCode = $realCode === '000000' ? '000001' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->complete()->handle(new CompleteEmailRegistrationCommand(
                    'user@example.com',
                    $wrongCode,
                    '客户用户',
                    'Correct-Horse-2026!',
                    '127.0.0.1'
                ));
                self::fail('错误验证码不应通过');
            } catch (CloudException $exception) {
                self::assertSame(ErrorCode::EMAIL_CODE_INVALID, $exception->errorCode);
            }
        }

        try {
            $this->complete()->handle(new CompleteEmailRegistrationCommand(
                'user@example.com',
                $realCode,
                '客户用户',
                'Correct-Horse-2026!',
                '127.0.0.1'
            ));
            self::fail('达到错误次数上限后真实验证码也必须拒绝');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::EMAIL_CODE_INVALID, $exception->errorCode);
        }
    }

    public function testSuccessfulCodeCannotBeConsumedTwice(): void
    {
        $this->requestCode('user@example.com');
        $command = new CompleteEmailRegistrationCommand(
            'user@example.com',
            $this->sender->lastCodeFor('user@example.com'),
            '客户用户',
            'Correct-Horse-2026!',
            '127.0.0.1'
        );

        $this->complete()->handle($command);

        $this->expectException(CloudException::class);
        $this->complete()->handle($command);
    }

    private function requestCode(string $email): void
    {
        (new RequestEmailCode(
            $this->users,
            $this->verifications,
            $this->sender,
            new InMemoryRateLimiter(),
            new PassthroughTransactionManager(),
            $this->clock,
            $this->ids,
            str_repeat('p', 32)
        ))->handle(new RequestEmailCodeCommand($email, '127.0.0.1'));
    }

    private function complete(): CompleteEmailRegistration
    {
        return new CompleteEmailRegistration(
            $this->users,
            $this->verifications,
            $this->challenges,
            new PassthroughTransactionManager(),
            $this->clock,
            $this->ids,
            new PasswordPolicy(),
            str_repeat('p', 32)
        );
    }
}
