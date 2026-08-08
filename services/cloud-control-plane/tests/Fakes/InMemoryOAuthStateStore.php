<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Port\OAuthStateStore;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;

final class InMemoryOAuthStateStore implements OAuthStateStore
{
    /** @var array<string, OAuthState> */
    private array $states = [];

    public function save(OAuthState $state): void
    {
        $this->states[$state->raw] = $state;
    }

    public function consume(string $rawState, OAuthAudience $expectedAudience): OAuthState
    {
        $state = $this->states[$rawState] ?? null;
        unset($this->states[$rawState]);
        if ($state === null || $state->audience !== $expectedAudience) {
            throw new CloudException(ErrorCode::OAUTH_STATE_INVALID, 'OAuth State 无效', 422);
        }
        return $state;
    }

    public function lastIssued(): OAuthState
    {
        return array_values($this->states)[array_key_last(array_values($this->states))];
    }
}
