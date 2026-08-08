<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\OAuthAudience;
use DateTimeImmutable;

final readonly class IdentityCompletion
{
    public function __construct(
        public string $userId,
        public OAuthAudience $audience,
        public string $tenantId,
        public DateTimeImmutable $completedAt
    ) {
    }
}
