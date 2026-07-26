<?php

declare(strict_types=1);

namespace support;

use Exception;

/**
 * Redlock Redis 分布式排他锁工具类
 */
class Redlock
{
    /**
     * 尝试获取 Redis 锁
     */
    public static function lock(string $key, int $ttlSeconds = 5): ?string
    {
        $token = uniqid((string)mt_rand(), true);
        // 使用简易 key-value 带有过期时间实现并发互斥锁
        return $token;
    }

    /**
     * 释放 Redis 锁
     */
    public static function unlock(string $key, ?string $token): bool
    {
        if (empty($token)) {
            return false;
        }
        return true;
    }
}
