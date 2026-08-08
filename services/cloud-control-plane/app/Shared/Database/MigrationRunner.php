<?php

declare(strict_types=1);

namespace CloudControl\Shared\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const LOCK_NAME = 'cxpay_cloud_schema_migration';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(string $directory): MigrationReport
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('迁移目录不存在');
        }

        $this->acquireLock();
        try {
            $this->ensureMigrationTable();
            $applied = [];
            $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
            sort($files, SORT_STRING);

            foreach ($files as $file) {
                $version = basename($file);
                $checksum = hash_file('sha256', $file);
                if ($checksum === false) {
                    throw new RuntimeException(sprintf('无法读取迁移文件 %s', $version));
                }

                $executedChecksum = $this->executedChecksum($version);
                if ($executedChecksum !== null) {
                    if (!hash_equals($executedChecksum, $checksum)) {
                        throw new RuntimeException(
                            sprintf('迁移文件 %s：已执行迁移的校验和发生变化', $version)
                        );
                    }
                    continue;
                }

                $this->apply($file, $version, $checksum);
                $applied[] = $version;
            }

            return new MigrationReport($applied);
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock(): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $statement->execute(['lock_name' => self::LOCK_NAME]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('无法取得云端数据库迁移锁');
        }
    }

    private function releaseLock(): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => self::LOCK_NAME]);
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cloud_schema_migrations (
    version VARCHAR(191) PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    executed_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    private function executedChecksum(string $version): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT checksum FROM cloud_schema_migrations WHERE version = :version'
        );
        $statement->execute(['version' => $version]);
        $checksum = $statement->fetchColumn();

        return $checksum === false ? null : (string)$checksum;
    }

    private function apply(string $file, string $version, string $checksum): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException(sprintf('无法读取迁移文件 %s', $version));
        }

        try {
            $this->pdo->exec($sql);
            (new PdoTransactionManager($this->pdo))->run(function (PDO $pdo) use ($version, $checksum): void {
                $statement = $pdo->prepare(<<<'SQL'
INSERT INTO cloud_schema_migrations (version, checksum, executed_at)
VALUES (:version, :checksum, UTC_TIMESTAMP(6))
SQL);
                $statement->execute([
                    'version' => $version,
                    'checksum' => $checksum,
                ]);
            });
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf('迁移文件 %s 执行失败', $version),
                0,
                $exception
            );
        }
    }
}
