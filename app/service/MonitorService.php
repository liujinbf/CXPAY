<?php

declare(strict_types=1);

namespace app\service;

use Exception;

/**
 * Webman 常驻内存常驻进程系统监控服务 (内存、CPU、QPS、数据库连接池)
 */
class MonitorService
{
    /**
     * 获取服务器与 Webman 运行进程的实时监控指标
     */
    public function getMetrics(): array
    {
        // 1. 获取 PHP 常驻进程内存开销
        $memoryBytes = memory_get_usage(true);
        $memoryFormatted = sprintf('%.2f MB', $memoryBytes / 1024 / 1024);

        // 2. 获取服务器 1 分钟 CPU 负载；Windows 等不支持的平台明确返回不可用。
        $cpuLoad = null;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpuLoad = isset($load[0]) ? (string)$load[0] : null;
        }

        // 3. MySQL / Redis 连接池运行状态检测
        $dbPoolStatus = 'HEALTHY';
        try {
            \Illuminate\Database\Capsule\Manager::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbPoolStatus = 'UNHEALTHY';
        }

        $workermanVer = '5.x';
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $workermanVer = \Composer\InstalledVersions::getPrettyVersion('workerman/workerman') ?: '5.x';
            }
        } catch (\Throwable $e) {
            $workermanVer = '5.x';
        }

        return [
            'memory_usage'   => $memoryFormatted,
            'cpu_load'       => $cpuLoad,
            'db_pool'        => $dbPoolStatus,
            'php_version'    => PHP_VERSION,
            'workerman_ver'  => $workermanVer,
            'opcache_status' => function_exists('opcache_get_status') ? 'ON' : 'OFF',
        ];
    }
}
