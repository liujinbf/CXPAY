<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Identity\Application\CompleteOAuth;
use CloudControl\Identity\Application\CompleteOAuthCommand;
use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Infrastructure\PdoExternalIdentityRepository;
use CloudControl\Identity\Infrastructure\PdoUserRepository;
use CloudControl\Shared\Database\MigrationRunner;
use CloudControl\Shared\Database\PdoTransactionManager;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tenant\Infrastructure\PdoCustomerTenantProvisioner;
use CloudControl\Tests\Fakes\FakeOAuthProvider;
use CloudControl\Tests\Fakes\InMemoryOAuthStateStore;
use CloudControl\Tests\Fakes\InMemoryRegistrationChallengeStore;
use CloudControl\Tests\Fakes\SequentialIdGenerator;
use CloudControl\Tests\Support\FrozenClock;
use CloudControl\Tests\Support\MySqlTestCase;
use DateTimeImmutable;

final class IdentityActivationTransactionTest extends MySqlTestCase
{
    private FrozenClock $clock;
    private SequentialIdGenerator $ids;
    private PdoUserRepository $users;
    private PdoExternalIdentityRepository $identities;
    private PdoCustomerTenantProvisioner $tenants;
    private FakeOAuthProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        (new MigrationRunner($this->pdo()))->migrate(dirname(__DIR__, 2) . '/migrations');
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $this->ids = new SequentialIdGenerator();
        $this->users = new PdoUserRepository($this->pdo());
        $this->identities = new PdoExternalIdentityRepository($this->pdo(), $this->ids);
        $this->tenants = new PdoCustomerTenantProvisioner($this->pdo(), $this->ids);
        $this->provider = new FakeOAuthProvider(IdentityProvider::QQ);
        $this->provider->willReturn(new ExternalIdentity(
            IdentityProvider::QQ,
            'qq-app',
            'openid-1',
            'QQ用户',
            null
        ));
    }

    public function testRegistrationBindingCreatesIdentityCustomerTenantAndOwnerAtomically(): void
    {
        $user = $this->pendingIdentity('first@example.com');

        $completion = $this->complete($user->id(), 'state-1');

        self::assertSame('ACTIVE', $this->users->findById($user->id())?->status()->value);
        self::assertSame($user->id(), $this->pdo()->query(
            'SELECT user_id FROM cloud_user_identities LIMIT 1'
        )->fetchColumn());
        self::assertSame('CUSTOMER', $this->pdo()->query(
            'SELECT type FROM cloud_tenants WHERE id = ' . $this->pdo()->quote($completion->tenantId)
        )->fetchColumn());
        self::assertSame('OWNER', $this->pdo()->query(
            'SELECT role FROM cloud_tenant_members WHERE tenant_id = ' . $this->pdo()->quote($completion->tenantId)
        )->fetchColumn());
    }

    public function testDuplicateExternalIdentityRollsBackSecondActivation(): void
    {
        $first = $this->pendingIdentity('first@example.com');
        $this->complete($first->id(), 'state-1');
        $second = $this->pendingIdentity('second@example.com');

        try {
            $this->complete($second->id(), 'state-2');
            self::fail('同一第三方身份不能绑定两个用户');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::IDENTITY_ALREADY_BOUND, $exception->errorCode);
        }

        self::assertSame('PENDING_IDENTITY', $this->users->findById($second->id())?->status()->value);
        $tenantCount = $this->pdo()->query(
            'SELECT COUNT(*) FROM cloud_tenant_members WHERE user_id = ' . $this->pdo()->quote($second->id())
        )->fetchColumn();
        self::assertSame(0, (int)$tenantCount);
    }

    private function pendingIdentity(string $email): User
    {
        $user = User::pendingEmail($this->ids->new(), EmailAddress::fromString($email), $this->clock->now());
        $user->completeEmailRegistration('客户用户', 'password-hash', $this->clock->now());
        (new PdoTransactionManager($this->pdo()))->run(function () use ($user): void {
            $this->users->findOrCreatePending($user);
            $this->users->save($user);
        });
        return $user;
    }

    private function complete(string $userId, string $rawState)
    {
        $states = new InMemoryOAuthStateStore();
        $states->save(new OAuthState(
            $rawState,
            hash('sha256', $rawState),
            IdentityProvider::QQ,
            OAuthAudience::PORTAL,
            OAuthPurpose::REGISTER_BIND,
            $userId,
            '/registration/complete',
            $this->clock->now()->modify('+10 minutes')
        ));

        return (new CompleteOAuth(
            [$this->provider],
            $states,
            $this->users,
            $this->identities,
            $this->tenants,
            new InMemoryRegistrationChallengeStore(),
            new PdoTransactionManager($this->pdo()),
            $this->clock
        ))->handle(new CompleteOAuthCommand($rawState, 'code-1', OAuthAudience::PORTAL));
    }
}
