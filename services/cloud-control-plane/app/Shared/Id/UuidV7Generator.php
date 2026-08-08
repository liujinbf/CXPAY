<?php

declare(strict_types=1);

namespace CloudControl\Shared\Id;

use Ramsey\Uuid\Uuid;

final class UuidV7Generator implements IdGenerator
{
    public function new(): string
    {
        return Uuid::uuid7()->toString();
    }
}
