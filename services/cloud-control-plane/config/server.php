<?php

declare(strict_types=1);

use CloudControl\Shared\Config\Environment;

return [
    'listen' => 'http://' . Environment::get('CLOUD_HOST', '127.0.0.1') . ':' . Environment::get('CLOUD_PORT', '8890'),
    'transport' => 'tcp',
    'context' => [],
    'name' => 'CXPAY Cloud Control Plane',
    'count' => (int)Environment::get('CLOUD_WEBMAN_WORKERS', 4),
    'user' => '',
    'group' => '',
    'reusePort' => false,
    'event_loop' => '',
    'stop_timeout' => 2,
    'pid_file' => runtime_path() . '/webman.pid',
    'status_file' => runtime_path() . '/webman.status',
    'stdout_file' => runtime_path() . '/logs/stdout.log',
    'log_file' => runtime_path() . '/logs/workerman.log',
    'max_package_size' => 2 * 1024 * 1024,
];
