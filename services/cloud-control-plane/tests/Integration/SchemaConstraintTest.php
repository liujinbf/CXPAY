<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Shared\Database\MigrationRunner;
use CloudControl\Tests\Support\MySqlTestCase;
use PDOException;

final class SchemaConstraintTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new MigrationRunner($this->pdo()))->migrate(dirname(__DIR__, 2) . '/migrations');
    }

    public function testEmailCanonicalIsDatabaseUnique(): void
    {
        $this->insertUser($this->id(1), 'user@example.com');

        $this->expectDuplicateKey(
            fn () => $this->insertUser($this->id(2), 'user@example.com')
        );
    }

    public function testExternalIdentityIsGloballyUnique(): void
    {
        $this->insertUser($this->id(1), 'user@example.com');
        $this->insertUser($this->id(2), 'other@example.com');
        $this->insertIdentity($this->id(11), $this->id(1), 'QQ', 'qq-client', 'openid-1');

        $this->expectDuplicateKey(
            fn () => $this->insertIdentity(
                $this->id(12),
                $this->id(2),
                'QQ',
                'qq-client',
                'openid-1'
            )
        );
    }

    public function testUserCanBindOnlyOneIdentityPerProviderAndIssuer(): void
    {
        $this->insertUser($this->id(1), 'user@example.com');
        $this->insertIdentity($this->id(11), $this->id(1), 'WECHAT', 'wechat-app', 'subject-1');

        $this->expectDuplicateKey(
            fn () => $this->insertIdentity(
                $this->id(12),
                $this->id(1),
                'WECHAT',
                'wechat-app',
                'subject-2'
            )
        );
    }

    public function testCustomerCanHaveOnlyOneActiveAgentRelation(): void
    {
        $userId = $this->id(1);
        $firstAgentId = $this->id(21);
        $secondAgentId = $this->id(22);
        $customerId = $this->id(23);
        $this->insertUser($userId, 'owner@example.com');
        $this->insertTenant($firstAgentId, 'AGENT', '一级代理', $userId);
        $this->insertTenant($secondAgentId, 'AGENT', '二级代理', $userId);
        $this->insertTenant($customerId, 'CUSTOMER', '客户', $userId);
        $this->insertRelation($this->id(31), $firstAgentId, $customerId, $userId);

        $this->expectDuplicateKey(
            fn () => $this->insertRelation(
                $this->id(32),
                $secondAgentId,
                $customerId,
                $userId
            )
        );
    }

    private function insertUser(string $id, string $email): void
    {
        $statement = $this->pdo()->prepare(<<<'SQL'
INSERT INTO cloud_users (
    id, email, email_canonical, status, created_at, updated_at
) VALUES (
    :id, :email, :email_canonical, 'PENDING_EMAIL', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
)
SQL);
        $statement->execute([
            'id' => $id,
            'email' => $email,
            'email_canonical' => strtolower($email),
        ]);
    }

    private function insertIdentity(
        string $id,
        string $userId,
        string $provider,
        string $issuer,
        string $subject
    ): void {
        $statement = $this->pdo()->prepare(<<<'SQL'
INSERT INTO cloud_user_identities (
    id, user_id, provider, issuer, subject, display_name, bound_at, created_at
) VALUES (
    :id, :user_id, :provider, :issuer, :subject, '测试身份', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
)
SQL);
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'provider' => $provider,
            'issuer' => $issuer,
            'subject' => $subject,
        ]);
    }

    private function expectDuplicateKey(callable $operation): void
    {
        try {
            $operation();
            self::fail('数据库唯一约束应拒绝重复数据');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
    }

    private function insertTenant(
        string $id,
        string $type,
        string $name,
        string $creatorId
    ): void {
        $statement = $this->pdo()->prepare(<<<'SQL'
INSERT INTO cloud_tenants (
    id, type, name, status, created_by_user_id, created_at, updated_at
) VALUES (
    :id, :type, :name, 'ACTIVE', :creator_id, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
)
SQL);
        $statement->execute([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'creator_id' => $creatorId,
        ]);
    }

    private function insertRelation(
        string $id,
        string $agentId,
        string $customerId,
        string $creatorId
    ): void {
        $statement = $this->pdo()->prepare(<<<'SQL'
INSERT INTO cloud_tenant_relations (
    id, agent_tenant_id, customer_tenant_id, status,
    effective_from, created_by_user_id, created_at
) VALUES (
    :id, :agent_id, :customer_id, 'ACTIVE',
    UTC_TIMESTAMP(6), :creator_id, UTC_TIMESTAMP(6)
)
SQL);
        $statement->execute([
            'id' => $id,
            'agent_id' => $agentId,
            'customer_id' => $customerId,
            'creator_id' => $creatorId,
        ]);
    }

    private function id(int $suffix): string
    {
        return sprintf('00000000-0000-7000-8000-%012d', $suffix);
    }
}
