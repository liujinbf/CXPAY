<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use RuntimeException;
use Tests\Support\WxpayClerkDatabaseTestCase;
use WxpayClerk\Database;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';

final class WxpayClerkDatabaseTest extends WxpayClerkDatabaseTestCase
{
    public function testMigratesLegacyDatabaseWithoutLosingOrdersOrAccounts(): void
    {
        $legacy = new PDO('sqlite:' . $this->databasePath);
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $legacy->exec(<<<'SQL'
            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id TEXT NOT NULL,
                channel_id TEXT NOT NULL,
                out_trade_no TEXT NOT NULL UNIQUE,
                amount TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'PENDING',
                matched_at INTEGER,
                source_bill_id TEXT
            );
            CREATE TABLE accounts (
                id TEXT PRIMARY KEY,
                nickname TEXT NOT NULL DEFAULT '',
                gewe_app_id TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'OFFLINE',
                last_seen_at INTEGER,
                created_at INTEGER NOT NULL
            );
            INSERT INTO orders(account_id, channel_id, out_trade_no, amount, expires_at, created_at, status)
            VALUES('acc_1', 'ch_1', 'CX1001', '12.30', 4102444800, 1700000000, 'CONFIRMED');
            INSERT INTO accounts(id, nickname, gewe_app_id, status, created_at)
            VALUES('acc_1', '店员', 'gewe_1', 'ONLINE', 1700000000);
            SQL);
        $legacy = null;

        $database = new Database($this->databasePath);

        self::assertSame('CX1001', $database->pdo()->query('SELECT out_trade_no FROM orders')->fetchColumn());
        self::assertSame('MATCHED', $database->pdo()->query('SELECT status FROM orders')->fetchColumn());
        self::assertSame('gewe_1', $database->pdo()->query('SELECT gewe_app_id FROM accounts')->fetchColumn());
        self::assertSame(1, (int) $database->pdo()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(
            1,
            (int) $database->pdo()->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'callback_outbox'"
            )->fetchColumn()
        );
    }

    public function testMigrationIsIdempotent(): void
    {
        $first = new Database($this->databasePath);
        $first = null;

        $second = new Database($this->databasePath);

        self::assertSame(1, (int) $second->pdo()->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testTransactionRollsBackAllWritesWhenCallbackFails(): void
    {
        $database = new Database($this->databasePath);

        try {
            $database->transaction(function (PDO $pdo): void {
                $pdo->exec(
                    "INSERT INTO accounts(id, nickname, gewe_app_id, status, created_at) "
                    . "VALUES('rollback_account', '回滚测试', 'rollback_app', 'ONLINE', 1700000000)"
                );
                throw new RuntimeException('触发回滚');
            });
            self::fail('事务回调异常必须继续抛出');
        } catch (RuntimeException $exception) {
            self::assertSame('触发回滚', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) $database->pdo()->query("SELECT COUNT(*) FROM accounts WHERE id = 'rollback_account'")->fetchColumn()
        );
    }
}
