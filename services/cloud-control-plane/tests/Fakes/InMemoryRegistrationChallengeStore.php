<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Application\RegistrationChallenge;
use CloudControl\Identity\Port\RegistrationChallengeStore;

final class InMemoryRegistrationChallengeStore implements RegistrationChallengeStore
{
    /** @var array<string, RegistrationChallenge> */
    private array $challenges = [];

    public function save(RegistrationChallenge $challenge): void
    {
        $this->challenges[$challenge->token] = $challenge;
    }

    public function find(string $rawToken): ?RegistrationChallenge
    {
        return $this->challenges[$rawToken] ?? null;
    }

    public function delete(string $rawToken): void
    {
        unset($this->challenges[$rawToken]);
    }
}
