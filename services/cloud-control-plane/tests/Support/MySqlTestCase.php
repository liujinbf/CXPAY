<?php

declare(strict_types=1);

namespace CloudControl\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class MySqlTestCase extends TestCase
{
    private static ?PDO $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ((string)getenv('CLOUD_TEST_DB_DSN') === '') {
            self::markTestSkipped('未配置 CLOUD_TEST_DB_DSN，跳过 MySQL 集成测试');
        }

        $this->resetDatabase();
    }

    final protected function pdo(): PDO
    {
        if (self::$connection === null) {
            self::$connection = new PDO(
                (string)getenv('CLOUD_TEST_DB_DSN'),
                (string)getenv('CLOUD_TEST_DB_USERNAME'),
                (string)getenv('CLOUD_TEST_DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                ]
            );
        }

        return self::$connection;
    }

    private function resetDatabase(): void
    {
        $pdo = $this->pdo();
        $tables = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
                $pdo->exec('DROP TABLE ' . $quoted);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
