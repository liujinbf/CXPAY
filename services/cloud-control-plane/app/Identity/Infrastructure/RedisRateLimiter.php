<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Port\RateLimiter;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use Predis\ClientInterface;

final readonly class RedisRateLimiter implements RateLimiter
{
    private const SCRIPT = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return {current, redis.call('TTL', KEYS[1])}
LUA;

    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'cxpay-cloud:rate:'
    ) {
    }

    public function consume(string $key, int $limit, int $windowSeconds): void
    {
        $result = $this->redis->eval(
            self::SCRIPT,
            1,
            $this->prefix . hash('sha256', $key),
            $windowSeconds
        );
        $current = (int)$result[0];
        $ttl = max(1, (int)$result[1]);
        if ($current > $limit) {
            throw new CloudException(
                ErrorCode::RATE_LIMITED,
                '请求过于频繁',
                429,
                true,
                ['retry_after' => $ttl]
            );
        }
    }
}
