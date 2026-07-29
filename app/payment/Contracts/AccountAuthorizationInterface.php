<?php

declare(strict_types=1);

namespace app\payment\Contracts;

interface AccountAuthorizationInterface
{
    /** @return array<string, mixed> */
    public function startAccountAuthorization(array $config): array;

    /** @return array<string, mixed> */
    public function pollAccountAuthorization(string $sessionId, array $config): array;
}
