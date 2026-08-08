<?php

declare(strict_types=1);

return [
    'listen' => 'http://' . env('CLOUD_HOST', '127.0.0.1') . ':' . env('CLOUD_PORT', '8890'),
    'transport' => 'tcp',
    'context' => [],
    'name' => 'CXPAY Cloud Control Plane',
    'count' => (int)env('CLOUD_WEBMAN_WORKERS', 4),
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
