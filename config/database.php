<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver'      => 'mysql',
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => env('DB_DATABASE', 'cxpay'),
            'username'    => env('DB_USERNAME', 'root'),
            'password'    => env('DB_PASSWORD', 'root'),
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'prefix'      => 'cx_',
            'strict'      => true,
            'engine'      => null,
            'pool'        => [
                'max_connections' => 50,
                'min_connections' => 2,
                'wait_timeout'    => 3.0,
            ],
        ],
    ],
];
