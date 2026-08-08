<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;

final class AuthSessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $sessionId, string $reference, int $ttl, ?int $now = null): void
    {
        $now ??= time();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO auth_sessions(id, reference, status, created_at, expires_at)
            VALUES(:id, :reference, 'PENDING', :created_at, :expires_at)
            ON CONFLICT(id) DO UPDATE SET
                reference = excluded.reference,
                mode = 'clerk',
                qr_url = '',
                account_id = '',
                status = 'PENDING',
                created_at = excluded.created_at,
                expires_at = excluded.expires_at
            SQL);
        $statement->execute([
            ':id' => $sessionId,
            ':reference' => $reference,
            ':created_at' => $now,
            ':expires_at' => $now + $ttl,
        ]);
    }

    public function update(string $sessionId, string $status, string $qrUrl = '', string $accountId = ''): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE auth_sessions
            SET status = :status, qr_url = :qr_url, account_id = :account_id
            WHERE id = :id
            SQL);
        $statement->execute([
            ':status' => $status,
            ':qr_url' => $qrUrl,
            ':account_id' => $accountId,
            ':id' => $sessionId,
        ]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string, mixed>|null */
    public function findActive(string $sessionId, ?int $now = null): ?array
    {
        $now ??= time();
        $statement = $this->pdo->prepare(
            'SELECT * FROM auth_sessions WHERE id = :id AND expires_at >= :now LIMIT 1'
        );
        $statement->execute([':id' => $sessionId, ':now' => $now]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
