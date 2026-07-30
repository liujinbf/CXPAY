<?php

declare(strict_types=1);

namespace AlipayMonitorCloud;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS amc_principal_keys (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                principal_id TEXT    NOT NULL,
                key_role     TEXT    NOT NULL,
                secret_enc   TEXT    NOT NULL,
                status       TEXT    NOT NULL,
                created_at   INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_amc_keys ON amc_principal_keys(principal_id, key_role, status);

            CREATE TABLE IF NOT EXISTS amc_auth_sessions (
                id           TEXT    PRIMARY KEY,
                principal_id TEXT    NOT NULL,
                status       TEXT    NOT NULL,
                qr_url       TEXT,
                external_ref TEXT,
                display_name TEXT,
                expires_at   INTEGER NOT NULL,
                created_at   INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS amc_orders (
                out_trade_no TEXT    PRIMARY KEY,
                principal_id TEXT    NOT NULL,
                amount       TEXT    NOT NULL,
                expires_at   INTEGER NOT NULL,
                status       TEXT    NOT NULL,
                created_at   INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS amc_payment_events (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                principal_id   TEXT    NOT NULL,
                source_bill_id TEXT    NOT NULL UNIQUE,
                amount         TEXT    NOT NULL,
                occurred_at    INTEGER NOT NULL,
                matched_order  TEXT,
                created_at     INTEGER NOT NULL
            );'
        );
    }
}
