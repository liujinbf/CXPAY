<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Application\RequestEmailCode;
use CloudControl\Identity\Application\RequestEmailCodeCommand;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tests\Fakes\FakeEmailSender;
use CloudControl\Tests\Fakes\InMemoryEmailVerificationRepository;
use CloudControl\Tests\Fakes\InMemoryRateLimiter;
use CloudControl\Tests\Fakes\InMemoryUserRepository;
use CloudControl\Tests\Fakes\PassthroughTransactionManager;
use CloudControl\Tests\Fakes\SequentialIdGenerator;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RequestEmailCodeTest extends TestCase
{
    public function testSuccessfulDeliveryCreatesReadyVerification(): void
    {
        $verifications = new InMemoryEmailVerificationRepository();
        $sender = FakeEmailSender::successful();
        $service = $this->service($verifications, $sender);

        $service->handle(new RequestEmailCodeCommand('User@Example.com', '127.0.0.1'));

        self::assertMatchesRegularExpression('/^\d{6}$/', $sender->lastCodeFor('user@example.com'));
        self::assertTrue($verifications->hasReadyCode('user@example.com'));
    }

    public function testFailedDeliveryNeverCreatesUsableVerification(): void
    {
        $verifications = new InMemoryEmailVerificationRepository();
        $sender = FakeEmailSender::alwaysFail();

        try {
            $this->service($verifications, $sender)
                ->handle(new RequestEmailCodeCommand('user@example.com', '127.0.0.1'));
            self::fail('投递失败时必须抛出稳定错误');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::EMAIL_DELIVERY_FAILED, $exception->errorCode);
        }

        self::assertFalse($verifications->hasReadyCode('user@example.com'));
    }

    private function service(
        InMemoryEmailVerificationRepository $verifications,
        FakeEmailSender $sender
    ): RequestEmailCode {
        return new RequestEmailCode(
            new InMemoryUserRepository(),
            $verifications,
            $sender,
            new InMemoryRateLimiter(),
            new PassthroughTransactionManager(),
            new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z')),
            new SequentialIdGenerator(),
            str_repeat('p', 32)
        );
    }
}
