<?php

/**
 * 全局中间件配置
 *
 * 注意：中间件按数组顺序依次执行，RequestIdMiddleware 必须排在最前面，
 * 确保所有后续中间件和控制器都能读取到 request_id。
 */
return [
    '' => [
        app\middleware\RequestIdMiddleware::class,
        app\middleware\ApiRateLimitMiddleware::class,   // 全局 API 限流（IP+路径滑动窗口）
        app\middleware\AdminChannelListContractMiddleware::class,
    ],
];
