<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use plugin\cxpay\wxpay_cloud_adapter\Driver;
use WxMonitorCloud\Authenticator;
use WxMonitorCloud\CloudApplication;
use WxMonitorCloud\Database;
use WxMonitorCloud\HttpResponse;
use WxMonitorCloud\PrincipalKeyManager;
use WxMonitorCloud\SecretVault;

require_once __DIR__ . '/../../plugins-src/wxpay-cloud-adapter/src/Driver.php';

final class WxMonitorCloudApplicationTest extends TestCase
{
    private PDO $pdo;
    private CloudApplication $application;
    private SecretVault $vault;
    private string $clientRequestSecret;
    private string $callbackSecret;
    private string $collectorSecret;

    protected function setUp(): void
    {
        $this->pdo = Database::connect('sqlite::memory:');
        $this->vault = new SecretVault(base64_encode(random_bytes(32)));
        $this->clientRequestSecret = str_repeat('a', 32);
        $this->callbackSecret = str_repeat('b', 32);
        $this->collectorSecret = str_repeat('c', 32);
        $this->insertPrincipal('cxpay-site-01', 'client', $this->clientRequestSecret, $this->callbackSecret);
        $this->insertPrincipal('collector-01', 'collector', $this->collectorSecret, '');
        $this->application = new CloudApplication($this->pdo, new Authenticator($this->pdo, $this->vault));
    }

    public function testRejectsReplayedSignedRequest(): void
    {
        $headers = $this->signedHeaders('client', 'GET', '/v1/auth-sessions/not-found-123456', '', 'fixed-nonce-123456');
        $first = $this->application->handle('GET', '/v1/auth-sessions/not-found-123456', $headers, '');
        $second = $this->application->handle('GET', '/v1/auth-sessions/not-found-123456', $headers, '');

        self::assertSame(404, $first->status);
        self::assertSame(401, $second->status);
        self::assertStringContainsString('重复请求随机数', $second->body);
        self::assertSame(
            '1',
            (string)$this->pdo->query(
                "SELECT request_count FROM principal_activity WHERE principal_id = 'cxpay-site-01'"
            )->fetchColumn()
        );
    }

    public function testAuthorizationCapabilityOrderAndPaymentEventFlow(): void
    {
        $sessionResponse = $this->request('client', 'POST', '/v1/auth-sessions', ['reference' => 'merchant-1001']);
        self::assertSame(201, $sessionResponse->status);
        $session = json_decode($sessionResponse->body, true, 16, JSON_THROW_ON_ERROR);
        $sessionId = $session['session_id'];

        $this->request('collector', 'POST', '/v1/collector/auth-sessions/' . $sessionId, [
            'status' => 'CLAIMED',
        ]);
        $this->request('collector', 'POST', '/v1/collector/auth-sessions/' . $sessionId, [
            'status' => 'QR_READY',
            'qr_url' => 'https://authorized-collector.example/qr/session',
            'message' => '等待用户扫码',
        ]);
        $confirmedResponse = $this->request('collector', 'POST', '/v1/collector/auth-sessions/' . $sessionId, [
            'status' => 'CONFIRMED',
            'capability_status' => 'RECEIPT_NOT_OPENED',
            'capabilities' => ['book' => true, 'receipt' => false],
            'external_ref' => 'authorized-account-ref',
            'display_name' => '测试收款账号',
        ]);
        $accountId = json_decode($confirmedResponse->body, true, 16, JSON_THROW_ON_ERROR)['account_id'];

        $pendingAfterConfirm = $this->request('collector', 'GET', '/v1/collector/auth-sessions/pending');
        $pendingRows = json_decode($pendingAfterConfirm->body, true, 16, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($accountId, $pendingRows[0]['account_id']);
        self::assertSame('CONFIRMED', $pendingRows[0]['status']);
        $this->request('collector', 'POST', '/v1/collector/auth-sessions/' . $sessionId, [
            'status' => 'BOUND',
        ]);
        $clientSession = json_decode(
            $this->request('client', 'GET', '/v1/auth-sessions/' . $sessionId)->body,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('CONFIRMED', $clientSession['status']);
        $pendingAfterBound = json_decode(
            $this->request('collector', 'GET', '/v1/collector/auth-sessions/pending')->body,
            true,
            16,
            JSON_THROW_ON_ERROR
        )['data'];
        self::assertSame([], $pendingAfterBound);

        $capabilities = $this->request('client', 'GET', '/v1/accounts/' . $accountId . '/capabilities');
        $capabilityData = json_decode($capabilities->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('RECEIPT_NOT_OPENED', $capabilityData['status']);
        self::assertSame(
            hash_hmac('sha256', $capabilities->body, $this->callbackSecret),
            $capabilities->headers['X-CXPAY-Signature']
        );

        $outTradeNo = 'CX-ORDER-20260001';
        $occurredAt = time();
        $this->request('client', 'POST', '/v1/orders', [
            'account_id' => $accountId,
            'out_trade_no' => $outTradeNo,
            'amount' => '10.01',
            'expires_at' => $occurredAt + 180,
        ]);
        $eventResponse = $this->request('collector', 'POST', '/v1/collector/events', [
            'account_id' => $accountId,
            'source_bill_id' => 'WX-BILL-CLOUD-001',
            'amount' => '10.01',
            'occurred_at' => $occurredAt,
        ]);
        self::assertSame('MATCHED', json_decode($eventResponse->body, true, 16, JSON_THROW_ON_ERROR)['status']);

        $outbox = $this->pdo->query('SELECT * FROM callback_outbox')->fetch();
        $payload = json_decode((string)$outbox['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        $signedPayload = $payload;
        ksort($signedPayload);
        $payload['sign'] = hash_hmac(
            'sha256',
            http_build_query($signedPayload, '', '&', PHP_QUERY_RFC3986),
            $this->callbackSecret
        );
        $pluginResult = (new Driver())->notify($payload, ['callback_secret' => $this->callbackSecret]);
        self::assertTrue($pluginResult['success']);
        self::assertSame($outTradeNo, $pluginResult['out_trade_no']);
    }

    public function testClaimedAuthorizationSessionIsIsolatedFromOtherCollectors(): void
    {
        $otherSecret = str_repeat('d', 32);
        $this->insertPrincipal('collector-02', 'collector', $otherSecret, '');
        $session = json_decode(
            $this->request('client', 'POST', '/v1/auth-sessions')->body,
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        $sessionId = $session['session_id'];
        $this->request('collector', 'POST', '/v1/collector/auth-sessions/' . $sessionId, [
            'status' => 'CLAIMED',
        ]);
        $this->request('collector', 'POST', '/v1/collector/auth-sessions/' . $sessionId, [
            'status' => 'QR_READY',
            'qr_url' => 'https://authorized-collector.example/qr/session',
        ]);

        $path = '/v1/collector/auth-sessions/pending';
        $otherResponse = $this->application->handle(
            'GET',
            $path,
            $this->signedHeaders('collector', 'GET', $path, '', null, 'collector-02', $otherSecret),
            ''
        );
        $otherTasks = json_decode($otherResponse->body, true, 16, JSON_THROW_ON_ERROR)['data'];

        self::assertSame([], $otherTasks);

        $claimPath = '/v1/collector/auth-sessions/' . $sessionId;
        $claimBody = '{"status":"CLAIMED"}';
        $otherClaim = $this->application->handle(
            'POST',
            $claimPath,
            $this->signedHeaders('collector', 'POST', $claimPath, $claimBody, null, 'collector-02', $otherSecret),
            $claimBody
        );
        self::assertSame(409, $otherClaim->status);
    }

    public function testAmbiguousEventCanBeReviewedMatchedAndCallbackIsQueued(): void
    {
        $accountId = 'wxa_review_account_001';
        $this->insertAccount($accountId);
        $occurredAt = time();
        foreach (['CX-REVIEW-ORDER-001', 'CX-REVIEW-ORDER-002'] as $tradeNo) {
            $this->pdo->prepare(
                'INSERT INTO pending_orders(client_id, account_id, out_trade_no, amount, status, created_at, expires_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?)'
            )->execute(['cxpay-site-01', $accountId, $tradeNo, '28.80', 'PENDING', $occurredAt - 30, $occurredAt + 120]);
        }
        $eventResponse = $this->request('collector', 'POST', '/v1/collector/events', [
            'account_id' => $accountId,
            'source_bill_id' => 'WX-REVIEW-BILL-001',
            'amount' => '28.80',
            'occurred_at' => $occurredAt,
        ]);
        self::assertSame('REVIEW_REQUIRED', json_decode($eventResponse->body, true, 16, JSON_THROW_ON_ERROR)['status']);

        $list = json_decode(
            $this->request('client', 'GET', '/v1/review/events')->body,
            true,
            16,
            JSON_THROW_ON_ERROR
        )['data'];
        self::assertCount(1, $list);
        self::assertCount(2, $list[0]['candidates']);
        $eventId = (int)$list[0]['id'];
        $path = '/v1/review/events/' . $eventId . '/match';
        $matched = $this->request('client', 'POST', $path, [
            'out_trade_no' => 'CX-REVIEW-ORDER-002',
            'operator' => 'admin@example.com',
            'note' => '已核对到账时间和付款备注',
        ]);
        self::assertSame(200, $matched->status);
        self::assertFalse(json_decode($matched->body, true, 16, JSON_THROW_ON_ERROR)['duplicate']);

        $duplicate = $this->request('client', 'POST', $path, [
            'out_trade_no' => 'CX-REVIEW-ORDER-002',
            'operator' => 'admin@example.com',
            'note' => '重复提交',
        ]);
        self::assertTrue(json_decode($duplicate->body, true, 16, JSON_THROW_ON_ERROR)['duplicate']);
        self::assertSame('MATCHED', (string)$this->pdo->query("SELECT status FROM payment_events WHERE id = {$eventId}")->fetchColumn());
        self::assertSame('MATCH', (string)$this->pdo->query("SELECT action FROM payment_event_reviews WHERE event_id = {$eventId}")->fetchColumn());
        self::assertSame('1', (string)$this->pdo->query("SELECT COUNT(*) FROM callback_outbox WHERE event_id = {$eventId}")->fetchColumn());
    }

    public function testUnmatchedEventCanBeIgnoredAndCannotBeMatchedToWrongAmount(): void
    {
        $accountId = 'wxa_review_account_002';
        $this->insertAccount($accountId);
        $occurredAt = time();
        $this->pdo->prepare(
            'INSERT INTO pending_orders(client_id, account_id, out_trade_no, amount, status, created_at, expires_at)
             VALUES(?, ?, ?, ?, ?, ?, ?)'
        )->execute(['cxpay-site-01', $accountId, 'CX-WRONG-AMOUNT-001', '9.99', 'PENDING', $occurredAt - 10, $occurredAt + 60]);
        $this->request('collector', 'POST', '/v1/collector/events', [
            'account_id' => $accountId,
            'source_bill_id' => 'WX-UNMATCHED-BILL-001',
            'amount' => '19.99',
            'occurred_at' => $occurredAt,
        ]);
        $eventId = (int)$this->pdo->lastInsertId();
        $wrongMatch = $this->request('client', 'POST', "/v1/review/events/{$eventId}/match", [
            'out_trade_no' => 'CX-WRONG-AMOUNT-001',
            'operator' => 'admin',
        ]);
        self::assertSame(409, $wrongMatch->status);

        $ignored = $this->request('client', 'POST', "/v1/review/events/{$eventId}/ignore", [
            'operator' => 'admin',
            'note' => '确认不是有效到账',
        ]);
        self::assertSame(200, $ignored->status);
        self::assertSame('IGNORED', (string)$this->pdo->query("SELECT status FROM payment_events WHERE id = {$eventId}")->fetchColumn());
        self::assertSame('0', (string)$this->pdo->query("SELECT COUNT(*) FROM callback_outbox WHERE event_id = {$eventId}")->fetchColumn());

        $duplicate = $this->request('client', 'POST', "/v1/review/events/{$eventId}/ignore", [
            'operator' => 'admin',
            'note' => '重复操作',
        ]);
        self::assertTrue(json_decode($duplicate->body, true, 16, JSON_THROW_ON_ERROR)['duplicate']);
        self::assertSame('1', (string)$this->pdo->query("SELECT COUNT(*) FROM payment_event_reviews WHERE event_id = {$eventId}")->fetchColumn());
    }

    public function testRequestAndResponseKeysCanRotateWithFiniteGracePeriod(): void
    {
        $manager = new PrincipalKeyManager($this->pdo, $this->vault);
        $newRequestSecret = str_repeat('n', 32);
        $manager->rotate('cxpay-site-01', 'request', 60, $newRequestSecret);
        $path = '/v1/auth-sessions/not-found-rotation';

        $oldAccepted = $this->application->handle(
            'GET',
            $path,
            $this->signedHeaders('client', 'GET', $path, '', null, null, $this->clientRequestSecret),
            ''
        );
        self::assertSame(404, $oldAccepted->status);
        $newAccepted = $this->application->handle(
            'GET',
            $path,
            $this->signedHeaders('client', 'GET', $path, '', null, null, $newRequestSecret),
            ''
        );
        self::assertSame(404, $newAccepted->status);

        $this->pdo->exec("UPDATE principal_keys SET expires_at = 1 WHERE key_type = 'request' AND expires_at > 0");
        $oldRejected = $this->application->handle(
            'GET',
            $path,
            $this->signedHeaders('client', 'GET', $path, '', null, null, $this->clientRequestSecret),
            ''
        );
        self::assertSame(401, $oldRejected->status);

        $newResponseSecret = str_repeat('r', 32);
        $manager->rotate('cxpay-site-01', 'response', 60, $newResponseSecret);
        $response = $this->application->handle(
            'GET',
            $path,
            $this->signedHeaders('client', 'GET', $path, '', null, null, $newRequestSecret),
            ''
        );
        self::assertSame(
            hash_hmac('sha256', $response->body, $newResponseSecret),
            $response->headers['X-CXPAY-Signature']
        );
        self::assertNotSame(
            hash_hmac('sha256', $response->body, $this->callbackSecret),
            $response->headers['X-CXPAY-Signature']
        );
    }

    public function testOperationsStatusIsTenantScopedAndReportsCollectorActivity(): void
    {
        $accountId = 'wxa_ops_account_0001';
        $this->insertAccount($accountId);
        $this->insertPrincipal('other-client-01', 'client', str_repeat('x', 32), str_repeat('y', 32));
        $this->pdo->prepare(
            'INSERT INTO accounts(
                id, client_id, collector_id, external_ref, display_name,
                auth_status, capability_status, capabilities_json, updated_at
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'wxa_other_account_001', 'other-client-01', 'collector-01', 'other', '其他租户账号',
            'ACTIVE', 'BOOK_AVAILABLE', '{}', time(),
        ]);
        $this->request('collector', 'POST', '/v1/collector/events', [
            'account_id' => $accountId,
            'source_bill_id' => 'WX-OPS-BILL-0001',
            'amount' => '6.66',
            'occurred_at' => time(),
        ]);

        $response = $this->request('client', 'GET', '/v1/ops/status');
        self::assertSame(200, $response->status);
        $status = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertCount(1, $status['accounts']);
        self::assertSame($accountId, $status['accounts'][0]['id']);
        self::assertSame(1, $status['metrics']['events']['UNMATCHED']);
        self::assertSame(1, $status['accounts'][0]['metrics']['events']['UNMATCHED']);
        self::assertCount(1, $status['collectors']);
        self::assertTrue($status['collectors'][0]['online']);
        self::assertGreaterThan(0, $status['collectors'][0]['last_seen_at']);
        self::assertStringNotContainsString('encrypted_secret', $response->body);
        self::assertStringNotContainsString('request_secret', $response->body);
        self::assertStringNotContainsString('response_secret', $response->body);
    }

    private function request(string $role, string $method, string $path, array $data = []): HttpResponse
    {
        $body = $data === [] ? '' : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $this->application->handle($method, $path, $this->signedHeaders($role, $method, $path, $body), $body);
    }

    /** @return array<string, string> */
    private function signedHeaders(
        string $role,
        string $method,
        string $path,
        string $body,
        ?string $nonce = null,
        ?string $principalId = null,
        ?string $principalSecret = null,
    ): array {
        $timestamp = (string)time();
        $nonce ??= bin2hex(random_bytes(16));
        $secret = $principalSecret ?? ($role === 'collector' ? $this->collectorSecret : $this->clientRequestSecret);
        $signature = hash_hmac(
            'sha256',
            implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]),
            $secret
        );
        return $role === 'collector'
            ? [
                'X-Collector-Id' => $principalId ?? 'collector-01',
                'X-Collector-Timestamp' => $timestamp,
                'X-Collector-Nonce' => $nonce,
                'X-Collector-Signature' => $signature,
            ]
            : [
                'X-CXPAY-Client' => $principalId ?? 'cxpay-site-01',
                'X-CXPAY-Timestamp' => $timestamp,
                'X-CXPAY-Nonce' => $nonce,
                'X-CXPAY-Signature' => $signature,
            ];
    }

    private function insertPrincipal(string $id, string $role, string $requestSecret, string $responseSecret): void
    {
        $this->pdo->prepare(
            'INSERT INTO principals(id, role, request_secret, response_secret, callback_url, status, created_at)
             VALUES(?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $id,
            $role,
            $this->vault->encrypt($requestSecret),
            $responseSecret === '' ? '' : $this->vault->encrypt($responseSecret),
            $role === 'client' ? 'https://cxpay.example/notify/wxpay_cloud_adapter' : '',
            time(),
        ]);
    }

    private function insertAccount(string $accountId): void
    {
        $this->pdo->prepare(
            'INSERT INTO accounts(
                id, client_id, collector_id, external_ref, display_name,
                auth_status, capability_status, capabilities_json, updated_at
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $accountId, 'cxpay-site-01', 'collector-01', 'review-account', '复核测试账号',
            'ACTIVE', 'BOOK_AVAILABLE', '{}', time(),
        ]);
    }
}
