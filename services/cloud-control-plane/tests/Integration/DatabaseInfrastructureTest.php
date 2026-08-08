<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Shared\Database\ConnectionFactory;
use CloudControl\Shared\Database\PdoTransactionManager;
use CloudControl\Tests\Support\MySqlTestCase;
use PDO;
use PDOException;
use RuntimeException;

final class DatabaseInfrastructureTest extends MySqlTestCase
{
    public function testApplicationConnectionRejectsMultipleStatements(): void
    {
        $factory = $this->factory();

        $this->expectException(PDOException::class);
        $factory->application()->exec('SELECT 1; SELECT 2');
    }

    public function testMigrationConnectionAllowsTrustedMultipleStatements(): void
    {
        $connection = $this->factory()->migration();
        $connection->exec(<<<'SQL'
CREATE TABLE cloud_factory_probe_a (id INT PRIMARY KEY);
CREATE TABLE cloud_factory_probe_b (id INT PRIMARY KEY);
SQL);

        $count = $connection->query(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('cloud_factory_probe_a', 'cloud_factory_probe_b')
SQL)->fetchColumn();

        self::assertSame(2, (int)$count);
    }

    public function testTransactionManagerRollsBackDomainChanges(): void
    {
        $this->pdo()->exec('CREATE TABLE cloud_transaction_probe (id INT PRIMARY KEY)');
        $manager = new PdoTransactionManager($this->pdo());

        try {
            $manager->run(function (PDO $pdo): void {
                $pdo->exec('INSERT INTO cloud_transaction_probe (id) VALUES (1)');
                throw new RuntimeException('触发回滚');
            });
            self::fail('事务回调应抛出异常');
        } catch (RuntimeException $exception) {
            self::assertSame('触发回滚', $exception->getMessage());
        }

        self::assertSame(0, (int)$this->pdo()->query(
            'SELECT COUNT(*) FROM cloud_transaction_probe'
        )->fetchColumn());
    }

    private function factory(): ConnectionFactory
    {
        return new ConnectionFactory(
            (string)getenv('CLOUD_TEST_DB_DSN'),
            (string)getenv('CLOUD_TEST_DB_USERNAME'),
            (string)getenv('CLOUD_TEST_DB_PASSWORD')
        );
    }
}
