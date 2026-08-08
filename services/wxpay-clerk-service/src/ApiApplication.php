<?php

declare(strict_types=1);

namespace WxpayClerk;

use JsonException;
use RuntimeException;
use Throwable;

final class ApiApplication
{
    /**
     * @param list<string> $webhookAllowedIps
     * @param list<string> $apiAllowedIps
     */
    public function __construct(
        private readonly RequestAuthenticator $authenticator,
        private readonly SignatureHelper $signer,
        private readonly OrderRepository $orders,
        private readonly PaymentEventRepository $events,
        private readonly OutboxRepository $outbox,
        private readonly ReviewRepository $reviews,
        private readonly AccountRepository $accounts,
        private readonly PaymentMatchingService $matching,
        private readonly AuthSessionManager $authSessions,
        private readonly GeweApiClientInterface $gewe,
        private readonly WechatWebhookHandler $webhook,
        private readonly string $webhookToken,
        private readonly array $webhookAllowedIps,
        private readonly array $apiAllowedIps = []
    ) {
        if (strlen($webhookToken) < 32) {
            throw new RuntimeException('webhook_token 长度必须至少为 32 位');
        }
        if ($webhookAllowedIps === []) {
            throw new RuntimeException('gewe_allowed_ips 不得为空');
        }
    }

    /** @param array<string, string> $headers */
    public function handle(
        string $method,
        string $path,
        array $headers,
        string $body,
        string $remoteIp,
        int $now
    ): HttpResponse {
        $method = strtoupper($method);
        if ($method === 'GET' && $path === '/health') {
            return HttpResponse::json(['status' => 'ok', 'ts' => $now]);
        }
        if ($method === 'POST' && str_starts_with($path, '/wechat/message/')) {
            return $this->handleWebhook($path, $body, $remoteIp, $now);
        }
        if (!str_starts_with($path, '/v1/')) {
            return HttpResponse::json(['error' => '路由不存在'], 404);
        }
        if ($this->apiAllowedIps !== [] && !$this->ipAllowed($remoteIp, $this->apiAllowedIps)) {
            return HttpResponse::json(['error' => '来源 IP 未授权'], 401);
        }

        try {
            $clientId = $this->authenticator->authenticate($method, $path, $headers, $body, $now);
        } catch (ApiException $exception) {
            $response = HttpResponse::json(['error' => $exception->getMessage()], $exception->status);
            return $exception->status === 401 ? $response : $this->signed($response);
        }

        try {
            return $this->signed($this->dispatch($method, $path, $body, $clientId, $now));
        } catch (ApiException $exception) {
            return $this->signed(HttpResponse::json(['error' => $exception->getMessage()], $exception->status));
        } catch (JsonException) {
            return $this->signed(HttpResponse::json(['error' => 'JSON 请求体不合法'], 400));
        } catch (Throwable) {
            return $this->signed(HttpResponse::json(['error' => '店员服务暂时不可用'], 503));
        }
    }

    private function dispatch(string $method, string $path, string $body, string $clientId, int $now): HttpResponse
    {
        if ($method === 'POST' && $path === '/v1/orders') {
            $input = $this->jsonBody($body);
            $accountId = trim((string) ($input['account_id'] ?? ''));
            $outTradeNo = trim((string) ($input['out_trade_no'] ?? ''));
            $amount = trim((string) ($input['amount'] ?? ''));
            $expiresAt = (int) ($input['expires_at'] ?? 0);
            if ($accountId === '' || strlen($accountId) > 128
                || !preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $outTradeNo)
                || !preg_match('/^(\d{1,5})\.(\d{2})$/', $amount, $money)
                || ((int) $money[1] * 100 + (int) $money[2]) < 1
                || ((int) $money[1] * 100 + (int) $money[2]) > 5_000_000
                || $expiresAt <= $now
                || $expiresAt > $now + 3600) {
                throw new ApiException(400, '订单参数不完整或不合法');
            }
            $channelId = trim((string) ($input['channel_id'] ?? $input['reference'] ?? $clientId));
            $result = $this->orders->register(
                $accountId,
                $channelId,
                $outTradeNo,
                $amount,
                $expiresAt,
                $now
            );
            return HttpResponse::json(array_merge($result, ['out_trade_no' => $outTradeNo]));
        }

        if ($method === 'GET' && preg_match('#^/v1/orders/([^/]+)$#', $path, $matches)) {
            $outTradeNo = rawurldecode($matches[1]);
            $order = $this->orders->find($outTradeNo);
            if ($order === null) {
                throw new ApiException(404, '订单不存在');
            }
            $event = $this->events->findByOrder($outTradeNo);
            $outbox = $this->outbox->findByOrder($outTradeNo);
            return HttpResponse::json([
                'paid' => $order['status'] === 'MATCHED' && $event !== null,
                'out_trade_no' => (string) $order['out_trade_no'],
                'amount' => (string) $order['amount'],
                'occurred_at' => (int) ($event['occurred_at'] ?? 0),
                'callback_status' => (string) ($outbox['status'] ?? ''),
            ]);
        }

        if ($method === 'POST' && $path === '/v1/auth-sessions') {
            $reference = trim((string) ($this->jsonBody($body)['reference'] ?? ''));
            if ($reference === '' || strlen($reference) > 128) {
                throw new ApiException(400, '缺少合法 reference 字段');
            }
            return HttpResponse::json($this->authSessions->createSession($reference));
        }

        if ($method === 'GET' && preg_match('#^/v1/auth-sessions/([A-Za-z0-9_-]{8,64})$#', $path, $matches)) {
            return HttpResponse::json($this->authSessions->pollSession($matches[1]));
        }

        if ($method === 'GET' && preg_match('#^/v1/accounts/([^/]+)/capabilities$#', $path, $matches)) {
            return HttpResponse::json($this->capabilities(rawurldecode($matches[1])));
        }

        if ($method === 'GET' && $path === '/v1/review/events') {
            $events = $this->reviews->pending();
            return HttpResponse::json(['events' => $events, 'count' => count($events)]);
        }

        if ($method === 'POST' && preg_match('#^/v1/review/events/(\d+)/match$#', $path, $matches)) {
            $input = $this->jsonBody($body);
            $outTradeNo = trim((string) ($input['out_trade_no'] ?? ''));
            if ($outTradeNo === '') {
                throw new ApiException(400, '缺少 out_trade_no');
            }
            return HttpResponse::json($this->matching->matchReview(
                (int) $matches[1],
                $outTradeNo,
                trim((string) ($input['operator'] ?? 'admin')),
                trim((string) ($input['note'] ?? ''))
            ));
        }

        if ($method === 'POST' && preg_match('#^/v1/review/events/(\d+)/ignore$#', $path, $matches)) {
            $input = $this->jsonBody($body);
            return HttpResponse::json($this->matching->ignoreReview(
                (int) $matches[1],
                trim((string) ($input['operator'] ?? 'admin')),
                trim((string) ($input['note'] ?? ''))
            ));
        }

        if ($method === 'GET' && $path === '/v1/ops/status') {
            $accounts = $this->accounts->all();
            $online = count(array_filter($accounts, static fn (array $account): bool => $account['status'] === 'ONLINE'));
            $outbox = $this->outbox->statusSummary();
            return HttpResponse::json([
                'status' => $outbox['failed_count'] > 0 || $online === 0 ? 'DEGRADED' : 'OK',
                'accounts' => $accounts,
                'online_count' => $online,
                'total_count' => count($accounts),
                'outbox' => $outbox,
            ]);
        }

        throw new ApiException(404, '路由不存在');
    }

    /** @return array<string, mixed> */
    private function capabilities(string $accountId): array
    {
        $account = $this->accounts->find($accountId);
        if ($account === null) {
            return ['status' => 'UNKNOWN', 'message' => '账号未注册', 'capabilities' => []];
        }
        $status = $this->gewe->getAccountStatus((string) $account['gewe_app_id']);
        $online = (bool) ($status['online'] ?? false);
        $this->accounts->save(
            $accountId,
            (string) $account['nickname'],
            (string) $account['gewe_app_id'],
            $online ? 'ONLINE' : 'OFFLINE'
        );
        return [
            'status' => $online ? 'RECEIPT_AVAILABLE' : 'REAUTH_REQUIRED',
            'message' => $online ? '微信店员账号在线' : '微信店员账号已离线，请重新扫码登录',
            'capabilities' => ['clerk_notification' => $online],
        ];
    }

    private function handleWebhook(string $path, string $body, string $remoteIp, int $now): HttpResponse
    {
        $token = rawurldecode(substr($path, strlen('/wechat/message/')));
        if (!hash_equals($this->webhookToken, $token)
            || !$this->ipAllowed($remoteIp, $this->webhookAllowedIps)) {
            return HttpResponse::json(['error' => 'Webhook 身份验证失败'], 401);
        }
        if (strlen($body) > 1_048_576) {
            return HttpResponse::json(['error' => 'Webhook 请求体过大'], 400);
        }
        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new JsonException('not object');
            }
            $this->webhook->handle($payload, $now);
            return HttpResponse::json(['ok' => true]);
        } catch (JsonException) {
            return HttpResponse::json(['error' => 'Webhook JSON 不合法'], 400);
        } catch (Throwable) {
            return HttpResponse::json(['error' => 'Webhook 持久化失败'], 503);
        }
    }

    /** @return array<string, mixed> */
    private function jsonBody(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('JSON body must be object');
        }
        return $decoded;
    }

    private function signed(HttpResponse $response): HttpResponse
    {
        return $response->withHeader('X-CXPAY-Signature', $this->signer->signResponse($response->body));
    }

    /** @param list<string> $allowed */
    private function ipAllowed(string $remoteIp, array $allowed): bool
    {
        foreach ($allowed as $rule) {
            if (hash_equals($rule, $remoteIp)) {
                return true;
            }
            if (str_contains($rule, '/') && filter_var($remoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                [$network, $prefix] = array_pad(explode('/', $rule, 2), 2, '');
                if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && ctype_digit($prefix)
                    && (int) $prefix >= 0
                    && (int) $prefix <= 32) {
                    $mask = (int) $prefix === 0 ? 0 : (-1 << (32 - (int) $prefix));
                    if (((int) ip2long($remoteIp) & $mask) === ((int) ip2long($network) & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
