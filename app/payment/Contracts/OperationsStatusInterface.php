<?php

declare(strict_types=1);

namespace app\payment\Contracts;

interface OperationsStatusInterface
{
    /** @return array<string, mixed> */
    public function operationsStatus(array $config): array;
}
