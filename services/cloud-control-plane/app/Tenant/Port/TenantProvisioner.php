<?php

declare(strict_types=1);

namespace CloudControl\Tenant\Port;

use CloudControl\Identity\Domain\User;
use DateTimeImmutable;

interface TenantProvisioner
{
    public function provisionCustomer(User $owner, DateTimeImmutable $now): string;
    public function customerTenantIdForUser(string $userId): ?string;
}
