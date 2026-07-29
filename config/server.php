<?php

return [
    'listen'           => 'http://0.0.0.0:' . env('PORT', '8787'),
    'transport'        => 'tcp',
    'context'          => [],
    'name'             => 'CXPAY',
    'count'            => (int)env('WEBMAN_WORKERS', 4),
    'user'             => '',
    'group'            => '',
    'reusePort'        => false,
    'event_loop'       => '',
    'stop_timeout'     => 2,
    'pid_file'         => runtime_path() . '/webman.pid',
    'status_file'      => runtime_path() . '/webman.status',
    'stdout_file'      => runtime_path() . '/logs/stdout.log',
    'log_file'         => runtime_path() . '/logs/workerman.log',
    'max_package_size' => 10 * 1024 * 1024,
];
