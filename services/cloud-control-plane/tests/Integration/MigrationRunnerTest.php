<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Shared\Database\MigrationRunner;
use CloudControl\Tests\Support\MySqlTestCase;

final class MigrationRunnerTest extends MySqlTestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }

        parent::tearDown();
    }

    public function testMigrationRunsOnceAndRejectsChangedChecksum(): void
    {
        $directory = $this->temporaryMigrationDirectory([
            '001_create_probe.sql' => 'CREATE TABLE cloud_probe (id CHAR(36) PRIMARY KEY)',
        ]);
        $runner = new MigrationRunner($this->pdo());

        self::assertSame(['001_create_probe.sql'], $runner->migrate($directory)->applied);
        self::assertSame([], $runner->migrate($directory)->applied);

        file_put_contents(
            $directory . '/001_create_probe.sql',
            'CREATE TABLE changed_probe (id INT)'
        );

        $this->expectExceptionMessage('已执行迁移的校验和发生变化');
        $runner->migrate($directory);
    }

    public function testFailureNamesMigrationButDoesNotLeakSqlOrDsn(): void
    {
        $directory = $this->temporaryMigrationDirectory([
            '001_secret_failure.sql' => 'THIS IS PRIVATE INVALID SQL',
        ]);

        try {
            (new MigrationRunner($this->pdo()))->migrate($directory);
            self::fail('非法迁移应失败');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('001_secret_failure.sql', $exception->getMessage());
            self::assertStringNotContainsString('THIS IS PRIVATE', $exception->getMessage());
            self::assertStringNotContainsString('mysql:', $exception->getMessage());
        }
    }

    /** @param array<string, string> $files */
    private function temporaryMigrationDirectory(array $files): string
    {
        $directory = sys_get_temp_dir() . '/cxpay-cloud-migration-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;

        foreach ($files as $name => $contents) {
            file_put_contents($directory . '/' . $name, $contents);
        }

        return $directory;
    }
}
