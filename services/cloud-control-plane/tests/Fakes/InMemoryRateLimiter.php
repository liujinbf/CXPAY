<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Port\RateLimiter;

final class InMemoryRateLimiter implements RateLimiter
{
    /** @var array<string, int> */
    private array $counts = [];

    public function consume(string $key, int $limit, int $windowSeconds): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }
}
