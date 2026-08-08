<?php

declare(strict_types=1);

namespace CloudControl\Shared\Clock;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
