<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthState;

interface OAuthStateStore
{
    public function save(OAuthState $state): void;
    public function consume(string $rawState, OAuthAudience $expectedAudience): OAuthState;
}
