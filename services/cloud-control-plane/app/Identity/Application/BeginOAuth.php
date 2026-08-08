<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Port\OAuthProvider;
use CloudControl\Identity\Port\OAuthStateStore;
use CloudControl\Identity\Port\RegistrationChallengeStore;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;

final class BeginOAuth
{
    /** @var array<string, OAuthProvider> */
    private array $providers = [];

    /** @param iterable<OAuthProvider> $providers */
    public function __construct(
        iterable $providers,
        private readonly RegistrationChallengeStore $registrationChallenges,
        private readonly OAuthStateStore $states,
        private readonly Clock $clock,
        private readonly string $stateHmacKey
    ) {
        if (strlen($stateHmacKey) !== 32) {
            throw new \InvalidArgumentException('OAuth State 摘要密钥必须为 32 字节');
        }
        foreach ($providers as $provider) {
            $this->providers[$provider->provider()->value] = $provider;
        }
    }

    public function handle(BeginOAuthCommand $command): OAuthRedirect
    {
        $provider = $this->provider($command->provider);
        if (!$provider->isConfigured($command->audience)) {
            throw new CloudException(
                ErrorCode::OAUTH_NOT_CONFIGURED,
                '该 OAuth 通道尚未配置',
                503
            );
        }

        $subjectId = null;
        if ($command->purpose === OAuthPurpose::REGISTER_BIND) {
            $challenge = $this->registrationChallenges->find((string)$command->registrationToken);
            if ($challenge === null || $challenge->status !== UserStatus::PENDING_IDENTITY) {
                throw new CloudException(
                    ErrorCode::REGISTRATION_INCOMPLETE,
                    '注册挑战无效或已过期',
                    422
                );
            }
            $subjectId = $challenge->userId;
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $this->clock->now()->modify('+10 minutes');
        $state = new OAuthState(
            $raw,
            hash_hmac('sha256', $raw, $this->stateHmacKey),
            $command->provider,
            $command->audience,
            $command->purpose,
            $subjectId,
            $command->purpose === OAuthPurpose::REGISTER_BIND
                ? '/registration/complete'
                : '/login/complete',
            $expiresAt
        );
        $this->states->save($state);

        return new OAuthRedirect($provider->authorizationUrl($state), $expiresAt);
    }

    private function provider(IdentityProvider $provider): OAuthProvider
    {
        return $this->providers[$provider->value] ?? throw new CloudException(
            ErrorCode::OAUTH_NOT_CONFIGURED,
            '该 OAuth 通道尚未配置',
            503
        );
    }
}
