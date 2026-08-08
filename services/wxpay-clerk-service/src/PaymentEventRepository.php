<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;
use PDOException;

final class PaymentEventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $event
     * @return array{event: array<string, mixed>, created: bool}
     */
    public function createOrFind(array $event): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO payment_events(
                    account_id, source_bill_id, amount, payer_name, remark,
                    occurred_at, received_at, raw_hash, status
                ) VALUES(
                    :account_id, :source_bill_id, :amount, :payer_name, :remark,
                    :occurred_at, :received_at, :raw_hash, 'RECEIVED'
                )
                SQL);
            $statement->execute([
                ':account_id' => (string) $event['account_id'],
                ':source_bill_id' => (string) $event['source_bill_id'],
                ':amount' => (string) $event['amount'],
                ':payer_name' => (string) ($event['payer_name'] ?? ''),
                ':remark' => (string) ($event['remark'] ?? ''),
                ':occurred_at' => (int) $event['occurred_at'],
                ':received_at' => (int) ($event['received_at'] ?? time()),
                ':raw_hash' => (string) $event['raw_hash'],
            ]);
            $created = $this->findBySource((string) $event['account_id'], (string) $event['source_bill_id']);
            return ['event' => $created, 'created' => true];
        } catch (PDOException $exception) {
            $existing = $this->findBySource((string) $event['account_id'], (string) $event['source_bill_id']);
            if ($existing === null) {
                throw $exception;
            }
            if (!hash_equals((string) $existing['amount'], (string) $event['amount'])
                || (int) $existing['occurred_at'] !== (int) $event['occurred_at']) {
                throw new ApiException(409, '相同账单编号的到账事实不一致');
            }
            return ['event' => $existing, 'created' => false];
        }
    }

    /** @return array<string, mixed>|null */
    private function findBySource(string $accountId, string $sourceBillId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM payment_events
            WHERE account_id = :account_id AND source_bill_id = :source_bill_id
            LIMIT 1
            SQL);
        $statement->execute([':account_id' => $accountId, ':source_bill_id' => $sourceBillId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}
