<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'settle:run'     => \app\command\SettleRun::class,
        'monitor:run'    => \app\command\MonitorRun::class,
        'monitor:status' => \app\command\MonitorStatus::class,
    ],
];
