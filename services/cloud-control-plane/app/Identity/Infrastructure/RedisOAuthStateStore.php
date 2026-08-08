<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Port\OAuthStateStore;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use DateTimeImmutable;
use Predis\ClientInterface;

final readonly class RedisOAuthStateStore implements OAuthStateStore
{
    private const CONSUME_SCRIPT = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if value then
    redis.call('DEL', KEYS[1])
end
return value
LUA;

    public function __construct(
        private ClientInterface $redis,
        private Clock $clock,
        private string $hmacKey,
        private string $prefix = 'cxpay-cloud:oauth:'
    ) {
        if (strlen($hmacKey) !== 32) {
            throw new \InvalidArgumentException('OAuth State 摘要密钥必须为 32 字节');
        }
    }

    public function save(OAuthState $state): void
    {
        $expectedDigest = $this->digest($state->raw);
        if (strlen($state->raw) < 32 || !hash_equals($expectedDigest, $state->digest)) {
            throw new \InvalidArgumentException('OAuth State 随机值或摘要无效');
        }
        $ttl = $state->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('不能保存已过期的 OAuth State');
        }
        $payload = json_encode([
            'provider' => $state->provider->value,
            'audience' => $state->audience->value,
            'purpose' => $state->purpose->value,
            'subject_id' => $state->subjectId,
            'redirect_path' => $state->redirectPath,
            'expires_at' => $state->expiresAt->format(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->redis->setex($this->prefix . $expectedDigest, $ttl, $payload);
    }

    public function consume(string $rawState, OAuthAudience $expectedAudience): OAuthState
    {
        $digest = $this->digest($rawState);
        $payload = $this->redis->eval(
            self::CONSUME_SCRIPT,
            1,
            $this->prefix . $digest
        );
        if (!is_string($payload) || $payload === '') {
            throw self::invalid();
        }
        try {
            $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
            $expiresAt = new DateTimeImmutable((string)$data['expires_at']);
            $audience = OAuthAudience::from((string)$data['audience']);
            if ($audience !== $expectedAudience || $expiresAt <= $this->clock->now()) {
                throw self::invalid();
            }

            return new OAuthState(
                $rawState,
                $digest,
                IdentityProvider::from((string)$data['provider']),
                $audience,
                OAuthPurpose::from((string)$data['purpose']),
                $data['subject_id'] === null ? null : (string)$data['subject_id'],
                (string)$data['redirect_path'],
                $expiresAt
            );
        } catch (CloudException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CloudException(
                ErrorCode::OAUTH_STATE_INVALID,
                'OAuth State 无效',
                422,
                false,
                [],
                $exception
            );
        }
    }

    private function digest(string $rawState): string
    {
        return hash_hmac('sha256', $rawState, $this->hmacKey);
    }

    private static function invalid(): CloudException
    {
        return new CloudException(ErrorCode::OAUTH_STATE_INVALID, 'OAuth State 无效', 422);
    }
}
