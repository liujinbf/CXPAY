<?php

declare(strict_types=1);

namespace WxCollector;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * 支付宝网页版扫码适配器。
 *
 * 这是非官方网页协议，只用于用户本人明确授权的账号。TLS 校验始终开启，
 * 不伪造来源 IP，不把 Cookie 返回给 CXPAY 或云端协调服务。
 */
final class AlipayWebProviderAdapter implements ProviderAdapterInterface, AccountBindingAwareInterface
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36';

    private Client $http;

    public function __construct(
        private readonly EncryptedFileStateStore $store,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client([
            'timeout' => 10.0,
            'connect_timeout' => 5.0,
            'verify' => true,
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'zh-CN,zh;q=0.8'],
        ]);
    }

    public function startAuthorization(array $task): ?array
    {
        $sessionId = $this->sessionId($task);
        $response = $this->http->get('https://authsa128.alipay.com/login/index.htm', [
            'headers' => ['Referer' => 'https://authsa128.alipay.com/'],
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 400) {
            throw new RuntimeException('支付宝登录页暂时不可用');
        }
        $html = (string)$response->getBody();
        $securityId = $this->match($html, '/securityId:\s*"([^"]+)"/');
        $passwordSecurityId = $this->match($html, '/s\.sid\s*=\s*"([^"]+)"/');
        $rdsToken = $this->match($html, '/name="rds_form_token"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="rds_form_token"/');
        $alieditUid = $this->match($html, '/name="alieditUid"[^>]*value="([^"]*)"|id="alieditUid"[^>]*value="([^"]*)"/');
        if ($securityId === '') {
            throw new RuntimeException('支付宝登录页结构已变化，无法取得二维码参数');
        }
        $state = [
            'security_id' => $securityId,
            'password_security_id' => $passwordSecurityId,
            'rds_form_token' => $rdsToken,
            'aliedit_uid' => $alieditUid,
            'cookies' => $this->cookiesFrom($response),
            'created_at' => time(),
        ];
        $this->store->put($sessionId, $state);
        return [
            'status' => 'QR_READY',
            'qr_url' => 'https://qr.alipay.com/_d?_b=PAI_LOGIN_DY&securityId=' . rawurlencode($securityId),
            'message' => '请使用支付宝 App 扫码并确认登录',
        ];
    }

    public function pollAuthorization(array $task): ?array
    {
        $sessionId = $this->sessionId($task);
        $state = $this->store->get($sessionId);
        if ($state === null) {
            throw new RuntimeException('支付宝授权会话不存在或已经清理');
        }
        $securityId = (string)($state['security_id'] ?? '');
        $response = $this->http->get('https://securitycore.alipay.com/barcode/barcodeProcessStatus.json', [
            'query' => ['securityId' => $securityId, '_callback' => 'light.request._callbacks.callback2'],
            'headers' => $this->cookieHeaders((array)($state['cookies'] ?? [])) + [
                'Referer' => 'https://b.alipay.com/',
            ],
        ]);
        $body = strtolower((string)$response->getBody());
        if ($response->getStatusCode() !== 200
            || str_contains($body, 'referercheckfailed')
            || str_contains($body, '"success":"false"')) {
            throw new RuntimeException('支付宝扫码状态接口暂时不可用');
        }
        if (str_contains($body, 'waiting')) {
            return null;
        }
        if (str_contains($body, 'scanned')) {
            return ['status' => 'SCANNED', 'message' => '已扫码，等待支付宝确认'];
        }

        $login = $this->http->post('https://authsa128.alipay.com/login/homeB.htm', [
            'headers' => $this->cookieHeaders((array)($state['cookies'] ?? [])) + [
                'Referer' => 'https://b.alipay.com/',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'support' => '000001', 'CtrlVersion' => '1,1,0,1',
                'loginScene' => 'ant_sso_index',
                'goto' => 'https://b.alipay.com/page/bizfund/assetManage',
                'rds_form_token' => (string)($state['rds_form_token'] ?? ''),
                'method' => 'qrCodeLogin', 'superSwitch' => 'true', 'noActiveX' => 'false',
                'passwordSecurityId' => (string)($state['password_security_id'] ?? ''),
                'qrCodeSecurityId' => $securityId, 'J_aliedit_using' => 'true',
                'J_aliedit_key_hidn' => 'password', 'J_aliedit_uid_hidn' => 'alieditUid',
                'alieditUid' => (string)($state['aliedit_uid'] ?? ''),
                'REMOTE_PCID_NAME' => '_seaside_gogo_pcid', 'security_activeX_enabled' => 'false',
            ],
        ]);
        $cookies = array_replace((array)($state['cookies'] ?? []), $this->cookiesFrom($login));
        if (($cookies['ALIPAYJSESSIONID'] ?? '') === '' || ($cookies['CLUB_ALIPAY_COM'] ?? '') === '') {
            return ['status' => 'FAILED', 'message' => '支付宝未返回有效登录会话，请重新扫码'];
        }
        $state['cookies'] = $cookies;
        $state['confirmed_at'] = time();
        $this->store->put($sessionId, $state);
        $externalRef = 'ali_' . substr(hash('sha256', (string)$cookies['CLUB_ALIPAY_COM']), 0, 32);
        return [
            'status' => 'CONFIRMED',
            'external_ref' => $externalRef,
            'display_name' => '支付宝账号 ' . substr($externalRef, -6),
            'capability_status' => 'UNKNOWN',
            'capabilities' => ['web_session' => true, 'stable_bill_id' => false],
            'message' => '扫码登录成功；账单能力尚待验证',
        ];
    }

    public function pullPaymentEvents(int $limit): array
    {
        // 未获得稳定支付宝账单号前禁止使用余额差生成自动核销事件。
        return [];
    }

    public function acknowledgePaymentEvent(string $ackToken): void
    {
    }

    public function bindAuthorizedAccount(string $sessionId, string $accountId): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
            throw new RuntimeException('支付宝云账号 ID 不合法');
        }
        $this->store->bindAccount($sessionId, $accountId);
    }

    /** @param array<string, mixed> $task */
    private function sessionId(array $task): string
    {
        $id = trim((string)($task['id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id)) {
            throw new RuntimeException('支付宝授权会话 ID 不合法');
        }
        return $id;
    }

    private function match(string $html, string $pattern): string
    {
        preg_match($pattern, $html, $matches);
        return html_entity_decode((string)($matches[1] ?? $matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<string, string> */
    private function cookiesFrom(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $pair = trim(explode(';', $header, 2)[0] ?? '');
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) {
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }

    /** @param array<string, string> $cookies @return array<string, string> */
    private function cookieHeaders(array $cookies): array
    {
        if ($cookies === []) {
            return [];
        }
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        return ['Cookie' => implode('; ', $pairs)];
    }
}
