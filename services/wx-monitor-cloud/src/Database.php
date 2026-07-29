<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    public static function connect(string $dsn, ?string $username = null, ?string $password = null): PDO
    {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException("WX Monitor Cloud 暂不支持数据库驱动: {$driver}");
        }
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
            $pdo->exec('PRAGMA busy_timeout=5000');
        } else {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        self::migrate($pdo, $driver);
        return $pdo;
    }

    private static function migrate(PDO $pdo, string $driver): void
    {
        $mysqlLockAcquired = false;
        $sqliteTransactionStarted = false;
        try {
            if ($driver === 'mysql') {
                $lockResult = $pdo->query("SELECT GET_LOCK('wxmc_schema_migration', 30)")->fetchColumn();
                if ((int)$lockResult !== 1) {
                    throw new RuntimeException('等待数据库迁移锁超时');
                }
                $mysqlLockAcquired = true;
            } else {
                // BEGIN IMMEDIATE 可避免两个 SQLite 进程同时判断迁移尚未执行。
                $pdo->exec('BEGIN IMMEDIATE');
                $sqliteTransactionStarted = true;
            }

            $pdo->exec(SchemaDefinition::migrationTable($driver));
            $applied = array_map(
                'intval',
                $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)
            );
            foreach (SchemaDefinition::migrations($driver) as $version => $statements) {
                if (in_array($version, $applied, true)) {
                    continue;
                }
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $statement = $pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(?, ?)');
                $statement->execute([$version, time()]);
            }

            if ($sqliteTransactionStarted) {
                // PDO 不会把手工执行的 BEGIN IMMEDIATE 记录为 inTransaction()。
                $pdo->exec('COMMIT');
                $sqliteTransactionStarted = false;
            }
        } catch (Throwable $e) {
            if ($sqliteTransactionStarted) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // 保留原始迁移异常。
                }
            }
            throw new RuntimeException("数据库迁移执行失败: {$e->getMessage()}", 0, $e);
        } finally {
            if ($mysqlLockAcquired) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('wxmc_schema_migration')")->fetchColumn();
                } catch (Throwable) {
                    // 连接关闭后 MySQL 会自动释放会话锁，不能掩盖原迁移结果。
                }
            }
        }
    }
}
