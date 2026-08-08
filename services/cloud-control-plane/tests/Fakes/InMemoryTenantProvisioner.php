<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\User;
use CloudControl\Tenant\Port\TenantProvisioner;
use DateTimeImmutable;

final class InMemoryTenantProvisioner implements TenantProvisioner
{
    /** @var array<string, string> */
    private array $tenantByUser = [];

    public function provisionCustomer(User $owner, DateTimeImmutable $now): string
    {
        return $this->tenantByUser[$owner->id()] = 'tenant-' . $owner->id();
    }

    public function customerTenantIdForUser(string $userId): ?string
    {
        return $this->tenantByUser[$userId] ?? null;
    }
}
