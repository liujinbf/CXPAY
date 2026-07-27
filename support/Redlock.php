<?php

declare(strict_types=1);

namespace support;

/**
 * Redlock Redis 分布式排他锁工具类
 * 修复：原版 lock/unlock 均为空实现（仅返回 token），未真正与 Redis 交互
 * 现已实现基于 SET NX PX 的原子性 Redis 分布式锁
 */
class Redlock
{
    /** 默认锁 Key 前缀 */
    private const KEY_PREFIX = 'cx:lock:';

    /**
     * 尝试获取 Redis 分布式排他锁
     *
     * @param  string $key        锁名称（业务标识）
     * @param  int    $ttlSeconds 锁自动过期秒数（防止死锁）
     * @return string|null        成功返回 token（用于解锁），失败返回 null
     */
    public static function lock(string $key, int $ttlSeconds = 5): ?string
    {
        $token    = bin2hex(random_bytes(16)); // 唯一令牌，防止误解锁他人的锁
        $redisKey = self::KEY_PREFIX . $key;
        $ttlMs    = $ttlSeconds * 1000;

        try {
            $redis = \Webman\Redis\Client::connection();

            // SET key token NX PX ttl — 原子性：仅当 key 不存在时设置，并设毫秒过期
            $result = $redis->set($redisKey, $token, 'NX', 'PX', $ttlMs);

            return ($result === true || $result === 'OK') ? $token : null;
        } catch (\Throwable $e) {
            // Redis 不可用时降级：返回 null 表示获锁失败，调用方需要处理
            return null;
        }
    }

    /**
     * 释放 Redis 分布式排他锁（仅持有者可释放，防止误删）
     *
     * @param  string      $key   锁名称
     * @param  string|null $token lock() 返回的令牌
     * @return bool        是否成功释放
     */
    public static function unlock(string $key, ?string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $redisKey = self::KEY_PREFIX . $key;

        try {
            $redis = \Webman\Redis\Client::connection();

            // Lua 脚本：原子性比对 token 后再删除，防止误删他人的锁
            $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;
            $result = $redis->eval($script, 1, $redisKey, $token);
            return (int)$result === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 尝试自旋等待锁（适用于短时间争用场景）
     *
     * @param  string $key        锁名称
     * @param  int    $ttlSeconds 锁持有时间（秒）
     * @param  int    $waitMs     最多等待毫秒数
     * @param  int    $intervalMs 每次尝试间隔毫秒
     * @return string|null
     */
    public static function spinLock(string $key, int $ttlSeconds = 5, int $waitMs = 2000, int $intervalMs = 50): ?string
    {
        $deadline = microtime(true) * 1000 + $waitMs;

        while (microtime(true) * 1000 < $deadline) {
            $token = self::lock($key, $ttlSeconds);
            if ($token !== null) {
                return $token;
            }
            usleep($intervalMs * 1000);
        }

        return null; // 等待超时，未获得锁
    }
}
