<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;

final class AccountRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(
        string $accountId,
        string $nickname,
        string $geweAppId,
        string $status,
        ?int $now = null
    ): void {
        $now ??= time();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO accounts(id, nickname, gewe_app_id, status, last_seen_at, created_at)
            VALUES(:id, :nickname, :gewe_app_id, :status, :now, :now)
            ON CONFLICT(id) DO UPDATE SET
                nickname = excluded.nickname,
                gewe_app_id = excluded.gewe_app_id,
                status = excluded.status,
                last_seen_at = excluded.last_seen_at
            SQL);
        $statement->execute([
            ':id' => $accountId,
            ':nickname' => $nickname,
            ':gewe_app_id' => $geweAppId,
            ':status' => $status,
            ':now' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $accountId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM accounts WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $accountId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByGeweAppId(string $geweAppId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM accounts WHERE gewe_app_id = :gewe_app_id LIMIT 1'
        );
        $statement->execute([':gewe_app_id' => $geweAppId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM accounts ORDER BY last_seen_at DESC, id ASC')->fetchAll() ?: [];
    }
}
