<?php

declare(strict_types=1);

namespace app\payment\Contracts;

interface PaymentEventReviewInterface
{
    /** @return array<string, mixed> */
    public function reviewEvents(array $config): array;

    /** @return array<string, mixed> */
    public function matchReviewEvent(
        array $config,
        int $eventId,
        string $tradeNo,
        string $operator,
        string $note
    ): array;

    /** @return array<string, mixed> */
    public function ignoreReviewEvent(
        array $config,
        int $eventId,
        string $operator,
        string $note
    ): array;
}
