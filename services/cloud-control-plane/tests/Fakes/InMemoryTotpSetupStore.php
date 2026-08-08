<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\PendingTotpSetup;
use CloudControl\Identity\Port\TotpSetupStore;

final class InMemoryTotpSetupStore implements TotpSetupStore
{
    /** @var array<string, PendingTotpSetup> */
    private array $setups = [];

    public function save(PendingTotpSetup $setup): void { $this->setups[$setup->userId] = $setup; }
    public function find(string $userId): ?PendingTotpSetup { return $this->setups[$userId] ?? null; }
    public function delete(string $userId): void { unset($this->setups[$userId]); }
}
