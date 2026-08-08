<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Port\UserRepository;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $usersByEmail = [];

    public function findOrCreatePending(User $candidate): User
    {
        return $this->usersByEmail[$candidate->emailCanonical()] ??= $candidate;
    }

    public function findByEmailCanonicalForUpdate(string $emailCanonical): ?User
    {
        return $this->usersByEmail[$emailCanonical] ?? null;
    }

    public function save(User $user): void
    {
        $this->usersByEmail[$user->emailCanonical()] = $user;
    }

    public function get(string $emailCanonical): User
    {
        return $this->usersByEmail[$emailCanonical];
    }
}
