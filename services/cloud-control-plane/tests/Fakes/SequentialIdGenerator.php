<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Shared\Id\IdGenerator;

final class SequentialIdGenerator implements IdGenerator
{
    private int $next = 1;

    public function new(): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $this->next++);
    }
}
