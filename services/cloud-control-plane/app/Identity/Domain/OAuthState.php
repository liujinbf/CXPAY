<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

use DateTimeImmutable;

final readonly class OAuthState
{
    public function __construct(
        public string $raw,
        public string $digest,
        public IdentityProvider $provider,
        public OAuthAudience $audience,
        public OAuthPurpose $purpose,
        public ?string $subjectId,
        public string $redirectPath,
        public DateTimeImmutable $expiresAt
    ) {
    }
}
