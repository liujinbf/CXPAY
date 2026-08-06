<?php

declare(strict_types=1);

namespace WxpayClerk;

/**
 * 处理 CXPAY 插件发来的所有 API 请求。
 *
 * 端点清单：
 *   POST /v1/orders                    — 登记待匹配订单
 *   POST /v1/auth-sessions             — 创建登录会话（生成微信扫码）
 *   GET  /v1/auth-sessions/{id}        — 轮询登录/绑定状态
 *   GET  /v1/accounts/{id}/capabilities — 检测账号在线能力
 *   GET  /v1/ops/status                — 全局运维状态
 *   GET  /v1/review/events             — 获取待人工审核的到账事件
 *   POST /v1/review/events/{id}/match  — 手动关联到账与订单
 *   POST /v1/review/events/{id}/ignore — 忽略审核事件
 */
final class ApiServer
{
    public function __construct(
        private readonly SignatureHelper    $signer,
        private readonly OrderStore        $store,
        private readonly AuthSessionManager $authMgr,
        private readonly GeweApiClient     $gewe
    ) {}

    /**
     * 主路由分发。
     */
    public function dispatch(string $method, string $path, string $rawBody): never
    {
        // 签名验证
        if (!$this->signer->verifyIncomingRequest($method, $path, $rawBody)) {
            $this->signer->sendJson(['error' => '签名验证失败'], 401);
        }

        $body = $rawBody !== '' ? (json_decode($rawBody, true) ?? []) : [];

        // ─── POST /v1/orders ──────────────────────────────────────────────────
        if ($method === 'POST' && $path === '/v1/orders') {
            $this->handleRegisterOrder((array)$body);
        }

        // ─── POST /v1/auth-sessions ───────────────────────────────────────────
        if ($method === 'POST' && $path === '/v1/auth-sessions') {
            $this->handleCreateAuthSession((array)$body);
        }

        // ─── GET /v1/auth-sessions/{id} ───────────────────────────────────────
        if ($method === 'GET' && preg_match('#^/v1/auth-sessions/([A-Za-z0-9_-]{8,64})$#', $path, $m)) {
            $this->handlePollAuthSession($m[1]);
        }

        // ─── GET /v1/accounts/{id}/capabilities ──────────────────────────────
        if ($method === 'GET' && preg_match('#^/v1/accounts/([^/]+)/capabilities$#', $path, $m)) {
            $this->handleCapabilities(rawurldecode($m[1]));
        }

        // ─── GET /v1/ops/status ───────────────────────────────────────────────
        if ($method === 'GET' && $path === '/v1/ops/status') {
            $this->handleOpsStatus();
        }

        // ─── GET /v1/review/events ────────────────────────────────────────────
        if ($method === 'GET' && $path === '/v1/review/events') {
            $this->handleListReviewEvents();
        }

        // ─── POST /v1/review/events/{id}/match ───────────────────────────────
        if ($method === 'POST' && preg_match('#^/v1/review/events/(\d+)/match$#', $path, $m)) {
            $this->handleMatchReviewEvent((int)$m[1], (array)$body);
        }

        // ─── POST /v1/review/events/{id}/ignore ──────────────────────────────
        if ($method === 'POST' && preg_match('#^/v1/review/events/(\d+)/ignore$#', $path, $m)) {
            $this->handleIgnoreReviewEvent((int)$m[1], (array)$body);
        }

        $this->signer->sendJson(['error' => '路由不存在'], 404);
    }

    // ─── 各端点处理 ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function handleRegisterOrder(array $body): never
    {
        $accountId  = trim((string)($body['account_id']   ?? ''));
        $channelId  = trim((string)($body['channel_id']   ?? $body['reference'] ?? ''));
        $outTradeNo = trim((string)($body['out_trade_no'] ?? ''));
        $amount     = number_format((float)($body['amount'] ?? 0), 2, '.', '');
        $expiresAt  = (int)($body['expires_at'] ?? 0);

        if ($outTradeNo === '' || (float)$amount <= 0 || $expiresAt <= time()) {
            $this->signer->sendJson(['error' => '订单参数不完整或已过期'], 400);
        }

        $this->store->upsertOrder($accountId, $channelId, $outTradeNo, $amount, $expiresAt);

        $this->signer->sendJson(['accepted' => true, 'out_trade_no' => $outTradeNo]);
    }

    /** @param array<string, mixed> $body */
    private function handleCreateAuthSession(array $body): never
    {
        $reference = trim((string)($body['reference'] ?? ''));
        if ($reference === '') {
            $this->signer->sendJson(['error' => '缺少 reference 字段'], 400);
        }

        $result = $this->authMgr->createSession($reference);
        $this->signer->sendJson($result);
    }

    private function handlePollAuthSession(string $sessionId): never
    {
        $result = $this->authMgr->pollSession($sessionId);
        $this->signer->sendJson($result);
    }

    private function handleCapabilities(string $accountId): never
    {
        $account = $this->store->getAccount($accountId);
        if ($account === null) {
            $this->signer->sendJson([
                'status'       => 'UNKNOWN',
                'message'      => '账号未注册，请先完成微信登录绑定',
                'capabilities' => [],
            ]);
        }

        $geweAppId = (string)($account['gewe_app_id'] ?? '');
        $geweStatus = $geweAppId !== '' ? $this->gewe->getAccountStatus($geweAppId) : ['online' => false];

        $isOnline = (bool)($geweStatus['online'] ?? false);
        $dbStatus = (string)($account['status'] ?? 'OFFLINE');

        // 同步最新在线状态回数据库
        if ($isOnline && $dbStatus !== 'ONLINE') {
            $this->store->upsertAccount(
                $accountId, (string)($account['nickname'] ?? ''), $geweAppId, 'ONLINE'
            );
        }

        $status  = $isOnline ? 'RECEIPT_AVAILABLE' : 'REAUTH_REQUIRED';
        $message = $isOnline
            ? '微信店员账号在线，收款通知正常接收'
            : '微信店员账号已离线，请重新扫码登录';

        $this->signer->sendJson([
            'status'       => $status,
            'message'      => $message,
            'capabilities' => ['clerk_notification' => $isOnline],
        ]);
    }

    private function handleOpsStatus(): never
    {
        $accounts = $this->store->getAllAccounts();
        $onlineCount = count(array_filter($accounts, fn ($a) => $a['status'] === 'ONLINE'));

        $this->signer->sendJson([
            'status'       => $onlineCount > 0 ? 'OK' : 'DEGRADED',
            'accounts'     => array_map(fn ($a) => [
                'id'           => $a['id'],
                'nickname'     => $a['nickname'],
                'status'       => $a['status'],
                'last_seen_at' => $a['last_seen_at'],
            ], $accounts),
            'online_count' => $onlineCount,
            'total_count'  => count($accounts),
        ]);
    }

    private function handleListReviewEvents(): never
    {
        $accountId = trim((string)($_GET['account_id'] ?? ''));
        $events    = $this->store->getPendingReviewEvents($accountId);
        $this->signer->sendJson(['events' => $events, 'count' => count($events)]);
    }

    /** @param array<string, mixed> $body */
    private function handleMatchReviewEvent(int $eventId, array $body): never
    {
        $outTradeNo = trim((string)($body['out_trade_no'] ?? ''));
        $operator   = trim((string)($body['operator']    ?? 'admin'));
        $note       = trim((string)($body['note']        ?? ''));

        if ($outTradeNo === '') {
            $this->signer->sendJson(['error' => '缺少 out_trade_no'], 400);
        }

        $event = $this->store->getReviewEvent($eventId);
        if ($event === null) {
            $this->signer->sendJson(['error' => '审核事件不存在或已处理'], 404);
        }

        $ok = $this->store->resolveReviewEvent($eventId, 'MATCHED', $outTradeNo, $operator, $note);
        $this->signer->sendJson(['success' => $ok]);
    }

    /** @param array<string, mixed> $body */
    private function handleIgnoreReviewEvent(int $eventId, array $body): never
    {
        $operator = trim((string)($body['operator'] ?? 'admin'));
        $note     = trim((string)($body['note']     ?? ''));
        $ok       = $this->store->resolveReviewEvent($eventId, 'IGNORED', '', $operator, $note);
        $this->signer->sendJson(['success' => $ok]);
    }
}
