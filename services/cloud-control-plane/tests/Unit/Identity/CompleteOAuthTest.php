<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Application\BeginOAuth;
use CloudControl\Identity\Application\BeginOAuthCommand;
use CloudControl\Identity\Application\CompleteOAuth;
use CloudControl\Identity\Application\CompleteOAuthCommand;
use CloudControl\Identity\Application\RegistrationChallenge;
use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Port\RegistrationChallengeStore;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tests\Fakes\FakeOAuthProvider;
use CloudControl\Tests\Fakes\InMemoryExternalIdentityRepository;
use CloudControl\Tests\Fakes\InMemoryOAuthStateStore;
use CloudControl\Tests\Fakes\InMemoryRegistrationChallengeStore;
use CloudControl\Tests\Fakes\InMemoryTenantProvisioner;
use CloudControl\Tests\Fakes\InMemoryUserRepository;
use CloudControl\Tests\Fakes\PassthroughTransactionManager;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompleteOAuthTest extends TestCase
{
    public function testRegistrationBindingActivatesUserAndCreatesCustomerTenant(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $users = new InMemoryUserRepository();
        $user = User::pendingEmail('user-1', EmailAddress::fromString('user@example.com'), $clock->now());
        $user->completeEmailRegistration('客户用户', 'password-hash', $clock->now());
        $users->findOrCreatePending($user);
        $challenges = new InMemoryRegistrationChallengeStore();
        $challenge = new RegistrationChallenge(
            'registration-token',
            $user->id(),
            $user->emailCanonical(),
            UserStatus::PENDING_IDENTITY,
            $clock->now()->modify('+15 minutes')
        );
        $challenges->save($challenge);
        $states = new InMemoryOAuthStateStore();
        $provider = new FakeOAuthProvider(IdentityProvider::QQ);
        $provider->willReturn(new ExternalIdentity(
            IdentityProvider::QQ,
            'qq-app',
            'openid-1',
            'QQ用户',
            null
        ));
        (new BeginOAuth([$provider], $challenges, $states, $clock, str_repeat('s', 32)))
            ->handle(BeginOAuthCommand::registration(
                IdentityProvider::QQ,
                OAuthAudience::PORTAL,
                $challenge->token
            ));
        $state = $states->lastIssued();

        $completion = (new CompleteOAuth(
            [$provider],
            $states,
            $users,
            new InMemoryExternalIdentityRepository(),
            new InMemoryTenantProvisioner(),
            $challenges,
            new PassthroughTransactionManager(),
            $clock
        ))->handle(new CompleteOAuthCommand($state->raw, 'code-1', OAuthAudience::PORTAL));

        self::assertSame(UserStatus::ACTIVE, $users->get('user@example.com')->status());
        self::assertSame('tenant-user-1', $completion->tenantId);
        self::assertSame('user-1', $completion->userId);
    }

    public function testOAuthLoginNeverCreatesUnknownUser(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $provider = new FakeOAuthProvider(IdentityProvider::WECHAT);
        $provider->willReturn(new ExternalIdentity(
            IdentityProvider::WECHAT,
            'wx-app',
            'unionid-unknown',
            '微信用户',
            null
        ));
        $states = new InMemoryOAuthStateStore();
        $begin = new BeginOAuth(
            [$provider],
            new InMemoryRegistrationChallengeStore(),
            $states,
            $clock,
            str_repeat('s', 32)
        );
        $begin->handle(BeginOAuthCommand::login(IdentityProvider::WECHAT, OAuthAudience::PORTAL));
        $state = $states->lastIssued();

        try {
            (new CompleteOAuth(
                [$provider],
                $states,
                new InMemoryUserRepository(),
                new InMemoryExternalIdentityRepository(),
                new InMemoryTenantProvisioner(),
                new InMemoryRegistrationChallengeStore(),
                new PassthroughTransactionManager(),
                $clock
            ))->handle(new CompleteOAuthCommand($state->raw, 'code-2', OAuthAudience::PORTAL));
            self::fail('未知第三方身份不能自动注册');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::IDENTITY_NOT_BOUND, $exception->errorCode);
        }
    }

    public function testCommittedActivationIsReturnedEvenWhenChallengeCleanupFails(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $users = new InMemoryUserRepository();
        $user = User::pendingEmail('user-1', EmailAddress::fromString('user@example.com'), $clock->now());
        $user->completeEmailRegistration('客户用户', 'password-hash', $clock->now());
        $users->findOrCreatePending($user);
        $states = new InMemoryOAuthStateStore();
        $states->save(new \CloudControl\Identity\Domain\OAuthState(
            str_repeat('r', 32),
            str_repeat('d', 64),
            IdentityProvider::QQ,
            OAuthAudience::PORTAL,
            \CloudControl\Identity\Domain\OAuthPurpose::REGISTER_BIND,
            $user->id(),
            '/registration/complete',
            $clock->now()->modify('+10 minutes')
        ));
        $provider = new FakeOAuthProvider(IdentityProvider::QQ);
        $provider->willReturn(new ExternalIdentity(
            IdentityProvider::QQ,
            'qq-app',
            'openid-cleanup',
            'QQ用户',
            null
        ));
        $cleanupFails = new class implements RegistrationChallengeStore {
            public function save(RegistrationChallenge $challenge): void {}
            public function find(string $rawToken): ?RegistrationChallenge { return null; }
            public function delete(string $rawToken): void {}
            public function deleteForUser(string $userId): void
            {
                throw new RuntimeException('Redis 清理失败');
            }
        };

        $completion = (new CompleteOAuth(
            [$provider],
            $states,
            $users,
            new InMemoryExternalIdentityRepository(),
            new InMemoryTenantProvisioner(),
            $cleanupFails,
            new PassthroughTransactionManager(),
            $clock
        ))->handle(new CompleteOAuthCommand(
            str_repeat('r', 32),
            'code-1',
            OAuthAudience::PORTAL
        ));

        self::assertSame('user-1', $completion->userId);
        self::assertSame(UserStatus::ACTIVE, $users->get('user@example.com')->status());
    }
}
