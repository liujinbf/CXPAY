<?php

return [
    'version' => '1.0.0',
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => rtrim((string)env('APP_URL', ''), '/'),
    'error_reporting' => E_ALL,
    'default_timezone' => 'Asia/Shanghai',
    'public_path' => base_path() . '/public',
    'runtime_path' => base_path() . '/runtime',
    'allow_private_callbacks' => filter_var(env('ALLOW_PRIVATE_CALLBACKS', false), FILTER_VALIDATE_BOOL),
    'system_update_enabled' => filter_var(env('SYSTEM_UPDATE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'install_lock' => (string)env('INSTALL_LOCK_FILE', base_path() . '/install.lock'),
];
