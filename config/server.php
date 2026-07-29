<?php

return [
    // 默认监听全部网卡以兼容 Docker；生产环境可通过 HOST=127.0.0.1 限制为仅由反向代理访问。
    'listen'           => 'http://' . env('HOST', '0.0.0.0') . ':' . env('PORT', '8787'),
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
