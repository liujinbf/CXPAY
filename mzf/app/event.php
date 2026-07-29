<?php
// 事件定义文件
return [
    'bind' => [
    ],

    'listen' => [
        'AppInit'  => [],
        'HttpRun'  => [],
        'HttpEnd'  => [],
        'LogLevel' => [],
        'LogWrite' => [],
        'userLoginSuccess'    => ['app\common\listener\UserLoginSuccess'],
        'userRegisterSuccess' => ['app\common\listener\UserRegisterSuccess'],
    ],

    'subscribe' => [
    ],
];
