<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthCallback;
use CloudControl\Identity\Domain\OAuthState;

interface OAuthProvider
{
    public function provider(): IdentityProvider;
    public function isConfigured(OAuthAudience $audience): bool;
    public function authorizationUrl(OAuthState $state): string;
    public function exchangeCallback(OAuthCallback $callback): ExternalIdentity;
}
