<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Application\RegistrationChallenge;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Port\RegistrationChallengeStore;
use CloudControl\Shared\Clock\Clock;
use DateTimeImmutable;
use Predis\ClientInterface;

final readonly class RedisRegistrationChallengeStore implements RegistrationChallengeStore
{
    public function __construct(
        private ClientInterface $redis,
        private Clock $clock,
        private string $hmacKey,
        private string $prefix = 'cxpay-cloud:registration:'
    ) {
        if (strlen($hmacKey) !== 32) {
            throw new \InvalidArgumentException('注册挑战摘要密钥必须为 32 字节');
        }
    }

    public function save(RegistrationChallenge $challenge): void
    {
        $ttl = $challenge->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('不能保存已过期的注册挑战');
        }

        $payload = json_encode([
            'user_id' => $challenge->userId,
            'email_canonical' => $challenge->emailCanonical,
            'status' => $challenge->status->value,
            'expires_at' => $challenge->expiresAt->format(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->redis->setex($this->key($challenge->token), $ttl, $payload);
        $userKey = $this->userKey($challenge->userId);
        $this->redis->sadd($userKey, [$this->key($challenge->token)]);
        $this->redis->expire($userKey, $ttl);
    }

    public function find(string $rawToken): ?RegistrationChallenge
    {
        $payload = $this->redis->get($this->key($rawToken));
        if ($payload === null) {
            return null;
        }
        $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        $expiresAt = new DateTimeImmutable((string)$data['expires_at']);
        if ($expiresAt <= $this->clock->now()) {
            $this->delete($rawToken);
            return null;
        }

        return new RegistrationChallenge(
            $rawToken,
            (string)$data['user_id'],
            (string)$data['email_canonical'],
            UserStatus::from((string)$data['status']),
            $expiresAt
        );
    }

    public function delete(string $rawToken): void
    {
        $this->redis->del([$this->key($rawToken)]);
    }

    public function deleteForUser(string $userId): void
    {
        $userKey = $this->userKey($userId);
        $keys = $this->redis->smembers($userKey);
        if ($keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->del([$userKey]);
    }

    private function key(string $rawToken): string
    {
        return $this->prefix . hash_hmac('sha256', $rawToken, $this->hmacKey);
    }

    private function userKey(string $userId): string
    {
        return $this->prefix . 'user:' . hash_hmac('sha256', $userId, $this->hmacKey);
    }
}
