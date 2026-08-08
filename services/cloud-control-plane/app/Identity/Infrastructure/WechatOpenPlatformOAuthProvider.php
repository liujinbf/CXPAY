<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Domain\IdentityProvider;
use CloudControl\Identity\Domain\OAuthAudience;
use CloudControl\Identity\Domain\OAuthCallback;
use CloudControl\Identity\Domain\OAuthState;
use CloudControl\Identity\Port\OAuthProvider;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use GuzzleHttp\ClientInterface;
use Throwable;

final readonly class WechatOpenPlatformOAuthProvider implements OAuthProvider
{
    private const AUTHORIZE_ENDPOINT = 'https://open.weixin.qq.com/connect/qrconnect';
    private const TOKEN_ENDPOINT = 'https://api.weixin.qq.com/sns/oauth2/access_token';
    private const USERINFO_ENDPOINT = 'https://api.weixin.qq.com/sns/userinfo';

    /** @param array<string, string> $redirectUris */
    public function __construct(
        private ClientInterface $http,
        private string $appId,
        private string $appSecret,
        private array $redirectUris
    ) {
    }

    public function provider(): IdentityProvider { return IdentityProvider::WECHAT; }

    public function isConfigured(OAuthAudience $audience): bool
    {
        return $this->appId !== ''
            && $this->appSecret !== ''
            && ($this->redirectUris[$audience->value] ?? '') !== '';
    }

    public function authorizationUrl(OAuthState $state): string
    {
        $this->assertConfigured($state->audience);
        return self::AUTHORIZE_ENDPOINT . '?' . http_build_query([
            'appid' => $this->appId,
            'redirect_uri' => $this->redirectUri($state->audience),
            'response_type' => 'code',
            'scope' => 'snsapi_login',
            'state' => $state->raw,
        ], '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
    }

    public function exchangeCallback(OAuthCallback $callback): ExternalIdentity
    {
        $this->assertConfigured($callback->audience);
        try {
            $token = $this->request(self::TOKEN_ENDPOINT, [
                'appid' => $this->appId,
                'secret' => $this->appSecret,
                'code' => $callback->code,
                'grant_type' => 'authorization_code',
            ]);
            $accessToken = (string)($token['access_token'] ?? '');
            $openid = (string)($token['openid'] ?? '');
            if ($accessToken === '' || $openid === '') {
                throw new \RuntimeException('微信 Access Token 响应无效');
            }
            $profile = $this->request(self::USERINFO_ENDPOINT, [
                'access_token' => $accessToken,
                'openid' => $openid,
                'lang' => 'zh_CN',
            ]);
            $unionId = (string)($profile['unionid'] ?? $token['unionid'] ?? '');
            $subject = $unionId !== '' ? $unionId : (string)($profile['openid'] ?? $openid);
            if ($subject === '') {
                throw new \RuntimeException('微信用户标识为空');
            }

            return new ExternalIdentity(
                IdentityProvider::WECHAT,
                $unionId !== '' ? 'wechat-open-platform' : $this->appId,
                $subject,
                (string)($profile['nickname'] ?? '微信用户'),
                self::nullableString($profile['headimgurl'] ?? null)
            );
        } catch (CloudException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CloudException(
                ErrorCode::CREDENTIALS_INVALID,
                '微信 OAuth 授权失败',
                422,
                false,
                [],
                $exception
            );
        }
    }

    /** @param array<string, scalar> $query
     *  @return array<string, mixed>
     */
    private function request(string $uri, array $query): array
    {
        $response = $this->http->request('GET', $uri, [
            'query' => $query,
            'connect_timeout' => 5,
            'timeout' => 10,
            'http_errors' => false,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('微信 OAuth HTTP 状态异常');
        }
        $data = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || isset($data['errcode'])) {
            throw new \RuntimeException('微信 OAuth 响应错误');
        }
        return $data;
    }

    private function assertConfigured(OAuthAudience $audience): void
    {
        if (!$this->isConfigured($audience)) {
            throw new CloudException(ErrorCode::OAUTH_NOT_CONFIGURED, '微信 OAuth 尚未配置', 503);
        }
    }

    private function redirectUri(OAuthAudience $audience): string
    {
        return (string)($this->redirectUris[$audience->value] ?? '');
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
