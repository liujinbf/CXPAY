<?php

declare(strict_types=1);

namespace support;

/**
 * 基于 Redis 的登录失败频率限制器。
 */
final class LoginRateLimiter
{
    public static function tooManyAttempts(string $scope, string $identifier, int $limit = 8, int $window = 300): bool
    {
        try {
            $redis = \Webman\Redis\Client::connection();
            $key = 'cx:login_limit:' . $scope . ':' . hash('sha256', $identifier);
            $attempts = (int)$redis->incr($key);
            if ($attempts === 1) {
                $redis->expire($key, $window);
            }
            return $attempts > $limit;
        } catch (\Throwable $e) {
            error_log('[LoginRateLimiter] Redis 不可用，拒绝登录: ' . $e->getMessage());
            return true;
        }
    }

    public static function clear(string $scope, string $identifier): void
    {
        try {
            \Webman\Redis\Client::del('cx:login_limit:' . $scope . ':' . hash('sha256', $identifier));
        } catch (\Throwable) {
        }
    }
}
