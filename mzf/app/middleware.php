<?php
// 全局中间件定义文件
return [
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    // \think\middleware\SessionInit::class,
    \think\middleware\Throttle::class,
    // 未授权全局拦截（站点未授权 → 系统未授权页 / JSON）
    \app\core\AuthGuardMiddleware::class,
];
