<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use InvalidArgumentException;

final class SchemaDefinition
{
    public static function migrationTable(string $driver): string
    {
        return match ($driver) {
            'sqlite' => 'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                applied_at INTEGER NOT NULL
            )',
            'mysql' => 'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INT UNSIGNED NOT NULL PRIMARY KEY,
                applied_at BIGINT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            default => throw new InvalidArgumentException("不支持的数据库方言: {$driver}"),
        };
    }

    /** @return array<int, list<string>> */
    public static function migrations(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [1 => self::sqliteV1(), 2 => self::sqliteV2(), 3 => self::sqliteV3(), 4 => self::sqliteV4()],
            'mysql' => [1 => self::mysqlV1(), 2 => self::mysqlV2(), 3 => self::mysqlV3(), 4 => self::mysqlV4()],
            default => throw new InvalidArgumentException("不支持的数据库方言: {$driver}"),
        };
    }

    /** @return list<string> */
    private static function sqliteV1(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS principals (
                id VARCHAR(128) PRIMARY KEY, role VARCHAR(20) NOT NULL,
                request_secret TEXT NOT NULL, response_secret TEXT NOT NULL DEFAULT \'\',
                callback_url TEXT NOT NULL DEFAULT \'\', status INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS request_nonces (
                principal_id VARCHAR(128) NOT NULL, nonce VARCHAR(128) NOT NULL,
                expires_at INTEGER NOT NULL, PRIMARY KEY (principal_id, nonce)
            )',
            'CREATE INDEX IF NOT EXISTS idx_nonce_expiry ON request_nonces(expires_at)',
            'CREATE TABLE IF NOT EXISTS auth_sessions (
                id VARCHAR(64) PRIMARY KEY, client_id VARCHAR(128) NOT NULL,
                collector_id VARCHAR(128) NOT NULL DEFAULT \'\', status VARCHAR(32) NOT NULL,
                qr_url TEXT NOT NULL DEFAULT \'\', account_id VARCHAR(64) NOT NULL DEFAULT \'\',
                message TEXT NOT NULL DEFAULT \'\', created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_auth_pending ON auth_sessions(status, expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_auth_collector ON auth_sessions(collector_id, status, expires_at)',
            'CREATE TABLE IF NOT EXISTS accounts (
                id VARCHAR(64) PRIMARY KEY, client_id VARCHAR(128) NOT NULL,
                collector_id VARCHAR(128) NOT NULL, external_ref VARCHAR(255) NOT NULL DEFAULT \'\',
                display_name VARCHAR(255) NOT NULL DEFAULT \'\', auth_status VARCHAR(32) NOT NULL,
                capability_status VARCHAR(32) NOT NULL, capabilities_json TEXT NOT NULL DEFAULT \'{}\',
                updated_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_account_client ON accounts(client_id, auth_status)',
            'CREATE INDEX IF NOT EXISTS idx_account_collector ON accounts(collector_id, auth_status)',
            'CREATE TABLE IF NOT EXISTS pending_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT, client_id VARCHAR(128) NOT NULL,
                account_id VARCHAR(64) NOT NULL, out_trade_no VARCHAR(128) NOT NULL,
                amount VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL,
                created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
                UNIQUE(client_id, out_trade_no)
            )',
            'CREATE INDEX IF NOT EXISTS idx_order_match ON pending_orders(account_id, amount, status, expires_at)',
            'CREATE TABLE IF NOT EXISTS payment_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, account_id VARCHAR(64) NOT NULL,
                source_bill_id VARCHAR(128) NOT NULL, amount VARCHAR(32) NOT NULL,
                occurred_at INTEGER NOT NULL, status VARCHAR(32) NOT NULL,
                matched_order_id INTEGER, created_at INTEGER NOT NULL,
                UNIQUE(account_id, source_bill_id)
            )',
            'CREATE INDEX IF NOT EXISTS idx_event_status ON payment_events(status, created_at)',
            'CREATE TABLE IF NOT EXISTS callback_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT, client_id VARCHAR(128) NOT NULL,
                event_id INTEGER NOT NULL, callback_url TEXT NOT NULL, payload_json TEXT NOT NULL,
                status VARCHAR(20) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at INTEGER NOT NULL, last_error TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL, UNIQUE(client_id, event_id)
            )',
            'CREATE INDEX IF NOT EXISTS idx_outbox_due ON callback_outbox(status, next_attempt_at)',
        ];
    }

    /** @return list<string> */
    private static function mysqlV1(): array
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'CREATE TABLE IF NOT EXISTS principals (
                id VARCHAR(128) NOT NULL PRIMARY KEY, role VARCHAR(20) NOT NULL,
                request_secret TEXT NOT NULL, response_secret TEXT NOT NULL,
                callback_url TEXT NOT NULL, status TINYINT UNSIGNED NOT NULL DEFAULT 1,
                created_at BIGINT UNSIGNED NOT NULL,
                KEY idx_principal_role_status(role, status)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS request_nonces (
                principal_id VARCHAR(128) NOT NULL, nonce VARCHAR(128) NOT NULL,
                expires_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (principal_id, nonce), KEY idx_nonce_expiry(expires_at)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS auth_sessions (
                id VARCHAR(64) NOT NULL PRIMARY KEY, client_id VARCHAR(128) NOT NULL,
                collector_id VARCHAR(128) NOT NULL DEFAULT \'\', status VARCHAR(32) NOT NULL,
                qr_url TEXT NOT NULL, account_id VARCHAR(64) NOT NULL DEFAULT \'\',
                message TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
                KEY idx_auth_pending(status, expires_at),
                KEY idx_auth_collector(collector_id, status, expires_at)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS accounts (
                id VARCHAR(64) NOT NULL PRIMARY KEY, client_id VARCHAR(128) NOT NULL,
                collector_id VARCHAR(128) NOT NULL, external_ref VARCHAR(255) NOT NULL DEFAULT \'\',
                display_name VARCHAR(255) NOT NULL DEFAULT \'\', auth_status VARCHAR(32) NOT NULL,
                capability_status VARCHAR(32) NOT NULL, capabilities_json JSON NOT NULL,
                updated_at BIGINT UNSIGNED NOT NULL,
                KEY idx_account_client(client_id, auth_status),
                KEY idx_account_collector(collector_id, auth_status)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS pending_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                client_id VARCHAR(128) NOT NULL, account_id VARCHAR(64) NOT NULL,
                out_trade_no VARCHAR(128) NOT NULL, amount DECIMAL(12,2) NOT NULL,
                status VARCHAR(20) NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
                expires_at BIGINT UNSIGNED NOT NULL,
                UNIQUE KEY uk_client_trade(client_id, out_trade_no),
                KEY idx_order_match(account_id, amount, status, expires_at)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS payment_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id VARCHAR(64) NOT NULL, source_bill_id VARCHAR(128) NOT NULL,
                amount DECIMAL(12,2) NOT NULL, occurred_at BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL, matched_order_id BIGINT UNSIGNED NULL,
                created_at BIGINT UNSIGNED NOT NULL,
                UNIQUE KEY uk_account_bill(account_id, source_bill_id),
                KEY idx_event_status(status, created_at)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS callback_outbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                client_id VARCHAR(128) NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
                callback_url TEXT NOT NULL, payload_json JSON NOT NULL,
                status VARCHAR(20) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at BIGINT UNSIGNED NOT NULL, last_error TEXT NOT NULL,
                created_at BIGINT UNSIGNED NOT NULL,
                UNIQUE KEY uk_client_event(client_id, event_id),
                KEY idx_outbox_due(status, next_attempt_at)
            )' . $suffix,
        ];
    }

    /** @return list<string> */
    private static function sqliteV2(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS payment_event_reviews (
                event_id INTEGER PRIMARY KEY, client_id VARCHAR(128) NOT NULL,
                action VARCHAR(20) NOT NULL, order_id INTEGER,
                operator VARCHAR(64) NOT NULL, note TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_review_client_time ON payment_event_reviews(client_id, created_at)',
        ];
    }

    /** @return list<string> */
    private static function mysqlV2(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS payment_event_reviews (
                event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                client_id VARCHAR(128) NOT NULL, action VARCHAR(20) NOT NULL,
                order_id BIGINT UNSIGNED NULL, operator VARCHAR(64) NOT NULL,
                note TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
                KEY idx_review_client_time(client_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    /** @return list<string> */
    private static function sqliteV3(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS principal_keys (
                id VARCHAR(64) PRIMARY KEY, principal_id VARCHAR(128) NOT NULL,
                key_type VARCHAR(20) NOT NULL, encrypted_secret TEXT NOT NULL,
                status VARCHAR(20) NOT NULL, not_before INTEGER NOT NULL,
                expires_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_principal_key_lookup
             ON principal_keys(principal_id, key_type, status, not_before, expires_at)',
        ];
    }

    /** @return list<string> */
    private static function mysqlV3(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS principal_keys (
                id VARCHAR(64) NOT NULL PRIMARY KEY, principal_id VARCHAR(128) NOT NULL,
                key_type VARCHAR(20) NOT NULL, encrypted_secret TEXT NOT NULL,
                status VARCHAR(20) NOT NULL, not_before BIGINT UNSIGNED NOT NULL,
                expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at BIGINT UNSIGNED NOT NULL,
                KEY idx_principal_key_lookup(principal_id, key_type, status, not_before, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    /** @return list<string> */
    private static function sqliteV4(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS principal_activity (
                principal_id VARCHAR(128) PRIMARY KEY, role VARCHAR(20) NOT NULL,
                last_authenticated_at INTEGER NOT NULL, request_count INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE INDEX IF NOT EXISTS idx_activity_role_time
             ON principal_activity(role, last_authenticated_at)',
        ];
    }

    /** @return list<string> */
    private static function mysqlV4(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS principal_activity (
                principal_id VARCHAR(128) NOT NULL PRIMARY KEY, role VARCHAR(20) NOT NULL,
                last_authenticated_at BIGINT UNSIGNED NOT NULL,
                request_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                KEY idx_activity_role_time(role, last_authenticated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
