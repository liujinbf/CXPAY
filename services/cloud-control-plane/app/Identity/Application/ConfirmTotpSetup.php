<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\Totp;
use CloudControl\Identity\Port\TotpSetupStore;
use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Database\TransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Shared\Security\Base32;
use CloudControl\Shared\Security\SecretCipher;

final readonly class ConfirmTotpSetup
{
    public function __construct(
        private UserRepository $users,
        private TotpSetupStore $setups,
        private Totp $totp,
        private SecretCipher $cipher,
        private TransactionManager $transactions,
        private Clock $clock
    ) {
    }

    public function handle(string $userId, string $code): void
    {
        $pending = $this->setups->find($userId);
        $now = $this->clock->now();
        if ($pending === null || $pending->expiresAt <= $now) {
            throw new CloudException(ErrorCode::TOTP_SETUP_REQUIRED, 'TOTP 设置已失效', 422);
        }
        $secretBytes = Base32::decode($pending->secretBase32);
        if ($this->totp->matchingStep($secretBytes, $code, $now->getTimestamp(), 1) === null) {
            throw new CloudException(ErrorCode::TOTP_INVALID, 'TOTP 动态码无效', 422);
        }
        $encrypted = $this->cipher->encrypt($pending->secretBase32);
        $this->transactions->run(function () use ($userId, $encrypted, $now): void {
            $user = $this->users->findByIdForUpdate($userId);
            if ($user === null) {
                throw new CloudException(ErrorCode::REGISTRATION_INCOMPLETE, '用户不存在', 404);
            }
            $user->enableTotp($encrypted, $now);
            $this->users->save($user);
        });
        try {
            $this->setups->delete($userId);
        } catch (\Throwable) {
            // 用户已启用 TOTP，待确认状态会按 TTL 自动失效。
        }
    }
}
