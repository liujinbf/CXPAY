<?php

use Workerman\Protocols\Http\Session\FileSessionHandler;

return [
    'handler' => FileSessionHandler::class,
    'config' => [
        'save_path' => runtime_path() . '/sessions',
    ],
    'name' => 'CXPAYSESSID',
    'lifetime' => 7200,
    'cookie_lifetime' => 7200,
    'same_site' => 'Lax',
    'secure' => str_starts_with((string)env('APP_URL', ''), 'https://'),
    'http_only' => true,
];
