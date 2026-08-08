<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\UserStatus;
use DateTimeImmutable;

final readonly class RegistrationChallenge
{
    public function __construct(
        public string $token,
        public string $userId,
        public string $emailCanonical,
        public UserStatus $status,
        public DateTimeImmutable $expiresAt
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }
}
