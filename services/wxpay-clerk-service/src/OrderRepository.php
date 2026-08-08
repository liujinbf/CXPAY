<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;
use PDOException;

final class OrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{accepted: bool, idempotent: bool} */
    public function register(
        string $accountId,
        string $channelId,
        string $outTradeNo,
        string $amount,
        int $expiresAt,
        ?int $now = null
    ): array {
        $existing = $this->find($outTradeNo);
        if ($existing !== null) {
            return $this->idempotentResult($existing, $accountId, $amount, $expiresAt);
        }

        $now ??= time();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO orders(account_id, channel_id, out_trade_no, amount, expires_at, created_at, status)
                VALUES(:account_id, :channel_id, :out_trade_no, :amount, :expires_at, :created_at, 'PENDING')
                SQL);
            $statement->execute([
                ':account_id' => $accountId,
                ':channel_id' => $channelId,
                ':out_trade_no' => $outTradeNo,
                ':amount' => $amount,
                ':expires_at' => $expiresAt,
                ':created_at' => $now,
            ]);
        } catch (PDOException $exception) {
            $existing = $this->find($outTradeNo);
            if ($existing === null) {
                throw $exception;
            }
            return $this->idempotentResult($existing, $accountId, $amount, $expiresAt);
        }

        return ['accepted' => true, 'idempotent' => false];
    }

    /** @return array<string, mixed>|null */
    public function find(string $outTradeNo): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE out_trade_no = :out_trade_no LIMIT 1');
        $statement->execute([':out_trade_no' => $outTradeNo]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function candidates(string $accountId, string $amount, int $occurredAt): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM orders
            WHERE account_id = :account_id
              AND amount = :amount
              AND status = 'PENDING'
              AND created_at <= :occurred_at
              AND expires_at >= :occurred_at
            ORDER BY created_at ASC, id ASC
            SQL);
        $statement->execute([
            ':account_id' => $accountId,
            ':amount' => $amount,
            ':occurred_at' => $occurredAt,
        ]);
        return $statement->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed> $existing
     * @return array{accepted: bool, idempotent: bool}
     */
    private function idempotentResult(array $existing, string $accountId, string $amount, int $expiresAt): array
    {
        $same = hash_equals((string) $existing['account_id'], $accountId)
            && hash_equals((string) $existing['amount'], $amount)
            && (int) $existing['expires_at'] === $expiresAt;
        if (!$same) {
            throw new ApiException(409, '订单号已存在且参数不一致');
        }
        return ['accepted' => true, 'idempotent' => true];
    }
}
