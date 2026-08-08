<?php

declare(strict_types=1);

use CloudControl\Shared\Config\Environment;

return [
    'version' => '0.1.0-m1a',
    'debug' => filter_var(Environment::get('CLOUD_APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string)Environment::get('CLOUD_API_URL', 'http://127.0.0.1:8890'), '/'),
    'default_timezone' => 'UTC',
    'public_path' => base_path() . '/public',
    'runtime_path' => base_path() . '/runtime',
];
