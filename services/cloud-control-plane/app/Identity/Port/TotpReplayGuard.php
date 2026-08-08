<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

interface TotpReplayGuard
{
    public function claim(string $userId, int $timeStep, int $ttlSeconds): bool;
}
