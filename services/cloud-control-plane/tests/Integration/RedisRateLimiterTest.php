<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Identity\Application\RegistrationChallenge;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Infrastructure\RedisRateLimiter;
use CloudControl\Identity\Infrastructure\RedisRegistrationChallengeStore;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RedisRateLimiterTest extends TestCase
{
    private Client $redis;

    protected function setUp(): void
    {
        if ((string)getenv('CLOUD_TEST_REDIS_HOST') === '') {
            self::markTestSkipped('未配置 CLOUD_TEST_REDIS_HOST，跳过 Redis 集成测试');
        }

        $this->redis = new Client([
            'scheme' => 'tcp',
            'host' => (string)getenv('CLOUD_TEST_REDIS_HOST'),
            'port' => (int)getenv('CLOUD_TEST_REDIS_PORT'),
        ]);
        $this->redis->flushdb();
    }

    public function testRateLimiterAtomicallyRejectsRequestsAboveLimit(): void
    {
        $limiter = new RedisRateLimiter($this->redis, 'cxpay-cloud-test:rate:');
        $limiter->consume('email:user@example.com', 2, 60);
        $limiter->consume('email:user@example.com', 2, 60);

        try {
            $limiter->consume('email:user@example.com', 2, 60);
            self::fail('超过限额必须被拒绝');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::RATE_LIMITED, $exception->errorCode);
            self::assertGreaterThan(0, $exception->data['retry_after']);
        }
    }

    public function testRegistrationChallengeStoresOnlyDigestKeyAndNoRawToken(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $store = new RedisRegistrationChallengeStore(
            $this->redis,
            $clock,
            str_repeat('h', 32),
            'cxpay-cloud-test:registration:'
        );
        $challenge = new RegistrationChallenge(
            'raw-registration-token-secret',
            '00000000-0000-7000-8000-000000000001',
            'user@example.com',
            UserStatus::PENDING_IDENTITY,
            $clock->now()->modify('+15 minutes')
        );

        $store->save($challenge);
        $keys = $this->redis->keys('cxpay-cloud-test:registration:*');

        self::assertCount(1, $keys);
        self::assertStringNotContainsString($challenge->token, $keys[0]);
        self::assertStringNotContainsString($challenge->token, (string)$this->redis->get($keys[0]));
        self::assertSame($challenge->userId, $store->find($challenge->token)?->userId);
    }
}
