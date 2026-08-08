<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\EmailVerificationPurpose;
use CloudControl\Identity\Domain\PasswordPolicy;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Port\EmailVerificationRepository;
use CloudControl\Identity\Port\RegistrationChallengeStore;
use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Database\TransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Shared\Id\IdGenerator;
use Throwable;

final readonly class CompleteEmailRegistration
{
    public function __construct(
        private UserRepository $users,
        private EmailVerificationRepository $verifications,
        private RegistrationChallengeStore $challenges,
        private TransactionManager $transactions,
        private Clock $clock,
        private IdGenerator $ids,
        private PasswordPolicy $passwordPolicy,
        private string $emailCodePepper
    ) {
        if (strlen($emailCodePepper) !== 32) {
            throw new \InvalidArgumentException('邮箱验证码摘要密钥必须为 32 字节');
        }
    }

    public function handle(CompleteEmailRegistrationCommand $command): RegistrationChallenge
    {
        $email = EmailAddress::fromString($command->email);
        $displayName = trim($command->displayName);
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 100) {
            throw new CloudException(
                ErrorCode::REGISTRATION_INCOMPLETE,
                '用户名称长度必须为 1 至 100 个字符',
                422
            );
        }
        $passwordHash = $this->passwordPolicy->hash($command->password);
        $now = $this->clock->now();
        $issuedChallenge = null;

        try {
            $result = $this->transactions->run(function () use (
                $email,
                $command,
                $displayName,
                $passwordHash,
                $now,
                &$issuedChallenge
            ): ?RegistrationChallenge {
                $verification = $this->verifications->latestReadyForUpdate(
                    $email->canonical(),
                    EmailVerificationPurpose::REGISTER
                );
                if ($verification === null) {
                    throw self::invalidCode();
                }

                $candidateDigest = hash_hmac(
                    'sha256',
                    $email->canonical() . "\n" . $command->code,
                    $this->emailCodePepper
                );
                if (!$verification->verifyDigest($candidateDigest, $now)) {
                    $this->verifications->save($verification);
                    return null;
                }

                $user = $this->users->findByEmailCanonicalForUpdate($email->canonical());
                if ($user === null) {
                    throw new CloudException(
                        ErrorCode::REGISTRATION_INCOMPLETE,
                        '邮箱注册状态不完整',
                        409
                    );
                }

                $user->completeEmailRegistration($displayName, $passwordHash, $now);
                $this->verifications->save($verification);
                $this->users->save($user);

                $issuedChallenge = new RegistrationChallenge(
                    self::randomToken(),
                    $user->id(),
                    $user->emailCanonical(),
                    UserStatus::PENDING_IDENTITY,
                    $now->modify('+15 minutes')
                );
                $this->challenges->save($issuedChallenge);

                return $issuedChallenge;
            });
        } catch (Throwable $exception) {
            if ($issuedChallenge instanceof RegistrationChallenge) {
                try {
                    $this->challenges->delete($issuedChallenge->token);
                } catch (Throwable) {
                    // 清理失败不能覆盖触发数据库回滚的原始异常。
                }
            }
            throw $exception;
        }

        if (!$result instanceof RegistrationChallenge) {
            throw self::invalidCode();
        }

        return $result;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function invalidCode(): CloudException
    {
        return new CloudException(
            ErrorCode::EMAIL_CODE_INVALID,
            '邮箱验证码无效',
            422
        );
    }
}
