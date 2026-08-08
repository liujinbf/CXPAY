#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudControl\Shared\Database\ConnectionFactory;
use CloudControl\Shared\Database\MigrationRunner;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (file_exists($root . '/.env')) {
    Dotenv::createUnsafeMutable($root)->load();
}

try {
    $connection = ConnectionFactory::fromEnvironment()->migration();
    $report = (new MigrationRunner($connection))->migrate($root . '/migrations');
    fwrite(STDOUT, json_encode([
        'status' => 'ok',
        'applied' => $report->applied,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable) {
    fwrite(STDERR, '云端数据库迁移失败，请检查安全日志和数据库配置。' . PHP_EOL);
    exit(1);
}
