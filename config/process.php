<?php

return [
    // 文件监控进程（开发环境热重载）
    '' => [
        'handler'     => process\FileMonitor::class,
        'reloadable'  => false,
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
    ],

    // 通道定时维护进程
    'channel_timer' => [
        'handler'  => process\ChannelTimerProcess::class,
        'count'    => 1,          // 单进程，避免定时任务重复执行
        'reloadable' => true,
    ],
];
