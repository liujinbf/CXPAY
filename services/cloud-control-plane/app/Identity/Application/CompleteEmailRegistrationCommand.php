<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

final readonly class CompleteEmailRegistrationCommand
{
    public function __construct(
        public string $email,
        public string $code,
        public string $displayName,
        public string $password,
        public string $requestedIp
    ) {
    }
}
