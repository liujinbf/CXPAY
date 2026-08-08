<?php

declare(strict_types=1);

namespace CloudControl\Shared\Database;

interface TransactionManager
{
    public function run(callable $callback): mixed;
}
