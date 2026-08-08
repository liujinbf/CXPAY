<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\EmailVerification;
use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Port\EmailSender;
use CloudControl\Identity\Port\EmailVerificationRepository;
use CloudControl\Identity\Port\RateLimiter;
use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Database\TransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Shared\Id\IdGenerator;
use Throwable;

final readonly class RequestEmailCode
{
    public function __construct(
        private UserRepository $users,
        private EmailVerificationRepository $verifications,
        private EmailSender $sender,
        private RateLimiter $rateLimiter,
        private TransactionManager $transactions,
        private Clock $clock,
        private IdGenerator $ids,
        private string $emailCodePepper
    ) {
        if (strlen($emailCodePepper) !== 32) {
            throw new \InvalidArgumentException('邮箱验证码摘要密钥必须为 32 字节');
        }
    }

    public function handle(RequestEmailCodeCommand $command): void
    {
        $email = EmailAddress::fromString($command->email);
        $this->rateLimiter->consume('email-code:email:' . $email->canonical(), 5, 600);
        $this->rateLimiter->consume('email-code:ip:' . $command->requestedIp, 20, 600);

        $now = $this->clock->now();
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $digest = $this->digest($email->canonical(), $code);
        $verification = EmailVerification::pendingDelivery(
            $this->ids->new(),
            $email->canonical(),
            $digest,
            $now->modify('+10 minutes'),
            $command->requestedIp,
            $now
        );

        $this->transactions->run(function () use ($email, $now, $verification): void {
            $this->users->findOrCreatePending(User::pendingEmail($this->ids->new(), $email, $now));
            $this->verifications->save($verification);
        });

        try {
            $this->sender->sendVerificationCode($email, $code);
        } catch (Throwable $exception) {
            $verification->invalidate();
            $this->transactions->run(fn () => $this->verifications->save($verification));
            throw new CloudException(
                ErrorCode::EMAIL_DELIVERY_FAILED,
                '邮箱验证码发送失败',
                503,
                true,
                [],
                $exception
            );
        }

        $verification->markReady();
        $this->transactions->run(fn () => $this->verifications->save($verification));
    }

    private function digest(string $emailCanonical, string $code): string
    {
        return hash_hmac('sha256', $emailCanonical . "\n" . $code, $this->emailCodePepper);
    }
}
