<?php

return [
    // 通道定时维护进程
    'channel_timer' => [
        'handler'  => process\ChannelTimerProcess::class,
        'count'    => 1,          // 单进程，避免定时任务重复执行
        'reloadable' => true,
    ],
];
