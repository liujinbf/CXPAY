<?php

declare(strict_types=1);

namespace WxCollector;

interface CloudGatewayInterface
{
    /** @return list<array<string, mixed>> */
    public function pendingSessions(): array;

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function updateSession(string $sessionId, array $state): array;

    /** @param array<string, mixed> $event @return array<string, mixed> */
    public function submitPaymentEvent(array $event): array;
}
