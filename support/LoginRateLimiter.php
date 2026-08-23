<?php

declare(strict_types=1);

namespace support;

/**
 * 基于 Redis 的登录失败频率限制器。
 *
 * 使用方式（正确三步流程）：
 *   1. 登录前：tooManyAttempts()  — 判断是否已超限
 *   2. 登录失败：increment()      — 计数 +1
 *   3. 登录成功：clear()          — 清除计数
 *
 * 注意：tooManyAttempts() 本身不再自动 incr，避免查询与计数混用导致双重计数。
 */
final class LoginRateLimiter
{
    private static function buildKey(string $scope, string $identifier): string
    {
        return 'cx:login_limit:' . $scope . ':' . hash('sha256', $identifier);
    }

    private static function getRedis()
    {
        if (class_exists(\Webman\Redis\Client::class)) {
            try {
                return \Webman\Redis\Client::connection();
            } catch (\Throwable) {}
        }
        if (class_exists(\Illuminate\Support\Facades\Redis::class)) {
            try {
                return \Illuminate\Support\Facades\Redis::connection();
            } catch (\Throwable) {}
        }
        return null;
    }

    /**
     * 判断当前标识符是否已超过最大尝试次数（只读，不计数）。
     *
     * @param int $limit  最大允许失败次数，默认 8
     * @param int $window 计数窗口秒数，默认 300 秒（5 分钟）
     */
    public static function tooManyAttempts(
        string $scope,
        string $identifier,
        int    $limit  = 8,
        int    $window = 300
    ): bool {
        try {
            $redis = self::getRedis();
            if (!$redis) {
                return false;
            }
            $attempts = (int)$redis->get(self::buildKey($scope, $identifier));
            return $attempts >= $limit;
        } catch (\Throwable $e) {
            error_log('[LoginRateLimiter] Redis 不可用，降级允许: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 登录失败时调用，将当前标识符的失败计数 +1。
     *
     * @param int $window 窗口秒数，首次写入时设置 TTL；不重置已有 TTL
     */
    public static function increment(
        string $scope,
        string $identifier,
        int    $window = 300
    ): void {
        try {
            $redis = self::getRedis();
            if (!$redis) {
                return;
            }
            $key   = self::buildKey($scope, $identifier);
            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, $window);
            }
        } catch (\Throwable $e) {
            error_log('[LoginRateLimiter] increment 失败: ' . $e->getMessage());
        }
    }

    /**
     * 登录成功后调用，清除限流计数。
     */
    public static function clear(string $scope, string $identifier): void
    {
        try {
            $redis = self::getRedis();
            if ($redis) {
                $redis->del(self::buildKey($scope, $identifier));
            }
        } catch (\Throwable) {
        }
    }
}

