<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\OAuthAudience;

final readonly class CompleteOAuthCommand
{
    public function __construct(
        public string $rawState,
        public string $code,
        public OAuthAudience $expectedAudience
    ) {
    }
}
