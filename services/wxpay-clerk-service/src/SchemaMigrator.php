<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;

final class SchemaMigrator
{
    private const VERSION = 1;

    public function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id TEXT NOT NULL,
                channel_id TEXT NOT NULL,
                out_trade_no TEXT NOT NULL UNIQUE,
                amount TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                matched_at INTEGER,
                source_bill_id TEXT,
                payment_event_id INTEGER
            );

            CREATE TABLE IF NOT EXISTS accounts (
                id TEXT PRIMARY KEY,
                nickname TEXT NOT NULL DEFAULT '',
                gewe_app_id TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'OFFLINE',
                last_seen_at INTEGER,
                created_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS auth_sessions (
                id TEXT PRIMARY KEY,
                reference TEXT NOT NULL,
                mode TEXT NOT NULL DEFAULT 'clerk',
                qr_url TEXT NOT NULL DEFAULT '',
                account_id TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'PENDING',
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS payment_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id TEXT NOT NULL,
                source_bill_id TEXT NOT NULL,
                amount TEXT NOT NULL,
                payer_name TEXT NOT NULL DEFAULT '',
                remark TEXT NOT NULL DEFAULT '',
                occurred_at INTEGER NOT NULL,
                received_at INTEGER NOT NULL,
                raw_hash TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'RECEIVED',
                out_trade_no TEXT
            );

            CREATE TABLE IF NOT EXISTS review_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_event_id INTEGER,
                account_id TEXT NOT NULL,
                amount TEXT NOT NULL,
                payer_name TEXT NOT NULL DEFAULT '',
                remark TEXT NOT NULL DEFAULT '',
                occurred_at INTEGER NOT NULL,
                received_at INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                source_bill_id TEXT NOT NULL DEFAULT '',
                out_trade_no TEXT,
                operator TEXT,
                note TEXT,
                resolved_at INTEGER
            );

            CREATE TABLE IF NOT EXISTS request_nonces (
                client_id TEXT NOT NULL,
                nonce TEXT NOT NULL,
                used_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                PRIMARY KEY (client_id, nonce)
            );

            CREATE TABLE IF NOT EXISTS callback_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_event_id INTEGER NOT NULL,
                out_trade_no TEXT NOT NULL,
                callback_payload TEXT NOT NULL DEFAULT '{}',
                status TEXT NOT NULL DEFAULT 'PENDING',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at INTEGER NOT NULL,
                lease_until INTEGER,
                last_error TEXT,
                created_at INTEGER NOT NULL,
                sent_at INTEGER
            );
            SQL);

        $this->addColumnIfMissing($pdo, 'orders', 'payment_event_id', 'INTEGER');
        $this->addColumnIfMissing($pdo, 'review_events', 'payment_event_id', 'INTEGER');
        $pdo->exec("UPDATE orders SET status = 'MATCHED' WHERE status = 'CONFIRMED'");

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_orders_lookup
                ON orders (account_id, amount, status, expires_at, created_at);
            CREATE INDEX IF NOT EXISTS idx_accounts_gewe_app_id
                ON accounts (gewe_app_id);
            CREATE UNIQUE INDEX IF NOT EXISTS uq_payment_events_source
                ON payment_events (account_id, source_bill_id);
            CREATE INDEX IF NOT EXISTS idx_payment_events_status
                ON payment_events (status, occurred_at);
            CREATE INDEX IF NOT EXISTS idx_review_events_status
                ON review_events (status, occurred_at);
            CREATE INDEX IF NOT EXISTS idx_request_nonces_expiry
                ON request_nonces (expires_at);
            CREATE UNIQUE INDEX IF NOT EXISTS uq_callback_outbox_event
                ON callback_outbox (payment_event_id);
            CREATE INDEX IF NOT EXISTS idx_callback_outbox_due
                ON callback_outbox (status, next_attempt_at, lease_until);
            SQL);

        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO schema_migrations(version, applied_at) VALUES(:version, :applied_at)'
        );
        $statement->execute([':version' => self::VERSION, ':applied_at' => time()]);
    }

    private function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $existing) {
            if (($existing['name'] ?? null) === $column) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
