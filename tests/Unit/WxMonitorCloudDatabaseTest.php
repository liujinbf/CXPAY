<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use WxMonitorCloud\Database;
use WxMonitorCloud\SchemaDefinition;

final class WxMonitorCloudDatabaseTest extends TestCase
{
    public function testSqliteMigrationIsVersionedAndIdempotent(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wxmc-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            $first = Database::connect('sqlite:' . $path);
            self::assertSame('1', (string)$first->query('SELECT version FROM schema_migrations')->fetchColumn());
            self::assertSame(
                '11',
                (string)$first->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
                )->fetchColumn()
            );
            $first = null;

            $second = Database::connect('sqlite:' . $path);
            self::assertSame(
                '1',
                (string)$second->query('SELECT COUNT(*) FROM schema_migrations WHERE version = 1')->fetchColumn()
            );
            $second = null;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($path . '-wal')) {
                unlink($path . '-wal');
            }
            if (is_file($path . '-shm')) {
                unlink($path . '-shm');
            }
        }
    }

    public function testMysqlSchemaUsesMysqlDialect(): void
    {
        $migrations = SchemaDefinition::migrations('mysql');
        $sql = implode("\n", array_merge(
            [SchemaDefinition::migrationTable('mysql')],
            ...array_values($migrations)
        ));

        self::assertStringContainsString('AUTO_INCREMENT', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringContainsString('UNIQUE KEY', $sql);
        self::assertStringNotContainsString('AUTOINCREMENT', $sql);
        self::assertStringNotContainsString('CREATE INDEX IF NOT EXISTS', $sql);
    }

    public function testLegacySqliteDatabaseIsMigratedWithoutLosingData(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wxmc-legacy-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            $legacy = new PDO('sqlite:' . $path);
            $legacy->exec(
                'CREATE TABLE principals (
                    id VARCHAR(128) PRIMARY KEY, role VARCHAR(20) NOT NULL,
                    request_secret TEXT NOT NULL, response_secret TEXT NOT NULL DEFAULT \'\',
                    callback_url TEXT NOT NULL DEFAULT \'\', status INTEGER NOT NULL DEFAULT 1,
                    created_at INTEGER NOT NULL
                )'
            );
            $legacy->exec(
                "INSERT INTO principals(id, role, request_secret, created_at)
                 VALUES('legacy-client', 'client', 'encrypted-secret', 1)"
            );
            $legacy = null;

            $migrated = Database::connect('sqlite:' . $path);
            self::assertSame(
                'encrypted-secret',
                (string)$migrated->query("SELECT request_secret FROM principals WHERE id = 'legacy-client'")->fetchColumn()
            );
            self::assertSame('1', (string)$migrated->query('SELECT version FROM schema_migrations')->fetchColumn());
            self::assertSame(
                '1',
                (string)$migrated->query(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'callback_outbox'"
                )->fetchColumn()
            );
            $migrated = null;
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testUnsupportedDialectIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SchemaDefinition::migrations('pgsql');
    }
}
