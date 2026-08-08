<?php

declare(strict_types=1);

namespace CloudControl\Shared\Error;

use RuntimeException;
use Throwable;

final class CloudException extends RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $safeMessage,
        public readonly int $httpStatus,
        public readonly bool $retryable = false,
        public readonly array $data = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function safeMessage(): string
    {
        return $this->getMessage();
    }
}
