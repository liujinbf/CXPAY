<?php

declare(strict_types=1);

namespace CloudControl\Tenant\Infrastructure;

use CloudControl\Identity\Domain\User;
use CloudControl\Shared\Id\IdGenerator;
use CloudControl\Tenant\Port\TenantProvisioner;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoCustomerTenantProvisioner implements TenantProvisioner
{
    public function __construct(
        private PDO $pdo,
        private IdGenerator $ids
    ) {
    }

    public function provisionCustomer(User $owner, DateTimeImmutable $now): string
    {
        $tenantId = $this->ids->new();
        $memberId = $this->ids->new();
        $timestamp = self::format($now);
        $tenant = $this->pdo->prepare(<<<'SQL'
INSERT INTO cloud_tenants (
    id, type, name, status, created_by_user_id, created_at, updated_at
) VALUES (
    :id, 'CUSTOMER', :name, 'ACTIVE', :creator, :created_at, :updated_at
)
SQL);
        $tenant->execute([
            'id' => $tenantId,
            'name' => mb_substr(($owner->displayName() ?? $owner->emailCanonical()) . '的客户租户', 0, 150),
            'creator' => $owner->id(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $member = $this->pdo->prepare(<<<'SQL'
INSERT INTO cloud_tenant_members (
    id, tenant_id, user_id, role, status, joined_at, created_at, updated_at
) VALUES (
    :id, :tenant_id, :user_id, 'OWNER', 'ACTIVE', :joined_at, :created_at, :updated_at
)
SQL);
        $member->execute([
            'id' => $memberId,
            'tenant_id' => $tenantId,
            'user_id' => $owner->id(),
            'joined_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $tenantId;
    }

    public function customerTenantIdForUser(string $userId): ?string
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT t.id
FROM cloud_tenants t
JOIN cloud_tenant_members m ON m.tenant_id = t.id
WHERE m.user_id = :user_id
  AND m.status = 'ACTIVE'
  AND t.type = 'CUSTOMER'
  AND t.status = 'ACTIVE'
ORDER BY m.joined_at ASC
LIMIT 1
SQL);
        $statement->execute(['user_id' => $userId]);
        $tenantId = $statement->fetchColumn();

        return $tenantId === false ? null : (string)$tenantId;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
