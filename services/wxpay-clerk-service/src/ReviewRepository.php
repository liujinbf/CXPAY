<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;

final class ReviewRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $event */
    public function create(array $event, string $reason): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO review_events(
                payment_event_id, account_id, amount, payer_name, remark,
                occurred_at, received_at, status, source_bill_id, note
            ) VALUES(
                :payment_event_id, :account_id, :amount, :payer_name, :remark,
                :occurred_at, :received_at, 'PENDING', :source_bill_id, :reason
            )
            SQL);
        $statement->execute([
            ':payment_event_id' => (int) $event['id'],
            ':account_id' => (string) $event['account_id'],
            ':amount' => (string) $event['amount'],
            ':payer_name' => (string) ($event['payer_name'] ?? ''),
            ':remark' => (string) ($event['remark'] ?? ''),
            ':occurred_at' => (int) $event['occurred_at'],
            ':received_at' => (int) $event['received_at'],
            ':source_bill_id' => (string) $event['source_bill_id'],
            ':reason' => $reason,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function pending(string $accountId = ''): array
    {
        if ($accountId === '') {
            $statement = $this->pdo->query(
                "SELECT * FROM review_events WHERE status = 'PENDING' ORDER BY occurred_at DESC, id DESC"
            );
            return $statement->fetchAll() ?: [];
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM review_events
            WHERE status = 'PENDING' AND account_id = :account_id
            ORDER BY occurred_at DESC, id DESC
            SQL);
        $statement->execute([':account_id' => $accountId]);
        return $statement->fetchAll() ?: [];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM review_events WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function recordResolution(
        int $id,
        string $status,
        string $outTradeNo,
        string $operator,
        string $note,
        ?int $resolvedAt = null
    ): bool {
        $resolvedAt ??= time();
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE review_events
            SET status = :status,
                out_trade_no = :out_trade_no,
                operator = :operator,
                note = :note,
                resolved_at = :resolved_at
            WHERE id = :id AND status = 'PENDING'
            SQL);
        $statement->execute([
            ':status' => $status,
            ':out_trade_no' => $outTradeNo !== '' ? $outTradeNo : null,
            ':operator' => $operator,
            ':note' => $note,
            ':resolved_at' => $resolvedAt,
            ':id' => $id,
        ]);
        return $statement->rowCount() === 1;
    }
}
