<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $safeMessage)
    {
        parent::__construct($safeMessage, $status);
    }
}
