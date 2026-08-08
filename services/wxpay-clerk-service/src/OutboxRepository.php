<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;

final class OutboxRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $paymentEventId, string $outTradeNo, int $now): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO callback_outbox(
                payment_event_id, out_trade_no, callback_payload,
                status, attempts, next_attempt_at, created_at
            ) VALUES(
                :payment_event_id, :out_trade_no, '{}',
                'PENDING', 0, :next_attempt_at, :created_at
            )
            SQL);
        $statement->execute([
            ':payment_event_id' => $paymentEventId,
            ':out_trade_no' => $outTradeNo,
            ':next_attempt_at' => $now,
            ':created_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function claimDue(int $now, int $leaseSeconds): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE callback_outbox
            SET status = 'PROCESSING',
                attempts = attempts + 1,
                lease_until = :lease_until
            WHERE id = (
                SELECT id FROM callback_outbox
                WHERE (status = 'PENDING' AND next_attempt_at <= :due_now)
                   OR (status = 'PROCESSING' AND lease_until < :lease_now)
                ORDER BY next_attempt_at ASC, id ASC
                LIMIT 1
            )
            RETURNING id
            SQL);
        $statement->execute([
            ':lease_until' => $now + $leaseSeconds,
            ':due_now' => $now,
            ':lease_now' => $now,
        ]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return null;
        }

        $task = $this->pdo->prepare(<<<'SQL'
            SELECT o.*, e.source_bill_id, e.amount, e.occurred_at
            FROM callback_outbox o
            INNER JOIN payment_events e ON e.id = o.payment_event_id
            WHERE o.id = :id
            LIMIT 1
            SQL);
        $task->execute([':id' => (int) $id]);
        $row = $task->fetch();
        return is_array($row) ? $row : null;
    }

    public function markSent(int $id, int $sentAt): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE callback_outbox
            SET status = 'SENT', sent_at = :sent_at, lease_until = NULL, last_error = NULL
            WHERE id = :id AND status = 'PROCESSING'
            SQL);
        $statement->execute([':sent_at' => $sentAt, ':id' => $id]);
    }

    public function reschedule(int $id, int $nextAttemptAt, int $attempts, string $error): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE callback_outbox
            SET status = 'PENDING',
                next_attempt_at = :next_attempt_at,
                lease_until = NULL,
                last_error = :last_error
            WHERE id = :id AND status = 'PROCESSING' AND attempts = :attempts
            SQL);
        $statement->execute([
            ':next_attempt_at' => $nextAttemptAt,
            ':last_error' => substr($error, 0, 500),
            ':id' => $id,
            ':attempts' => $attempts,
        ]);
    }

    public function markFailed(int $id, int $attempts, string $error): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE callback_outbox
            SET status = 'FAILED', lease_until = NULL, last_error = :last_error
            WHERE id = :id AND status = 'PROCESSING' AND attempts = :attempts
            SQL);
        $statement->execute([
            ':last_error' => substr($error, 0, 500),
            ':id' => $id,
            ':attempts' => $attempts,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findByOrder(string $outTradeNo): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM callback_outbox
            WHERE out_trade_no = :out_trade_no
            ORDER BY id DESC
            LIMIT 1
            SQL);
        $statement->execute([':out_trade_no' => $outTradeNo]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array{pending_count: int, processing_count: int, failed_count: int, oldest_pending_at: ?int, last_error: string} */
    public function statusSummary(): array
    {
        $row = $this->pdo->query(<<<'SQL'
            SELECT
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                MIN(CASE WHEN status = 'PENDING' THEN created_at END) AS oldest_pending_at
            FROM callback_outbox
            SQL)->fetch();
        $lastError = $this->pdo->query(<<<'SQL'
            SELECT last_error FROM callback_outbox
            WHERE last_error IS NOT NULL AND last_error <> ''
            ORDER BY id DESC LIMIT 1
            SQL)->fetchColumn();
        return [
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'processing_count' => (int) ($row['processing_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'oldest_pending_at' => isset($row['oldest_pending_at']) ? (int) $row['oldest_pending_at'] : null,
            'last_error' => $lastError !== false ? (string) $lastError : '',
        ];
    }
}
