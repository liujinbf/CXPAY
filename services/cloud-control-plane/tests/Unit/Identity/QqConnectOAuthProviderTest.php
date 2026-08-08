<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthCallback;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Infrastructure\QqConnectOAuthProvider;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class QqConnectOAuthProviderTest extends TestCase
{
    public function testUsesOfficialEndpointsAndReturnsOpenIdIdentity(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], 'access_token=token-1&expires_in=7776000'),
            new Response(200, [], 'callback( {"client_id":"qq-app","openid":"openid-1"} );'),
            new Response(200, [], json_encode([
                'ret' => 0,
                'nickname' => 'QQ用户',
                'figureurl_qq_2' => 'https://q.qlogo.cn/avatar.jpg',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $provider = new QqConnectOAuthProvider(
            new Client(['handler' => $stack]),
            'qq-app',
            'qq-secret',
            [OAuthAudience::PORTAL->value => 'https://cloud.example/oauth/qq/callback']
        );

        $identity = $provider->exchangeCallback(new OAuthCallback('code-1', OAuthAudience::PORTAL));

        self::assertSame(IdentityProvider::QQ, $identity->provider);
        self::assertSame('qq-app', $identity->issuer);
        self::assertSame('openid-1', $identity->subject);
        self::assertSame('QQ用户', $identity->displayName);
        self::assertCount(3, $history);
        self::assertSame('graph.qq.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('/oauth2.0/token', $history[0]['request']->getUri()->getPath());
        self::assertStringContainsString('code=code-1', $history[0]['request']->getUri()->getQuery());
        self::assertSame('/oauth2.0/me', $history[1]['request']->getUri()->getPath());
        self::assertSame('/user/get_user_info', $history[2]['request']->getUri()->getPath());
    }

    public function testAuthorizationUrlUsesStateAndAudienceSpecificRedirect(): void
    {
        $provider = new QqConnectOAuthProvider(
            new Client(),
            'qq-app',
            'qq-secret',
            [OAuthAudience::PORTAL->value => 'https://cloud.example/oauth/qq/callback']
        );
        $state = new OAuthState(
            str_repeat('r', 32),
            str_repeat('d', 64),
            IdentityProvider::QQ,
            OAuthAudience::PORTAL,
            OAuthPurpose::LOGIN,
            null,
            '/login/complete',
            new DateTimeImmutable('2026-08-09T00:10:00Z')
        );

        $url = $provider->authorizationUrl($state);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://graph.qq.com/oauth2.0/authorize', strtok($url, '?'));
        self::assertSame('qq-app', $query['client_id']);
        self::assertSame($state->raw, $query['state']);
        self::assertSame('https://cloud.example/oauth/qq/callback', $query['redirect_uri']);
        self::assertTrue($provider->isConfigured(OAuthAudience::PORTAL));
        self::assertFalse($provider->isConfigured(OAuthAudience::OPS));
    }

    public function testAcceptsOfficialJsonTokenResponseWhenFmtIsJson(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"access_token":"json-token","expires_in":7776000}'),
            new Response(200, [], 'callback( {"client_id":"qq-app","openid":"openid-json"} );'),
            new Response(200, [], '{"ret":0,"nickname":"JSON用户"}'),
        ]);
        $provider = new QqConnectOAuthProvider(
            new Client(['handler' => HandlerStack::create($mock)]),
            'qq-app',
            'qq-secret',
            [OAuthAudience::PORTAL->value => 'https://cloud.example/oauth/qq/callback']
        );

        $identity = $provider->exchangeCallback(new OAuthCallback('code-json', OAuthAudience::PORTAL));

        self::assertSame('openid-json', $identity->subject);
        self::assertSame('JSON用户', $identity->displayName);
    }
}
