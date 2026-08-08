<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\PendingTotpSetup;

interface TotpSetupStore
{
    public function save(PendingTotpSetup $setup): void;
    public function find(string $userId): ?PendingTotpSetup;
    public function delete(string $userId): void;
}
