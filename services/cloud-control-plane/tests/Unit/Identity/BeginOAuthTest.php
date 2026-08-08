<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Application\BeginOAuth;
use CloudControl\Identity\Application\BeginOAuthCommand;
use CloudControl\Identity\Application\RegistrationChallenge;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Tests\Fakes\FakeOAuthProvider;
use CloudControl\Tests\Fakes\InMemoryOAuthStateStore;
use CloudControl\Tests\Fakes\InMemoryRegistrationChallengeStore;
use CloudControl\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BeginOAuthTest extends TestCase
{
    public function testRegistrationStateIsBoundToServerSelectedProviderAudienceAndUser(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $challenges = new InMemoryRegistrationChallengeStore();
        $states = new InMemoryOAuthStateStore();
        $provider = new FakeOAuthProvider(IdentityProvider::QQ);
        $challenge = new RegistrationChallenge(
            'registration-token',
            'user-1',
            'user@example.com',
            UserStatus::PENDING_IDENTITY,
            $clock->now()->modify('+15 minutes')
        );
        $challenges->save($challenge);

        $redirect = (new BeginOAuth(
            [$provider],
            $challenges,
            $states,
            $clock,
            str_repeat('s', 32)
        ))->handle(BeginOAuthCommand::registration(
            IdentityProvider::QQ,
            OAuthAudience::PORTAL,
            $challenge->token
        ));
        $state = $states->lastIssued();

        self::assertSame(IdentityProvider::QQ, $state->provider);
        self::assertSame(OAuthAudience::PORTAL, $state->audience);
        self::assertSame(OAuthPurpose::REGISTER_BIND, $state->purpose);
        self::assertSame('user-1', $state->subjectId);
        self::assertStringContainsString(rawurlencode($state->raw), $redirect->url);
        self::assertNotSame($state->raw, $state->digest);
    }

    public function testUnconfiguredProviderFailsBeforeAuthorizationUrl(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-09T00:00:00Z'));
        $challenges = new InMemoryRegistrationChallengeStore();
        $challenges->save(new RegistrationChallenge(
            'registration-token',
            'user-1',
            'user@example.com',
            UserStatus::PENDING_IDENTITY,
            $clock->now()->modify('+15 minutes')
        ));

        try {
            (new BeginOAuth(
                [new FakeOAuthProvider(IdentityProvider::WECHAT, false)],
                $challenges,
                new InMemoryOAuthStateStore(),
                $clock,
                str_repeat('s', 32)
            ))->handle(BeginOAuthCommand::registration(
                IdentityProvider::WECHAT,
                OAuthAudience::PORTAL,
                'registration-token'
            ));
            self::fail('未配置提供商必须拒绝');
        } catch (CloudException $exception) {
            self::assertSame(ErrorCode::OAUTH_NOT_CONFIGURED, $exception->errorCode);
        }
    }
}
