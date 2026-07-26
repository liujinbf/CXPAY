<?php

return [
    '' => [
        'handler' => process\FileMonitor::class,
        'reloadable' => false,
        'constructor' => [
            'monitor_dir' => [
                app_path(),
                config_path(),
                base_path() . '/support',
            ],
            'monitor_extensions' => [
                'php', 'html', 'htm', 'env'
            ]
        ]
    ]
];
