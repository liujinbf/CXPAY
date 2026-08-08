<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Port\ExternalIdentityRepository;
use DateTimeImmutable;

final class InMemoryExternalIdentityRepository implements ExternalIdentityRepository
{
    /** @var array<string, string> */
    private array $bindings = [];

    public function findUserId(ExternalIdentity $identity): ?string
    {
        return $this->bindings[$identity->key()] ?? null;
    }

    public function bind(string $userId, ExternalIdentity $identity, DateTimeImmutable $now): void
    {
        if (isset($this->bindings[$identity->key()])) {
            throw new \CloudControl\Shared\Error\CloudException(
                \CloudControl\Shared\Error\ErrorCode::IDENTITY_ALREADY_BOUND,
                '第三方身份已被绑定',
                409
            );
        }
        $this->bindings[$identity->key()] = $userId;
    }
}
