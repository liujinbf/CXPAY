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
}
