<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use DateTimeImmutable;

final readonly class TotpSetupView
{
    public function __construct(
        public string $provisioningUri,
        public DateTimeImmutable $expiresAt
    ) {
    }
}
