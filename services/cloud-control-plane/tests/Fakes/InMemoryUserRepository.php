<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Port\UserRepository;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $usersByEmail = [];
    /** @var array<string, User> */
    private array $usersById = [];

    public function findOrCreatePending(User $candidate): User
    {
        $user = $this->usersByEmail[$candidate->emailCanonical()] ??= $candidate;
        $this->usersById[$user->id()] = $user;
        return $user;
    }

    public function findByEmailCanonicalForUpdate(string $emailCanonical): ?User
    {
        return $this->usersByEmail[$emailCanonical] ?? null;
    }

    public function save(User $user): void
    {
        $this->usersByEmail[$user->emailCanonical()] = $user;
        $this->usersById[$user->id()] = $user;
    }

    public function findById(string $id): ?User { return $this->usersById[$id] ?? null; }
    public function findByIdForUpdate(string $id): ?User { return $this->findById($id); }

    public function get(string $emailCanonical): User
    {
        return $this->usersByEmail[$emailCanonical];
    }
}
