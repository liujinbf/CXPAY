<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Port\TotpReplayGuard;

final class InMemoryTotpReplayGuard implements TotpReplayGuard
{
    /** @var array<string, true> */
    private array $claimed = [];

    public function claim(string $userId, int $timeStep, int $ttlSeconds): bool
    {
        $key = $userId . ':' . $timeStep;
        if (isset($this->claimed[$key])) {
            return false;
        }
        $this->claimed[$key] = true;
        return true;
    }
}
