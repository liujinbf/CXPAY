<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\Totp;
use CloudControl\Identity\Port\TotpReplayGuard;
use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Security\Base32;
use CloudControl\Shared\Security\SecretCipher;
use DateTimeImmutable;

final readonly class VerifyTotp
{
    public function __construct(
        private UserRepository $users,
        private Totp $totp,
        private SecretCipher $cipher,
        private TotpReplayGuard $replayGuard
    ) {
    }

    public function handle(string $userId, string $code, DateTimeImmutable $at): bool
    {
        $user = $this->users->findById($userId);
        $encrypted = $user?->totpSecret();
        if ($user === null || !$user->totpEnabled() || $encrypted === null) {
            return false;
        }
        try {
            $secret = Base32::decode($this->cipher->decrypt($encrypted));
        } catch (\Throwable) {
            return false;
        }
        $step = $this->totp->matchingStep($secret, $code, $at->getTimestamp(), 1);
        if ($step === null) {
            return false;
        }

        return $this->replayGuard->claim($userId, $step, $this->totp->period * 3);
    }
}
