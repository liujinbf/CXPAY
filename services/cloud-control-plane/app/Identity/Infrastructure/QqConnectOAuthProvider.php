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
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class QqConnectOAuthProvider implements OAuthProvider
{
    private const AUTHORIZE_ENDPOINT = 'https://graph.qq.com/oauth2.0/authorize';
    private const TOKEN_ENDPOINT = 'https://graph.qq.com/oauth2.0/token';
    private const OPENID_ENDPOINT = 'https://graph.qq.com/oauth2.0/me';
    private const USERINFO_ENDPOINT = 'https://graph.qq.com/user/get_user_info';

    /** @param array<string, string> $redirectUris */
    public function __construct(
        private ClientInterface $http,
        private string $clientId,
        private string $clientSecret,
        private array $redirectUris
    ) {
    }

    public function provider(): IdentityProvider { return IdentityProvider::QQ; }

    public function isConfigured(OAuthAudience $audience): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && ($this->redirectUris[$audience->value] ?? '') !== '';
    }

    public function authorizationUrl(OAuthState $state): string
    {
        $this->assertConfigured($state->audience);
        return self::AUTHORIZE_ENDPOINT . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri($state->audience),
            'state' => $state->raw,
            'scope' => 'get_user_info',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCallback(OAuthCallback $callback): ExternalIdentity
    {
        $this->assertConfigured($callback->audience);
        try {
            $tokenBody = $this->body($this->request(self::TOKEN_ENDPOINT, [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $callback->code,
                'redirect_uri' => $this->redirectUri($callback->audience),
                'fmt' => 'json',
            ]));
            $token = $this->parseToken($tokenBody);
            $openid = $this->parseCallbackJson($this->body($this->request(
                self::OPENID_ENDPOINT,
                ['access_token' => $token]
            )));
            if (($openid['client_id'] ?? '') !== $this->clientId || ($openid['openid'] ?? '') === '') {
                throw new \RuntimeException('QQ OpenID 响应无效');
            }
            $profile = $this->json($this->body($this->request(self::USERINFO_ENDPOINT, [
                'access_token' => $token,
                'oauth_consumer_key' => $this->clientId,
                'openid' => (string)$openid['openid'],
                'format' => 'json',
            ])));
            if ((int)($profile['ret'] ?? -1) !== 0) {
                throw new \RuntimeException('QQ 用户资料响应错误');
            }

            return new ExternalIdentity(
                IdentityProvider::QQ,
                $this->clientId,
                (string)$openid['openid'],
                (string)($profile['nickname'] ?? 'QQ用户'),
                self::nullableString($profile['figureurl_qq_2'] ?? $profile['figureurl_qq_1'] ?? null)
            );
        } catch (CloudException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw self::providerFailure($exception);
        }
    }

    /** @param array<string, scalar> $query */
    private function request(string $uri, array $query): ResponseInterface
    {
        $response = $this->http->request('GET', $uri, [
            'query' => $query,
            'connect_timeout' => 5,
            'timeout' => 10,
            'http_errors' => false,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException('QQ OAuth HTTP 状态异常');
        }
        return $response;
    }

    private function parseToken(string $body): string
    {
        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, 'callback')) {
            $error = $this->parseCallbackJson($body);
            throw new \RuntimeException('QQ OAuth 错误 ' . (string)($error['error'] ?? 'unknown'));
        }
        if (str_starts_with($trimmed, '{')) {
            $data = $this->json($body);
            if (isset($data['error'])) {
                throw new \RuntimeException('QQ OAuth 错误 ' . (string)$data['error']);
            }
            $token = (string)($data['access_token'] ?? '');
            if ($token === '') {
                throw new \RuntimeException('QQ Access Token 响应无效');
            }
            return $token;
        }
        parse_str($body, $data);
        $token = (string)($data['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('QQ Access Token 响应无效');
        }
        return $token;
    }

    /** @return array<string, mixed> */
    private function parseCallbackJson(string $body): array
    {
        if (preg_match('/callback\s*\(\s*(\{.*\})\s*\)\s*;?/s', $body, $matches) !== 1) {
            throw new \RuntimeException('QQ callback 响应格式无效');
        }
        return $this->json($matches[1]);
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('QQ JSON 响应无效');
        }
        return $data;
    }

    private function assertConfigured(OAuthAudience $audience): void
    {
        if (!$this->isConfigured($audience)) {
            throw new CloudException(ErrorCode::OAUTH_NOT_CONFIGURED, 'QQ OAuth 尚未配置', 503);
        }
    }

    private function redirectUri(OAuthAudience $audience): string
    {
        return (string)($this->redirectUris[$audience->value] ?? '');
    }

    private function body(ResponseInterface $response): string
    {
        return (string)$response->getBody();
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function providerFailure(Throwable $previous): CloudException
    {
        return new CloudException(
            ErrorCode::CREDENTIALS_INVALID,
            'QQ OAuth 授权失败',
            422,
            false,
            [],
            $previous
        );
    }
}
