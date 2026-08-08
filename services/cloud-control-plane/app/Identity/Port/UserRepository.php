<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\User;

interface UserRepository
{
    public function findOrCreatePending(User $candidate): User;
    public function findByEmailCanonicalForUpdate(string $emailCanonical): ?User;
    public function save(User $user): void;
}
