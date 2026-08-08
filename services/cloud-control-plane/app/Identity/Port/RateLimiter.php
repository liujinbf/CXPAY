<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

interface RateLimiter
{
    public function consume(string $key, int $limit, int $windowSeconds): void;
}
