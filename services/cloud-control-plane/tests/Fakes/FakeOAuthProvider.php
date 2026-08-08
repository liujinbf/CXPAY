<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthCallback;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Port\OAuthProvider;

final class FakeOAuthProvider implements OAuthProvider
{
    private ?ExternalIdentity $identity = null;

    public function __construct(
        private readonly IdentityProvider $provider,
        private readonly bool $configured = true
    ) {
    }

    public function willReturn(ExternalIdentity $identity): void
    {
        $this->identity = $identity;
    }

    public function provider(): IdentityProvider { return $this->provider; }
    public function isConfigured(\CloudControl\Identity\Domain\OAuthAudience $audience): bool
    {
        return $this->configured;
    }

    public function authorizationUrl(OAuthState $state): string
    {
        return 'https://oauth.example/authorize?state=' . rawurlencode($state->raw);
    }

    public function exchangeCallback(OAuthCallback $callback): ExternalIdentity
    {
        if ($this->identity === null) {
            throw new \LogicException('测试未配置第三方身份');
        }
        return $this->identity;
    }
}
