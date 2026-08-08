<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Infrastructure\RedisOAuthStateStore;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RedisOAuthStateStoreTest extends TestCase
{
    private Client $redis;
    private FrozenClock $clock;

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
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
    }

    public function testStateIsAtomicallyConsumedOnceWithoutPersistingRawValue(): void
    {
        $store = $this->store();
        $state = $this->state('raw-oauth-state-secret-1234567890123456');
        $store->save($state);
        $keys = $this->redis->keys('cxpay-cloud-test:oauth:*');

        self::assertCount(1, $keys);
        self::assertStringNotContainsString($state->raw, $keys[0]);
        self::assertStringNotContainsString($state->raw, (string)$this->redis->get($keys[0]));
        self::assertSame($state->subjectId, $store->consume(
            $state->raw,
            OAuthAudience::PORTAL
        )->subjectId);

        try {
            $store->consume($state->raw, OAuthAudience::PORTAL);
            self::fail('OAuth State 只能消费一次');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::OAUTH_STATE_INVALID, $exception->errorCode);
        }
    }

    public function testAudienceMismatchInvalidatesState(): void
    {
        $store = $this->store();
        $state = $this->state('audience-bound-state-12345678901234567890');
        $store->save($state);

        foreach ([OAuthAudience::OPS, OAuthAudience::PORTAL] as $audience) {
            try {
                $store->consume($state->raw, $audience);
                self::fail('错误 audience 或重复消费必须拒绝');
            } catch (CloudException $exception) {
                self::assertSame(ErrorCode::OAUTH_STATE_INVALID, $exception->errorCode);
            }
        }
    }

    private function store(): RedisOAuthStateStore
    {
        return new RedisOAuthStateStore(
            $this->redis,
            $this->clock,
            str_repeat('s', 32),
            'cxpay-cloud-test:oauth:'
        );
    }

    private function state(string $raw): OAuthState
    {
        return new OAuthState(
            $raw,
            hash_hmac('sha256', $raw, str_repeat('s', 32)),
            IdentityProvider::QQ,
            OAuthAudience::PORTAL,
            OAuthPurpose::REGISTER_BIND,
            'user-1',
            '/registration/complete',
            $this->clock->now()->modify('+10 minutes')
        );
    }
}
