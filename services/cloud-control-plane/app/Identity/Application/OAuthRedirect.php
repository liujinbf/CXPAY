<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use DateTimeImmutable;

final readonly class OAuthRedirect
{
    public function __construct(
        public string $url,
        public DateTimeImmutable $expiresAt
    ) {
    }
}
