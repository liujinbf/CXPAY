<?php

declare(strict_types=1);

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

return [
    'default' => [
        'handlers' => [[
            'class' => RotatingFileHandler::class,
            'constructor' => [
                runtime_path() . '/logs/cloud-control-plane.log',
                14,
                Logger::INFO,
            ],
        ]],
    ],
];
