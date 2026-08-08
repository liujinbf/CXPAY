<?php

declare(strict_types=1);

namespace CloudControl\Tests\Integration;

use CloudControl\Identity\Application\BeginOAuth;
use CloudControl\Identity\Application\BeginOAuthCommand;
use CloudControl\Identity\Application\BeginTotpSetup;
use CloudControl\Identity\Application\CompleteEmailRegistration;
use CloudControl\Identity\Application\CompleteEmailRegistrationCommand;
use CloudControl\Identity\Application\CompleteOAuth;
use CloudControl\Identity\Application\CompleteOAuthCommand;
use CloudControl\Identity\Application\ConfirmTotpSetup;
use CloudControl\Identity\Application\RequestEmailCode;
use CloudControl\Identity\Application\RequestEmailCodeCommand;
use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\PasswordPolicy;
use CloudControl\Identity\Domain\Totp;
use CloudControl\Identity\Infrastructure\PdoEmailVerificationRepository;
use CloudControl\Identity\Infrastructure\PdoExternalIdentityRepository;
use CloudControl\Identity\Infrastructure\PdoUserRepository;
use CloudControl\Identity\Infrastructure\RedisOAuthStateStore;
use CloudControl\Identity\Infrastructure\RedisRateLimiter;
use CloudControl\Identity\Infrastructure\RedisRegistrationChallengeStore;
use CloudControl\Identity\Infrastructure\RedisTotpSetupStore;
use CloudControl\Shared\Database\MigrationRunner;
use CloudControl\Shared\Database\PdoTransactionManager;
use CloudControl\Shared\Security\Base32;
use CloudControl\Shared\Security\SodiumSecretCipher;
use CloudControl\Tenant\Infrastructure\PdoCustomerTenantProvisioner;
use CloudControl\Tests\Fakes\FakeEmailSender;
use CloudControl\Tests\Fakes\FakeOAuthProvider;
use CloudControl\Tests\Fakes\SequentialIdGenerator;
use CloudControl\Tests\Support\FrozenClock;
use CloudControl\Tests\Support\MySqlTestCase;
use DateTimeImmutable;
use Predis\Client;

final class M1aIdentityLifecycleTest extends MySqlTestCase
{
    public function testVerifiedEmailOauthAndTotpProduceActiveCustomerIdentity(): void
    {
        if ((string)getenv('CLOUD_TEST_REDIS_HOST') === '') {
            self::markTestSkipped('未配置 Redis 测试环境');
        }
        (new MigrationRunner($this->pdo()))->migrate(dirname(__DIR__, 2) . '/migrations');
        $redis = new Client([
            'scheme' => 'tcp',
            'host' => (string)getenv('CLOUD_TEST_REDIS_HOST'),
            'port' => (int)getenv('CLOUD_TEST_REDIS_PORT'),
        ]);
        $redis->flushdb();
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $ids = new SequentialIdGenerator();
        $users = new PdoUserRepository($this->pdo());
        $verifications = new PdoEmailVerificationRepository($this->pdo());
        $transactions = new PdoTransactionManager($this->pdo());
        $sender = FakeEmailSender::successful();
        $pepper = str_repeat('p', 32);
        (new RequestEmailCode(
            $users,
            $verifications,
            $sender,
            new RedisRateLimiter($redis, 'cxpay-cloud-test:lifecycle-rate:'),
            $transactions,
            $clock,
            $ids,
            $pepper
        ))->handle(new RequestEmailCodeCommand('User@Example.com', '127.0.0.1'));
        $registrationStore = new RedisRegistrationChallengeStore(
            $redis,
            $clock,
            str_repeat('h', 32),
            'cxpay-cloud-test:lifecycle-registration:'
        );
        $challenge = (new CompleteEmailRegistration(
            $users,
            $verifications,
            $registrationStore,
            $transactions,
            $clock,
            $ids,
            new PasswordPolicy(),
            $pepper
        ))->handle(new CompleteEmailRegistrationCommand(
            'user@example.com',
            $sender->lastCodeFor('user@example.com'),
            '客户用户',
            'Correct-Horse-2026!',
            '127.0.0.1'
        ));

        $oauth = new FakeOAuthProvider(IdentityProvider::QQ);
        $oauth->willReturn(new ExternalIdentity(
            IdentityProvider::QQ,
            'qq-app',
            'openid-lifecycle',
            'QQ用户',
            null
        ));
        $states = new RedisOAuthStateStore(
            $redis,
            $clock,
            str_repeat('s', 32),
            'cxpay-cloud-test:lifecycle-oauth:'
        );
        $redirect = (new BeginOAuth(
            [$oauth],
            $registrationStore,
            $states,
            $clock,
            str_repeat('s', 32)
        ))->handle(BeginOAuthCommand::registration(
            IdentityProvider::QQ,
            OAuthAudience::PORTAL,
            $challenge->token
        ));
        parse_str((string)parse_url($redirect->url, PHP_URL_QUERY), $oauthQuery);
        $completion = (new CompleteOAuth(
            [$oauth],
            $states,
            $users,
            new PdoExternalIdentityRepository($this->pdo(), $ids),
            new PdoCustomerTenantProvisioner($this->pdo(), $ids),
            $registrationStore,
            $transactions,
            $clock
        ))->handle(new CompleteOAuthCommand(
            (string)$oauthQuery['state'],
            'code-lifecycle',
            OAuthAudience::PORTAL
        ));

        self::assertSame('ACTIVE', $users->findById($completion->userId)?->status()->value);
        self::assertSame('CUSTOMER', $this->pdo()->query(
            'SELECT type FROM cloud_tenants WHERE id = ' . $this->pdo()->quote((string)$completion->tenantId)
        )->fetchColumn());
        self::assertSame('OWNER', $this->pdo()->query(
            'SELECT role FROM cloud_tenant_members WHERE tenant_id = '
            . $this->pdo()->quote((string)$completion->tenantId)
        )->fetchColumn());
        self::assertSame(1, (int)$this->pdo()->query(
            'SELECT COUNT(*) FROM cloud_user_identities WHERE user_id = '
            . $this->pdo()->quote($completion->userId)
        )->fetchColumn());

        $setups = new RedisTotpSetupStore(
            $redis,
            $clock,
            'cxpay-cloud-test:lifecycle-totp-setup:'
        );
        (new BeginTotpSetup($users, $setups, $clock))->handle(
            $completion->userId,
            'CXPAY Cloud',
            'user@example.com'
        );
        $pending = $setups->find($completion->userId);
        self::assertNotNull($pending);
        $totp = new Totp();
        $code = $totp->at(Base32::decode($pending->secretBase32), $clock->now()->getTimestamp());
        (new ConfirmTotpSetup(
            $users,
            $setups,
            $totp,
            new SodiumSecretCipher(str_repeat('k', 32)),
            $transactions,
            $clock
        ))->handle($completion->userId, $code);

        self::assertTrue($users->findById($completion->userId)?->totpEnabled());
        self::assertFalse($completion->totpRequired);
    }
}
