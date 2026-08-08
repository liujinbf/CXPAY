<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

use DateTimeImmutable;

final readonly class PendingTotpSetup
{
    public function __construct(
        public string $userId,
        public string $secretBase32,
        public DateTimeImmutable $expiresAt
    ) {
    }
}
