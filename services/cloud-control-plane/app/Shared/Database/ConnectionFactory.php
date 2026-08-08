<?php

declare(strict_types=1);

namespace CloudControl\Shared\Database;

use CloudControl\Shared\Config\Environment;
use PDO;

final readonly class ConnectionFactory
{
    public function __construct(
        private string $dsn,
        private string $username,
        private string $password
    ) {
    }

    public static function fromEnvironment(): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string)Environment::get('CLOUD_DB_HOST', '127.0.0.1'),
            (int)Environment::get('CLOUD_DB_PORT', 3306),
            (string)Environment::get('CLOUD_DB_DATABASE', ''),
        );

        return new self(
            $dsn,
            (string)Environment::get('CLOUD_DB_USERNAME', ''),
            (string)Environment::get('CLOUD_DB_PASSWORD', '')
        );
    }

    public function application(): PDO
    {
        return $this->connect(false);
    }

    public function migration(): PDO
    {
        return $this->connect(true);
    }

    private function connect(bool $allowMultipleStatements): PDO
    {
        return new PDO($this->dsn, $this->username, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => $allowMultipleStatements,
        ]);
    }
}
