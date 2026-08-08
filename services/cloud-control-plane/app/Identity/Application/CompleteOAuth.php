<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthCallback;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Port\ExternalIdentityRepository;
use CloudControl\Identity\Port\OAuthProvider;
use CloudControl\Identity\Port\OAuthStateStore;
use CloudControl\Identity\Port\RegistrationChallengeStore;
use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Database\TransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tenant\Port\TenantProvisioner;

final class CompleteOAuth
{
    /** @var array<string, OAuthProvider> */
    private array $providers = [];

    /** @param iterable<OAuthProvider> $providers */
    public function __construct(
        iterable $providers,
        private readonly OAuthStateStore $states,
        private readonly UserRepository $users,
        private readonly ExternalIdentityRepository $identities,
        private readonly TenantProvisioner $tenants,
        private readonly RegistrationChallengeStore $registrationChallenges,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->provider()->value] = $provider;
        }
    }

    public function handle(CompleteOAuthCommand $command): IdentityCompletion
    {
        $state = $this->states->consume($command->rawState, $command->expectedAudience);
        $provider = $this->provider($state->provider);
        $identity = $provider->exchangeCallback(new OAuthCallback(
            $command->code,
            $command->expectedAudience
        ));
        if ($identity->provider !== $state->provider) {
            throw new CloudException(ErrorCode::OAUTH_STATE_INVALID, 'OAuth State 无效', 422);
        }

        if ($state->purpose === OAuthPurpose::LOGIN) {
            return $this->completeLogin($state->audience, $identity);
        }
        if ($state->purpose !== OAuthPurpose::REGISTER_BIND || $state->subjectId === null) {
            throw new CloudException(ErrorCode::OAUTH_STATE_INVALID, 'OAuth State 无效', 422);
        }

        $now = $this->clock->now();
        $completion = $this->transactions->run(function () use ($state, $identity, $now): IdentityCompletion {
            $user = $this->users->findByIdForUpdate((string)$state->subjectId);
            if ($user === null || $user->status() !== UserStatus::PENDING_IDENTITY) {
                throw new CloudException(
                    ErrorCode::REGISTRATION_INCOMPLETE,
                    '用户注册状态不完整',
                    409
                );
            }
            $this->identities->bind($user->id(), $identity, $now);
            $user->activate($now);
            $this->users->save($user);
            $tenantId = $this->tenants->provisionCustomer($user, $now);

            return new IdentityCompletion(
                $user->id(),
                $state->audience,
                $tenantId,
                $state->audience === OAuthAudience::OPS,
                $now
            );
        });
        try {
            $this->registrationChallenges->deleteForUser((string)$state->subjectId);
        } catch (\Throwable) {
            // 激活事务已经提交，短期挑战仍会因 TTL 和用户状态检查失效。
        }

        return $completion;
    }

    private function completeLogin($audience, $identity): IdentityCompletion
    {
        $userId = $this->identities->findUserId($identity);
        if ($userId === null) {
            throw new CloudException(
                ErrorCode::IDENTITY_NOT_BOUND,
                '该第三方账号尚未绑定',
                404
            );
        }
        $user = $this->users->findById($userId);
        $tenantId = $this->tenants->customerTenantIdForUser($userId);
        if ($user === null || $user->status() !== UserStatus::ACTIVE || $tenantId === null) {
            throw new CloudException(
                ErrorCode::REGISTRATION_INCOMPLETE,
                '账号或租户状态不完整',
                409
            );
        }

        return new IdentityCompletion(
            $userId,
            $audience,
            $tenantId,
            $audience === OAuthAudience::OPS,
            $this->clock->now()
        );
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
