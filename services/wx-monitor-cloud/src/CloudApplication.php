<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use PDO;
use RuntimeException;
use Throwable;

final class CloudApplication
{
    private const CAPABILITY_STATUSES = [
        'UNKNOWN', 'RECEIPT_AVAILABLE', 'RECEIPT_NOT_OPENED',
        'BOOK_AVAILABLE', 'REAUTH_REQUIRED', 'TEMPORARY_ERROR',
    ];

    public function __construct(private readonly PDO $pdo, private readonly Authenticator $authenticator)
    {
    }

    /** @param array<string, string> $headers */
    public function handle(string $method, string $path, array $headers, string $body): HttpResponse
    {
        $method = strtoupper($method);
        if ($method === 'GET' && $path === '/health') {
            return HttpResponse::json(['status' => 'ok', 'time' => time()]);
        }

        $role = str_starts_with($path, '/v1/collector/') ? 'collector' : 'client';
        try {
            $principal = $this->authenticator->authenticate($method, $path, $headers, $body, $role);
        } catch (Throwable $e) {
            return HttpResponse::json(['code' => 'UNAUTHORIZED', 'message' => $e->getMessage()], 401);
        }

        try {
            $data = $body === '' ? [] : json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException('请求体必须是 JSON 对象');
            }
            $response = $role === 'collector'
                ? $this->handleCollector($method, $path, $data, $principal)
                : $this->handleClient($method, $path, $data, $principal);
        } catch (\JsonException) {
            $response = HttpResponse::json(['code' => 'INVALID_JSON', 'message' => '请求体不是合法 JSON'], 400);
        } catch (DomainConflictException $e) {
            $response = HttpResponse::json(['code' => 'CONFLICT', 'message' => $e->getMessage()], 409);
        } catch (DomainNotFoundException $e) {
            $response = HttpResponse::json(['code' => 'NOT_FOUND', 'message' => $e->getMessage()], 404);
        } catch (Throwable $e) {
            $response = HttpResponse::json(['code' => 'BAD_REQUEST', 'message' => $e->getMessage()], 400);
        }
        return $this->authenticator->signResponse($response, $principal);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $principal */
    private function handleClient(string $method, string $path, array $data, array $principal): HttpResponse
    {
        $clientId = (string)$principal['id'];
        if ($method === 'POST' && $path === '/v1/auth-sessions') {
            return $this->createAuthSession($clientId, $data);
        }
        if ($method === 'GET' && preg_match('#^/v1/auth-sessions/([A-Za-z0-9_-]{16,64})$#', $path, $matches)) {
            return $this->getAuthSession($clientId, $matches[1]);
        }
        if ($method === 'GET' && preg_match('#^/v1/accounts/([A-Za-z0-9_-]{16,64})/capabilities$#', $path, $matches)) {
            return $this->getCapabilities($clientId, $matches[1]);
        }
        if ($method === 'POST' && $path === '/v1/orders') {
            return $this->registerOrder($clientId, $data);
        }
        if ($method === 'GET' && $path === '/v1/review/events') {
            return $this->reviewEvents($clientId);
        }
        if ($method === 'GET' && $path === '/v1/ops/status') {
            return $this->operationsStatus($clientId);
        }
        if ($method === 'POST'
            && preg_match('#^/v1/review/events/([1-9][0-9]*)/match$#', $path, $matches)) {
            return $this->matchReviewEvent($clientId, (int)$matches[1], $data);
        }
        if ($method === 'POST'
            && preg_match('#^/v1/review/events/([1-9][0-9]*)/ignore$#', $path, $matches)) {
            return $this->ignoreReviewEvent($clientId, (int)$matches[1], $data);
        }
        return HttpResponse::json(['code' => 'NOT_FOUND', 'message' => '接口不存在'], 404);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $principal */
    private function handleCollector(string $method, string $path, array $data, array $principal): HttpResponse
    {
        $collectorId = (string)$principal['id'];
        if ($method === 'GET' && $path === '/v1/collector/auth-sessions/pending') {
            return $this->pendingAuthSessions($collectorId);
        }
        if ($method === 'POST'
            && preg_match('#^/v1/collector/auth-sessions/([A-Za-z0-9_-]{16,64})$#', $path, $matches)) {
            return $this->updateAuthSession($collectorId, $matches[1], $data);
        }
        if ($method === 'POST' && $path === '/v1/collector/events') {
            return $this->ingestPaymentEvent($collectorId, $data);
        }
        return HttpResponse::json(['code' => 'NOT_FOUND', 'message' => '采集器接口不存在'], 404);
    }

    /** @param array<string, mixed> $data */
    private function createAuthSession(string $clientId, array $data): HttpResponse
    {
        $reference = trim((string)($data['reference'] ?? ''));
        if ($reference !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $reference)) {
            throw new RuntimeException('授权业务引用格式不合法');
        }
        $id = self::randomId('was_');
        $now = time();
        $this->pdo->prepare(
            'INSERT INTO auth_sessions(
                id, client_id, collector_id, status, qr_url, account_id, message, created_at, expires_at
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $clientId, '', 'WAITING_COLLECTOR', '', '', '等待授权采集器生成二维码', $now, $now + 300]);
        return HttpResponse::json([
            'session_id' => $id,
            'status' => 'WAITING_COLLECTOR',
            'qr_url' => '',
            'expires_at' => $now + 300,
            'reference' => $reference,
        ], 201);
    }

    private function getAuthSession(string $clientId, string $sessionId): HttpResponse
    {
        $statement = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE id = ? AND client_id = ?');
        $statement->execute([$sessionId, $clientId]);
        $session = $statement->fetch();
        if (!$session) {
            throw new DomainNotFoundException('授权会话不存在');
        }
        if ((int)$session['expires_at'] < time() && !in_array($session['status'], ['CONFIRMED', 'BOUND', 'FAILED'], true)) {
            $this->pdo->prepare('UPDATE auth_sessions SET status = ?, message = ? WHERE id = ?')
                ->execute(['EXPIRED', '授权二维码已过期', $sessionId]);
            $session['status'] = 'EXPIRED';
            $session['message'] = '授权二维码已过期';
        }
        return HttpResponse::json([
            'session_id' => $session['id'],
            'status' => $session['status'] === 'BOUND' ? 'CONFIRMED' : $session['status'],
            'qr_url' => $session['qr_url'],
            'account_id' => $session['account_id'],
            'message' => $session['message'],
            'expires_at' => (int)$session['expires_at'],
        ]);
    }

    private function getCapabilities(string $clientId, string $accountId): HttpResponse
    {
        $statement = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ? AND client_id = ?');
        $statement->execute([$accountId, $clientId]);
        $account = $statement->fetch();
        if (!$account) {
            throw new DomainNotFoundException('云监控账号不存在');
        }
        return HttpResponse::json([
            'status' => $account['capability_status'],
            'message' => $this->capabilityMessage((string)$account['capability_status']),
            'capabilities' => json_decode((string)$account['capabilities_json'], true) ?: [],
            'auth_status' => $account['auth_status'],
            'updated_at' => (int)$account['updated_at'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function registerOrder(string $clientId, array $data): HttpResponse
    {
        $accountId = trim((string)($data['account_id'] ?? ''));
        $outTradeNo = trim((string)($data['out_trade_no'] ?? ''));
        $amount = self::money($data['amount'] ?? null);
        $expiresAt = (int)($data['expires_at'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)
            || !preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $outTradeNo)
            || $expiresAt <= time() || $expiresAt > time() + 3600) {
            throw new RuntimeException('订单登记参数不合法');
        }
        $account = $this->pdo->prepare('SELECT id FROM accounts WHERE id = ? AND client_id = ? AND auth_status = ?');
        $account->execute([$accountId, $clientId, 'ACTIVE']);
        if (!$account->fetch()) {
            throw new DomainNotFoundException('账号不存在、未授权或不属于当前客户端');
        }
        $existing = $this->pdo->prepare('SELECT * FROM pending_orders WHERE client_id = ? AND out_trade_no = ?');
        $existing->execute([$clientId, $outTradeNo]);
        $order = $existing->fetch();
        if ($order) {
            if (!hash_equals((string)$order['account_id'], $accountId)
                || !hash_equals((string)$order['amount'], $amount)
                || (int)$order['expires_at'] !== $expiresAt) {
                throw new DomainConflictException('相同订单号的登记内容不一致');
            }
            return HttpResponse::json(['accepted' => true, 'duplicate' => true]);
        }
        $this->pdo->prepare(
            'INSERT INTO pending_orders(client_id, account_id, out_trade_no, amount, status, created_at, expires_at)
             VALUES(?, ?, ?, ?, ?, ?, ?)'
        )->execute([$clientId, $accountId, $outTradeNo, $amount, 'PENDING', time(), $expiresAt]);
        return HttpResponse::json(['accepted' => true, 'duplicate' => false], 201);
    }

    private function pendingAuthSessions(string $collectorId): HttpResponse
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, collector_id, account_id, created_at, expires_at FROM auth_sessions
             WHERE (
               expires_at >= ? AND (
                 (status = ? AND collector_id = \'\') OR
                 (collector_id = ? AND status IN (?, ?, ?))
               )
             ) OR (collector_id = ? AND status = ?)
             ORDER BY created_at ASC LIMIT 20'
        );
        $statement->execute([
            time(), 'WAITING_COLLECTOR', $collectorId, 'CLAIMED', 'QR_READY', 'SCANNED',
            $collectorId, 'CONFIRMED',
        ]);
        return HttpResponse::json(['data' => $statement->fetchAll()]);
    }

    /** @param array<string, mixed> $data */
    private function updateAuthSession(string $collectorId, string $sessionId, array $data): HttpResponse
    {
        $status = strtoupper(trim((string)($data['status'] ?? '')));
        if (!in_array($status, ['CLAIMED', 'QR_READY', 'SCANNED', 'CONFIRMED', 'BOUND', 'FAILED'], true)) {
            throw new RuntimeException('授权会话状态不合法');
        }
        $this->pdo->beginTransaction();
        try {
            if ($status === 'CLAIMED') {
                $claim = $this->pdo->prepare(
                    'UPDATE auth_sessions SET collector_id = ?, status = ?, message = ?
                     WHERE id = ? AND status = ? AND collector_id = \'\' AND expires_at >= ?'
                );
                $claim->execute([
                    $collectorId, 'CLAIMED', '授权任务已由采集器领取',
                    $sessionId, 'WAITING_COLLECTOR', time(),
                ]);
                if ($claim->rowCount() === 1) {
                    $this->pdo->commit();
                    return HttpResponse::json(['accepted' => true, 'claimed' => true]);
                }
                $existingClaim = $this->pdo->prepare('SELECT collector_id, status FROM auth_sessions WHERE id = ?');
                $existingClaim->execute([$sessionId]);
                $claimedSession = $existingClaim->fetch();
                if ($claimedSession
                    && $claimedSession['status'] === 'CLAIMED'
                    && hash_equals((string)$claimedSession['collector_id'], $collectorId)) {
                    $this->pdo->commit();
                    return HttpResponse::json(['accepted' => true, 'claimed' => true, 'duplicate' => true]);
                }
                throw new DomainConflictException('授权任务已被其他采集器领取或已经失效');
            }
            $lockSuffix = $this->isMysql() ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare('SELECT * FROM auth_sessions WHERE id = ?' . $lockSuffix);
            $statement->execute([$sessionId]);
            $session = $statement->fetch();
            if (!$session || ((int)$session['expires_at'] < time()
                    && !in_array($session['status'], ['CONFIRMED', 'BOUND'], true))) {
                throw new DomainNotFoundException('授权会话不存在或已过期');
            }
            if ($session['collector_id'] !== '' && !hash_equals((string)$session['collector_id'], $collectorId)) {
                throw new DomainConflictException('授权会话已由其他采集器接管');
            }
            if ($session['status'] === 'BOUND') {
                $this->pdo->commit();
                return HttpResponse::json([
                    'accepted' => true,
                    'duplicate' => true,
                    'account_id' => $session['account_id'],
                ]);
            }
            if ($session['status'] === 'CONFIRMED' && $status !== 'BOUND') {
                $this->pdo->commit();
                return HttpResponse::json([
                    'accepted' => true,
                    'duplicate' => true,
                    'account_id' => $session['account_id'],
                ]);
            }
            $transitions = [
                'WAITING_COLLECTOR' => ['CLAIMED'],
                'CLAIMED' => ['CLAIMED', 'QR_READY', 'FAILED'],
                'QR_READY' => ['QR_READY', 'SCANNED', 'CONFIRMED', 'FAILED'],
                'SCANNED' => ['SCANNED', 'CONFIRMED', 'FAILED'],
                'CONFIRMED' => ['BOUND'],
            ];
            if (!in_array($status, $transitions[$session['status']] ?? [], true)) {
                throw new DomainConflictException('授权会话状态不能逆向或重复变更');
            }
            $qrUrl = trim((string)($data['qr_url'] ?? $session['qr_url']));
            if ($status === 'QR_READY' && ($qrUrl === '' || strlen($qrUrl) > 4096)) {
                throw new RuntimeException('采集器没有提供有效授权二维码');
            }
            $accountId = (string)$session['account_id'];
            if ($status === 'CONFIRMED') {
                $capabilityStatus = strtoupper(trim((string)($data['capability_status'] ?? 'UNKNOWN')));
                if (!in_array($capabilityStatus, self::CAPABILITY_STATUSES, true)) {
                    throw new RuntimeException('账号能力状态不合法');
                }
                $accountId = $accountId !== '' ? $accountId : self::randomId('wxa_');
                $capabilities = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
                $this->pdo->prepare(
                    'INSERT INTO accounts(id, client_id, collector_id, external_ref, display_name, auth_status, capability_status, capabilities_json, updated_at)
                     VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $accountId, $session['client_id'], $collectorId,
                    mb_substr(trim((string)($data['external_ref'] ?? '')), 0, 255),
                    mb_substr(trim((string)($data['display_name'] ?? '')), 0, 255),
                    'ACTIVE', $capabilityStatus,
                    json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    time(),
                ]);
            }
            $message = mb_substr(trim((string)($data['message'] ?? '')), 0, 500);
            $this->pdo->prepare(
                'UPDATE auth_sessions SET collector_id = ?, status = ?, qr_url = ?, account_id = ?, message = ? WHERE id = ?'
            )->execute([$collectorId, $status, $qrUrl, $accountId, $message, $sessionId]);
            $this->pdo->commit();
            return HttpResponse::json(['accepted' => true, 'account_id' => $accountId]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    private function ingestPaymentEvent(string $collectorId, array $data): HttpResponse
    {
        $accountId = trim((string)($data['account_id'] ?? ''));
        $sourceBillId = trim((string)($data['source_bill_id'] ?? ''));
        $amount = self::money($data['amount'] ?? null);
        $occurredAt = (int)($data['occurred_at'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)
            || !preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $sourceBillId)
            || $occurredAt < time() - 86400 || $occurredAt > time() + 60) {
            throw new RuntimeException('到账事件参数不合法');
        }
        $accountStatement = $this->pdo->prepare(
            'SELECT * FROM accounts WHERE id = ? AND collector_id = ? AND auth_status = ?'
        );
        $accountStatement->execute([$accountId, $collectorId, 'ACTIVE']);
        $account = $accountStatement->fetch();
        if (!$account) {
            throw new DomainNotFoundException('账号不存在、未授权或不属于当前采集器');
        }

        $this->pdo->beginTransaction();
        try {
            $lockSuffix = $this->isMysql() ? ' FOR UPDATE' : '';
            $existing = $this->pdo->prepare(
                'SELECT * FROM payment_events WHERE account_id = ? AND source_bill_id = ?' . $lockSuffix
            );
            $existing->execute([$accountId, $sourceBillId]);
            $event = $existing->fetch();
            if ($event) {
                if (!hash_equals((string)$event['amount'], $amount)
                    || (int)$event['occurred_at'] !== $occurredAt) {
                    throw new DomainConflictException('相同账单号的到账内容不一致');
                }
                $this->pdo->commit();
                return HttpResponse::json(['accepted' => true, 'duplicate' => true, 'status' => $event['status']]);
            }

            $orders = $this->pdo->prepare(
                'SELECT * FROM pending_orders WHERE account_id = ? AND amount = ? AND status = ?
                 AND created_at <= ? AND expires_at >= ? ORDER BY id DESC LIMIT 2' . $lockSuffix
            );
            $orders->execute([$accountId, $amount, 'PENDING', $occurredAt + 10, $occurredAt]);
            $candidates = $orders->fetchAll();
            $eventStatus = count($candidates) === 1 ? 'MATCHED' : (count($candidates) > 1 ? 'REVIEW_REQUIRED' : 'UNMATCHED');
            $matchedOrder = count($candidates) === 1 ? $candidates[0] : null;
            $this->pdo->prepare(
                'INSERT INTO payment_events(account_id, source_bill_id, amount, occurred_at, status, matched_order_id, created_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?)'
            )->execute([$accountId, $sourceBillId, $amount, $occurredAt, $eventStatus, $matchedOrder['id'] ?? null, time()]);
            $eventId = (int)$this->pdo->lastInsertId();
            if ($matchedOrder) {
                $this->pdo->prepare('UPDATE pending_orders SET status = ? WHERE id = ? AND status = ?')
                    ->execute(['MATCHED', $matchedOrder['id'], 'PENDING']);
                $payload = [
                    'source_bill_id' => $sourceBillId,
                    'out_trade_no' => $matchedOrder['out_trade_no'],
                    'money' => $amount,
                    'occurred_at' => $occurredAt,
                    'timestamp' => time(),
                    'nonce' => bin2hex(random_bytes(16)),
                ];
                $this->pdo->prepare(
                    'INSERT INTO callback_outbox(
                        client_id, event_id, callback_url, payload_json, status,
                        attempts, next_attempt_at, last_error, created_at
                     ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $account['client_id'], $eventId, $this->clientCallbackUrl((string)$account['client_id']),
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'PENDING', 0, time(), '', time(),
                ]);
            }
            $this->pdo->commit();
            return HttpResponse::json(['accepted' => true, 'duplicate' => false, 'status' => $eventStatus], 201);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function clientCallbackUrl(string $clientId): string
    {
        $statement = $this->pdo->prepare('SELECT callback_url FROM principals WHERE id = ? AND role = ?');
        $statement->execute([$clientId, 'client']);
        $url = (string)($statement->fetchColumn() ?: '');
        if ($url === '') {
            throw new RuntimeException('CXPAY 客户端尚未配置回调地址');
        }
        return $url;
    }

    private function reviewEvents(string $clientId): HttpResponse
    {
        $statement = $this->pdo->prepare(
            "SELECT pe.id, pe.account_id, pe.source_bill_id, pe.amount, pe.occurred_at,
                    pe.status, pe.created_at
             FROM payment_events pe
             INNER JOIN accounts a ON a.id = pe.account_id
             WHERE a.client_id = ? AND pe.status IN ('REVIEW_REQUIRED', 'UNMATCHED')
             ORDER BY pe.id DESC LIMIT 50"
        );
        $statement->execute([$clientId]);
        $events = $statement->fetchAll();
        foreach ($events as &$event) {
            $candidates = $this->pdo->prepare(
                'SELECT out_trade_no, amount, created_at, expires_at
                 FROM pending_orders
                 WHERE client_id = ? AND account_id = ? AND amount = ? AND status = ?
                   AND created_at <= ? AND expires_at >= ?
                 ORDER BY id DESC LIMIT 5'
            );
            $candidates->execute([
                $clientId, $event['account_id'], $event['amount'], 'PENDING',
                (int)$event['occurred_at'] + 10, (int)$event['occurred_at'],
            ]);
            $event['id'] = (int)$event['id'];
            $event['occurred_at'] = (int)$event['occurred_at'];
            $event['created_at'] = (int)$event['created_at'];
            $event['amount'] = number_format((float)$event['amount'], 2, '.', '');
            $event['candidates'] = $candidates->fetchAll();
        }
        unset($event);
        return HttpResponse::json(['data' => $events]);
    }

    private function operationsStatus(string $clientId): HttpResponse
    {
        $accounts = $this->pdo->prepare(
            'SELECT id, collector_id, display_name, auth_status, capability_status, updated_at
             FROM accounts WHERE client_id = ? ORDER BY updated_at DESC'
        );
        $accounts->execute([$clientId]);
        $accountRows = $accounts->fetchAll();
        foreach ($accountRows as &$account) {
            $account['updated_at'] = (int)$account['updated_at'];
            $account['metrics'] = [
                'orders' => $this->accountStatusCounts('pending_orders', (string)$account['id']),
                'events' => $this->accountStatusCounts('payment_events', (string)$account['id']),
                'outbox' => $this->accountOutboxStatusCounts((string)$account['id']),
            ];
        }
        unset($account);

        $collectorIds = $this->pdo->prepare(
            "SELECT collector_id FROM accounts WHERE client_id = ? AND collector_id <> ''
             UNION
             SELECT collector_id FROM auth_sessions WHERE client_id = ? AND collector_id <> ''"
        );
        $collectorIds->execute([$clientId, $clientId]);
        $collectors = [];
        foreach ($collectorIds->fetchAll(PDO::FETCH_COLUMN) as $collectorId) {
            $activity = $this->pdo->prepare(
                'SELECT p.status, pa.last_authenticated_at
                 FROM principals p LEFT JOIN principal_activity pa ON pa.principal_id = p.id
                 WHERE p.id = ? AND p.role = ?'
            );
            $activity->execute([(string)$collectorId, 'collector']);
            $row = $activity->fetch() ?: [];
            $lastSeen = (int)($row['last_authenticated_at'] ?? 0);
            $collectors[] = [
                'id' => (string)$collectorId,
                'enabled' => (int)($row['status'] ?? 0) === 1,
                'last_seen_at' => $lastSeen,
                'online' => $lastSeen >= time() - 120,
            ];
        }

        $oldestOutbox = $this->pdo->prepare(
            "SELECT MIN(created_at) FROM callback_outbox
             WHERE client_id = ? AND status IN ('PENDING', 'RETRY', 'PROCESSING', 'FAILED')"
        );
        $oldestOutbox->execute([$clientId]);
        return HttpResponse::json([
            'server_time' => time(),
            'accounts' => $accountRows,
            'collectors' => $collectors,
            'metrics' => [
                'auth_sessions' => $this->statusCounts('auth_sessions', 'client_id', $clientId),
                'orders' => $this->statusCounts('pending_orders', 'client_id', $clientId),
                'events' => $this->eventStatusCounts($clientId),
                'outbox' => $this->statusCounts('callback_outbox', 'client_id', $clientId),
                'oldest_outbox_at' => (int)($oldestOutbox->fetchColumn() ?: 0),
            ],
        ]);
    }

    /** @return array<string, int> */
    private function statusCounts(string $table, string $ownerColumn, string $clientId): array
    {
        $allowed = [
            'auth_sessions' => 'client_id',
            'pending_orders' => 'client_id',
            'callback_outbox' => 'client_id',
        ];
        if (($allowed[$table] ?? '') !== $ownerColumn) {
            throw new RuntimeException('状态统计数据源不合法');
        }
        $statement = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS aggregate FROM {$table}
             WHERE {$ownerColumn} = ? GROUP BY status"
        );
        $statement->execute([$clientId]);
        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string)$row['status']] = (int)$row['aggregate'];
        }
        return $counts;
    }

    /** @return array<string, int> */
    private function eventStatusCounts(string $clientId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pe.status, COUNT(*) AS aggregate
             FROM payment_events pe INNER JOIN accounts a ON a.id = pe.account_id
             WHERE a.client_id = ? GROUP BY pe.status'
        );
        $statement->execute([$clientId]);
        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string)$row['status']] = (int)$row['aggregate'];
        }
        return $counts;
    }

    /** @return array<string, int> */
    private function accountStatusCounts(string $table, string $accountId): array
    {
        if (!in_array($table, ['pending_orders', 'payment_events'], true)) {
            throw new RuntimeException('账号状态统计数据源不合法');
        }
        $statement = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS aggregate FROM {$table} WHERE account_id = ? GROUP BY status"
        );
        $statement->execute([$accountId]);
        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string)$row['status']] = (int)$row['aggregate'];
        }
        return $counts;
    }

    /** @return array<string, int> */
    private function accountOutboxStatusCounts(string $accountId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT co.status, COUNT(*) AS aggregate
             FROM callback_outbox co INNER JOIN payment_events pe ON pe.id = co.event_id
             WHERE pe.account_id = ? GROUP BY co.status'
        );
        $statement->execute([$accountId]);
        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string)$row['status']] = (int)$row['aggregate'];
        }
        return $counts;
    }

    /** @param array<string, mixed> $data */
    private function matchReviewEvent(string $clientId, int $eventId, array $data): HttpResponse
    {
        $outTradeNo = trim((string)($data['out_trade_no'] ?? ''));
        [$operator, $note] = $this->reviewMetadata($data);
        if (!preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $outTradeNo)) {
            throw new RuntimeException('复核订单号格式不合法');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->isMysql() ? ' FOR UPDATE' : '';
            $eventQuery = $this->pdo->prepare(
                'SELECT pe.*, a.client_id
                 FROM payment_events pe INNER JOIN accounts a ON a.id = pe.account_id
                 WHERE pe.id = ? AND a.client_id = ?' . $lock
            );
            $eventQuery->execute([$eventId, $clientId]);
            $event = $eventQuery->fetch();
            if (!$event) {
                throw new DomainNotFoundException('待复核账单不存在');
            }
            if ($event['status'] === 'MATCHED') {
                $matched = $this->pdo->prepare('SELECT out_trade_no FROM pending_orders WHERE id = ?');
                $matched->execute([$event['matched_order_id']]);
                if (hash_equals((string)($matched->fetchColumn() ?: ''), $outTradeNo)) {
                    $this->pdo->commit();
                    return HttpResponse::json(['accepted' => true, 'duplicate' => true]);
                }
                throw new DomainConflictException('账单已经匹配到其他订单');
            }
            if (!in_array($event['status'], ['REVIEW_REQUIRED', 'UNMATCHED'], true)) {
                throw new DomainConflictException('当前账单状态不允许人工匹配');
            }

            $orderQuery = $this->pdo->prepare(
                'SELECT * FROM pending_orders
                 WHERE client_id = ? AND account_id = ? AND out_trade_no = ?' . $lock
            );
            $orderQuery->execute([$clientId, $event['account_id'], $outTradeNo]);
            $order = $orderQuery->fetch();
            if (!$order || $order['status'] !== 'PENDING'
                || !hash_equals((string)$order['amount'], (string)$event['amount'])
                || (int)$order['created_at'] > (int)$event['occurred_at'] + 10
                || (int)$order['expires_at'] < (int)$event['occurred_at']) {
                throw new DomainConflictException('订单与账单的账号、金额、状态或到账时间窗不匹配');
            }

            $updated = $this->pdo->prepare('UPDATE pending_orders SET status = ? WHERE id = ? AND status = ?');
            $updated->execute(['MATCHED', $order['id'], 'PENDING']);
            if ($updated->rowCount() !== 1) {
                throw new DomainConflictException('订单已被其他到账事件占用');
            }
            $this->pdo->prepare('UPDATE payment_events SET status = ?, matched_order_id = ? WHERE id = ?')
                ->execute(['MATCHED', $order['id'], $eventId]);
            $this->insertReviewAudit($eventId, $clientId, 'MATCH', (int)$order['id'], $operator, $note);
            $payload = [
                'source_bill_id' => (string)$event['source_bill_id'],
                'out_trade_no' => (string)$order['out_trade_no'],
                'money' => number_format((float)$event['amount'], 2, '.', ''),
                'occurred_at' => (int)$event['occurred_at'],
                'timestamp' => time(),
                'nonce' => bin2hex(random_bytes(16)),
            ];
            $this->pdo->prepare(
                'INSERT INTO callback_outbox(
                    client_id, event_id, callback_url, payload_json, status,
                    attempts, next_attempt_at, last_error, created_at
                 ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $clientId, $eventId, $this->clientCallbackUrl($clientId),
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'PENDING', 0, time(), '', time(),
            ]);
            $this->pdo->commit();
            return HttpResponse::json(['accepted' => true, 'duplicate' => false]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data */
    private function ignoreReviewEvent(string $clientId, int $eventId, array $data): HttpResponse
    {
        [$operator, $note] = $this->reviewMetadata($data);
        if ($note === '') {
            throw new RuntimeException('忽略账单必须填写原因');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->isMysql() ? ' FOR UPDATE' : '';
            $query = $this->pdo->prepare(
                'SELECT pe.status FROM payment_events pe
                 INNER JOIN accounts a ON a.id = pe.account_id
                 WHERE pe.id = ? AND a.client_id = ?' . $lock
            );
            $query->execute([$eventId, $clientId]);
            $status = $query->fetchColumn();
            if ($status === false) {
                throw new DomainNotFoundException('待复核账单不存在');
            }
            if ($status === 'IGNORED') {
                $this->pdo->commit();
                return HttpResponse::json(['accepted' => true, 'duplicate' => true]);
            }
            if (!in_array($status, ['REVIEW_REQUIRED', 'UNMATCHED'], true)) {
                throw new DomainConflictException('当前账单状态不允许忽略');
            }
            $this->pdo->prepare('UPDATE payment_events SET status = ? WHERE id = ?')
                ->execute(['IGNORED', $eventId]);
            $this->insertReviewAudit($eventId, $clientId, 'IGNORE', null, $operator, $note);
            $this->pdo->commit();
            return HttpResponse::json(['accepted' => true, 'duplicate' => false]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $data @return array{string,string} */
    private function reviewMetadata(array $data): array
    {
        $operator = trim((string)($data['operator'] ?? ''));
        $note = trim((string)($data['note'] ?? ''));
        if (!preg_match('/^[\p{L}\p{N}_.:@-]{1,64}$/u', $operator) || mb_strlen($note) > 500) {
            throw new RuntimeException('复核操作人或备注格式不合法');
        }
        return [$operator, $note];
    }

    private function insertReviewAudit(
        int $eventId,
        string $clientId,
        string $action,
        ?int $orderId,
        string $operator,
        string $note
    ): void {
        $this->pdo->prepare(
            'INSERT INTO payment_event_reviews(event_id, client_id, action, order_id, operator, note, created_at)
             VALUES(?, ?, ?, ?, ?, ?, ?)'
        )->execute([$eventId, $clientId, $action, $orderId, $operator, $note, time()]);
    }

    private function capabilityMessage(string $status): string
    {
        return match ($status) {
            'RECEIPT_AVAILABLE' => '账号已开通收款单功能',
            'RECEIPT_NOT_OPENED' => '账号明确未开通收款单功能，只能使用小账本',
            'BOOK_AVAILABLE' => '账号可使用小账本监控',
            'REAUTH_REQUIRED' => '账号授权已失效，需要重新扫码',
            'TEMPORARY_ERROR' => '采集端暂时无法判断账号能力',
            default => '账号能力尚未确定',
        };
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private static function randomId(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private static function money(mixed $value): string
    {
        if (!is_numeric($value) || (float)$value <= 0 || (float)$value > 50000) {
            throw new RuntimeException('金额不合法');
        }
        return number_format((float)$value, 2, '.', '');
    }
}

final class DomainConflictException extends RuntimeException
{
}

final class DomainNotFoundException extends RuntimeException
{
}
