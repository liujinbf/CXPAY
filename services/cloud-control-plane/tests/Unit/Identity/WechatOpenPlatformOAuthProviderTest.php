<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Identity;

use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthCallback;
use CloudControl\Identity\Domain\OAuthPurpose;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Infrastructure\WechatOpenPlatformOAuthProvider;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class WechatOpenPlatformOAuthProviderTest extends TestCase
{
    public function testPrefersUnionIdFromOfficialWebsiteOAuthResponse(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'wx-token',
                'expires_in' => 7200,
                'openid' => 'openid-1',
                'scope' => 'snsapi_login',
                'unionid' => 'unionid-1',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'openid' => 'openid-1',
                'nickname' => '微信用户',
                'headimgurl' => 'https://wx.qlogo.cn/avatar.jpg',
                'unionid' => 'unionid-1',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $provider = new WechatOpenPlatformOAuthProvider(
            new Client(['handler' => $stack]),
            'wx-app',
            'wx-secret',
            [OAuthAudience::PORTAL->value => 'https://cloud.example/oauth/wechat/callback']
        );

        $identity = $provider->exchangeCallback(new OAuthCallback('code-1', OAuthAudience::PORTAL));

        self::assertSame(IdentityProvider::WECHAT, $identity->provider);
        self::assertSame('wechat-open-platform', $identity->issuer);
        self::assertSame('unionid-1', $identity->subject);
        self::assertSame('微信用户', $identity->displayName);
        self::assertCount(2, $history);
        self::assertSame('api.weixin.qq.com', $history[0]['request']->getUri()->getHost());
        self::assertSame('/sns/oauth2/access_token', $history[0]['request']->getUri()->getPath());
        self::assertSame('/sns/userinfo', $history[1]['request']->getUri()->getPath());
    }

    public function testFallsBackToAppIdAndOpenIdWhenUnionIdIsAbsent(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"access_token":"wx-token","openid":"openid-2"}'),
            new Response(200, [], '{"openid":"openid-2","nickname":"微信用户"}'),
        ]);
        $provider = new WechatOpenPlatformOAuthProvider(
            new Client(['handler' => HandlerStack::create($mock)]),
            'wx-app',
            'wx-secret',
            [OAuthAudience::PORTAL->value => 'https://cloud.example/oauth/wechat/callback']
        );

        $identity = $provider->exchangeCallback(new OAuthCallback('code-2', OAuthAudience::PORTAL));

        self::assertSame('wx-app', $identity->issuer);
        self::assertSame('openid-2', $identity->subject);
    }

    public function testAuthorizationUrlUsesQrConnectAndWechatRedirectFragment(): void
    {
        $provider = new WechatOpenPlatformOAuthProvider(
            new Client(),
            'wx-app',
            'wx-secret',
            [OAuthAudience::PORTAL->value => 'https://cloud.example/oauth/wechat/callback']
        );
        $state = new OAuthState(
            str_repeat('r', 32),
            str_repeat('d', 64),
            IdentityProvider::WECHAT,
            OAuthAudience::PORTAL,
            OAuthPurpose::LOGIN,
            null,
            '/login/complete',
            new DateTimeImmutable('2026-08-09T00:10:00Z')
        );

        $url = $provider->authorizationUrl($state);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://open.weixin.qq.com/connect/qrconnect', strtok($url, '?'));
        self::assertSame('wx-app', $query['appid']);
        self::assertSame('snsapi_login', $query['scope']);
        self::assertSame($state->raw, $query['state']);
        self::assertStringEndsWith('#wechat_redirect', $url);
    }
}
