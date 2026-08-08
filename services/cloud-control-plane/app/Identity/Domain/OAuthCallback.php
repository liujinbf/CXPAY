<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

final readonly class OAuthCallback
{
    public function __construct(
        public string $code,
        public OAuthAudience $audience
    ) {
    }
}
