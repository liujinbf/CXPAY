<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Application\RegistrationChallenge;

interface RegistrationChallengeStore
{
    public function save(RegistrationChallenge $challenge): void;
    public function find(string $rawToken): ?RegistrationChallenge;
    public function delete(string $rawToken): void;
    public function deleteForUser(string $userId): void;
}
