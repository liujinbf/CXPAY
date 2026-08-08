<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Shared\Database\TransactionManager;

final class PassthroughTransactionManager implements TransactionManager
{
    public function run(callable $callback): mixed
    {
        return $callback();
    }
}
