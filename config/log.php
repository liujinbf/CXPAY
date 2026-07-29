<?php

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

return [
    'default' => [
        'handlers' => [
            [
                'class'       => RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/cxpay.log',
                    14,
                    Logger::INFO,
                ],
            ],
        ],
    ],
];
