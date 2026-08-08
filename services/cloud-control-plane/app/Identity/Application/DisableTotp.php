<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Database\TransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;

final readonly class DisableTotp
{
    public function __construct(
        private UserRepository $users,
        private VerifyTotp $verifyTotp,
        private TransactionManager $transactions,
        private Clock $clock
    ) {
    }

    public function handle(
        string $userId,
        string $currentPassword,
        string $currentCode,
        string $tenantType
    ): void {
        if ($tenantType === 'OFFICIAL') {
            throw new CloudException(
                ErrorCode::TOTP_SETUP_REQUIRED,
                '官方成员必须启用 TOTP',
                409
            );
        }
        $user = $this->users->findById($userId);
        if (
            $user === null
            || $user->passwordHash() === null
            || !password_verify($currentPassword, $user->passwordHash())
        ) {
            throw new CloudException(ErrorCode::CREDENTIALS_INVALID, '当前密码错误', 422);
        }
        if (!$this->verifyTotp->handle($userId, $currentCode, $this->clock->now())) {
            throw new CloudException(ErrorCode::TOTP_INVALID, 'TOTP 动态码无效', 422);
        }
        $now = $this->clock->now();
        $this->transactions->run(function () use ($userId, $now): void {
            $user = $this->users->findByIdForUpdate($userId);
            if ($user === null) {
                throw new CloudException(ErrorCode::REGISTRATION_INCOMPLETE, '用户不存在', 404);
            }
            $user->disableTotp($now);
            $this->users->save($user);
        });
    }
}
