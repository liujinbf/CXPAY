<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

final readonly class RequestEmailCodeCommand
{
    public function __construct(
        public string $email,
        public string $requestedIp
    ) {
    }
}
