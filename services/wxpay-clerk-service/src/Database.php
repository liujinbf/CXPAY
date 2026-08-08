<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $directory = dirname($sqlitePath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException("SQLite 存储目录创建失败: {$directory}");
        }

        $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        (new SchemaMigrator())->migrate($this->pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback($this->pdo);
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

}
