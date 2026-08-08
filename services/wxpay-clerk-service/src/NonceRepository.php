<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;
use PDOException;

final class NonceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function claim(string $clientId, string $nonce, int $usedAt, int $expiresAt): bool
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO request_nonces(client_id, nonce, used_at, expires_at)
                VALUES(:client_id, :nonce, :used_at, :expires_at)
                SQL);
            $statement->execute([
                ':client_id' => $clientId,
                ':nonce' => $nonce,
                ':used_at' => $usedAt,
                ':expires_at' => $expiresAt,
            ]);
            return true;
        } catch (PDOException $exception) {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT 1 FROM request_nonces
                WHERE client_id = :client_id AND nonce = :nonce
                LIMIT 1
                SQL);
            $statement->execute([':client_id' => $clientId, ':nonce' => $nonce]);
            if ($statement->fetchColumn() !== false) {
                return false;
            }
            throw $exception;
        }
    }

    public function purgeExpired(int $now): int
    {
        $statement = $this->pdo->prepare('DELETE FROM request_nonces WHERE expires_at < :now');
        $statement->execute([':now' => $now]);
        return $statement->rowCount();
    }
}
