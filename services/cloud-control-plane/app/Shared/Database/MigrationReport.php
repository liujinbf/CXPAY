<?php

declare(strict_types=1);

namespace CloudControl\Shared\Database;

final readonly class MigrationReport
{
    /** @param list<string> $applied */
    public function __construct(public array $applied)
    {
    }
}
