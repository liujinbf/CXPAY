<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthPurpose;

final readonly class BeginOAuthCommand
{
    private function __construct(
        public IdentityProvider $provider,
        public OAuthAudience $audience,
        public OAuthPurpose $purpose,
        public ?string $registrationToken
    ) {
    }

    public static function registration(
        IdentityProvider $provider,
        OAuthAudience $audience,
        string $registrationToken
    ): self {
        return new self($provider, $audience, OAuthPurpose::REGISTER_BIND, $registrationToken);
    }

    public static function login(IdentityProvider $provider, OAuthAudience $audience): self
    {
        return new self($provider, $audience, OAuthPurpose::LOGIN, null);
    }
}
