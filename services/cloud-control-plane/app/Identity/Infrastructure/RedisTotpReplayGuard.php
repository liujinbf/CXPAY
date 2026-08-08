<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Port\TotpReplayGuard;
use Predis\ClientInterface;

final readonly class RedisTotpReplayGuard implements TotpReplayGuard
{
    private const SCRIPT = <<<'LUA'
if redis.call('SETNX', KEYS[1], '1') == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
    return 1
end
return 0
LUA;

    public function __construct(
        private ClientInterface $redis,
        private string $prefix = 'cxpay-cloud:totp-replay:'
    ) {
    }

    public function claim(string $userId, int $timeStep, int $ttlSeconds): bool
    {
        $key = $this->prefix . hash('sha256', $userId . "\n" . $timeStep);
        return (int)$this->redis->eval(self::SCRIPT, 1, $key, $ttlSeconds) === 1;
    }
}
