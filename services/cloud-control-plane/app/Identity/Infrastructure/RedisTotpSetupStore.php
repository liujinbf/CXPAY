<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\PendingTotpSetup;
use CloudControl\Identity\Port\TotpSetupStore;
use CloudControl\Shared\Clock\Clock;
use DateTimeImmutable;
use Predis\ClientInterface;

final readonly class RedisTotpSetupStore implements TotpSetupStore
{
    public function __construct(
        private ClientInterface $redis,
        private Clock $clock,
        private string $prefix = 'cxpay-cloud:totp-setup:'
    ) {
    }

    public function save(PendingTotpSetup $setup): void
    {
        $ttl = $setup->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('不能保存已过期的 TOTP 设置');
        }
        $payload = json_encode([
            'user_id' => $setup->userId,
            'secret_base32' => $setup->secretBase32,
            'expires_at' => $setup->expiresAt->format(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->redis->setex($this->key($setup->userId), $ttl, $payload);
    }

    public function find(string $userId): ?PendingTotpSetup
    {
        $payload = $this->redis->get($this->key($userId));
        if ($payload === null) {
            return null;
        }
        $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        $expiresAt = new DateTimeImmutable((string)$data['expires_at']);
        if ($expiresAt <= $this->clock->now()) {
            $this->delete($userId);
            return null;
        }
        return new PendingTotpSetup(
            (string)$data['user_id'],
            (string)$data['secret_base32'],
            $expiresAt
        );
    }

    public function delete(string $userId): void
    {
        $this->redis->del([$this->key($userId)]);
    }

    private function key(string $userId): string
    {
        return $this->prefix . hash('sha256', $userId);
    }
}
