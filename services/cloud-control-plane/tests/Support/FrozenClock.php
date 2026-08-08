<?php

declare(strict_types=1);

namespace CloudControl\Tests\Support;

use CloudControl\Shared\Clock\Clock;
use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
