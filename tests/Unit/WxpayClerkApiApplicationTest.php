<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\WxpayClerkDatabaseTestCase;
use WxpayClerk\AccountRepository;
use WxpayClerk\ApiApplication;
use WxpayClerk\AuthSessionManager;
use WxpayClerk\AuthSessionRepository;
use WxpayClerk\Database;
use WxpayClerk\GeweApiClientInterface;
use WxpayClerk\HttpResponse;
use WxpayClerk\NonceRepository;
use WxpayClerk\OrderMatcher;
use WxpayClerk\OrderRepository;
use WxpayClerk\OutboxRepository;
use WxpayClerk\PaymentEventRepository;
use WxpayClerk\PaymentMatchingService;
use WxpayClerk\PaymentNotificationParser;
use WxpayClerk\RequestAuthenticator;
use WxpayClerk\ReviewRepository;
use WxpayClerk\SignatureHelper;
use WxpayClerk\WechatWebhookHandler;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';

final class WxpayClerkApiApplicationTest extends WxpayClerkDatabaseTestCase
{
    private const CLIENT_ID = 'client_api';
    private const CLIENT_SECRET = 'ssssssssssssssssssssssssssssssss';
    private const CALLBACK_SECRET = 'cccccccccccccccccccccccccccccccc';
    private const WEBHOOK_TOKEN = 'webhook-token-0123456789abcdefghi';

    private Database $database;
    private OrderRepository $orders;
    private PaymentEventRepository $events;
    private OutboxRepository $outbox;
    private AccountRepository $accounts;
    private PaymentMatchingService $matching;
    private FakeGeweApiClient $gewe;
    private ApiApplication $app;
    private int $nonceSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Database($this->databasePath);
        $this->orders = new OrderRepository($this->database->pdo());
        $this->events = new PaymentEventRepository($this->database->pdo());
        $reviews = new ReviewRepository($this->database->pdo());
        $this->outbox = new OutboxRepository($this->database->pdo());
        $this->accounts = new AccountRepository($this->database->pdo());
        $sessions = new AuthSessionRepository($this->database->pdo());
        $this->matching = new PaymentMatchingService(
            $this->database,
            $this->orders,
            $this->events,
            $reviews,
            $this->outbox,
            new OrderMatcher(),
            600
        );
        $this->gewe = new FakeGeweApiClient();
        $authSessions = new AuthSessionManager(
            $this->gewe,
            $sessions,
            $this->accounts,
            'https://clerk.example.com/wechat/message/' . self::WEBHOOK_TOKEN,
            300
        );
        $webhook = new WechatWebhookHandler(
            new PaymentNotificationParser(),
            $this->matching,
            $this->accounts,
            ''
        );
        $signer = new SignatureHelper(self::CLIENT_ID, self::CLIENT_SECRET, self::CALLBACK_SECRET);
        $this->app = new ApiApplication(
            new RequestAuthenticator(
                self::CLIENT_ID,
                self::CLIENT_SECRET,
                new NonceRepository($this->database->pdo())
            ),
            $signer,
            $this->orders,
            $this->events,
            $this->outbox,
            $reviews,
            $this->accounts,
            $this->matching,
            $authSessions,
            $this->gewe,
            $webhook,
            self::WEBHOOK_TOKEN,
            ['127.0.0.1'],
            []
        );
    }

    protected function tearDown(): void
    {
        unset(
            $this->app,
            $this->gewe,
            $this->matching,
            $this->accounts,
            $this->outbox,
            $this->events,
            $this->orders,
            $this->database
        );
        parent::tearDown();
    }

    public function testOrderQueryReturnsMatchedPaymentWhileCallbackIsPending(): void
    {
        $this->orders->register('acc_1', 'ch_1', 'CX3001', '9.99', 1700000600, 1700000000);
        $this->matching->ingest($this->event([
            'source_bill_id' => 'bill_query',
            'amount' => '9.99',
        ]));

        $response = $this->signedRequest('GET', '/v1/orders/CX3001', '', 1700000200);

        self::assertSame(200, $response->status);
        self::assertSame([
            'paid' => true,
            'out_trade_no' => 'CX3001',
            'amount' => '9.99',
            'occurred_at' => 1700000100,
            'callback_status' => 'PENDING',
        ], json_decode($response->body, true));
        self::assertTrue($this->responseSignatureIsValid($response));
    }

    public function testRegisterOrderReturnsExplicitAcceptance(): void
    {
        $body = json_encode([
            'account_id' => 'acc_1',
            'out_trade_no' => 'CX-REGISTER-1',
            'amount' => '1.23',
            'expires_at' => 1700000300,
            'mode' => 'clerk',
        ], JSON_THROW_ON_ERROR);

        $response = $this->signedRequest('POST', '/v1/orders', $body, 1700000000);

        self::assertSame(200, $response->status);
        self::assertSame(true, json_decode($response->body, true)['accepted']);
        self::assertSame('PENDING', $this->orders->find('CX-REGISTER-1')['status']);
    }

    public function testManualMatchRouteCreatesOutboxTask(): void
    {
        $unmatched = $this->matching->ingest($this->event(['source_bill_id' => 'bill_api_manual']));
        $this->orders->register('acc_1', 'ch_1', 'CX-API-MANUAL', '8.88', 1700000600, 1700000000);
        $body = json_encode([
            'out_trade_no' => 'CX-API-MANUAL',
            'operator' => 'admin_1',
            'note' => '后台人工确认',
        ], JSON_THROW_ON_ERROR);

        $pending = $this->signedRequest('GET', '/v1/review/events', '', 1700000199);
        self::assertSame(1, json_decode($pending->body, true)['count']);

        $response = $this->signedRequest(
            'POST',
            '/v1/review/events/' . $unmatched['event_id'] . '/match',
            $body,
            1700000200
        );

        self::assertSame(200, $response->status);
        self::assertSame('MATCHED', json_decode($response->body, true)['status']);
        self::assertSame(1, $this->countRows('callback_outbox'));
        self::assertTrue($this->responseSignatureIsValid($response));
    }

    public function testDuplicateWebhookCreatesOneEventAndOneOutboxTask(): void
    {
        $this->accounts->save('acc_1', '店员', 'gewe_app_1', 'ONLINE', 1700000000);
        $this->orders->register('acc_1', 'ch_1', 'CX-WEBHOOK', '8.88', 1700000600, 1700000000);
        $body = json_encode($this->validWebhook('stable_bill_webhook'), JSON_THROW_ON_ERROR);

        for ($index = 0; $index < 10; $index++) {
            $response = $this->app->handle(
                'POST',
                '/wechat/message/' . self::WEBHOOK_TOKEN,
                [],
                $body,
                '127.0.0.1',
                1700000110
            );
            self::assertSame(200, $response->status);
        }

        self::assertSame(1, $this->countRows('payment_events'));
        self::assertSame(1, $this->countRows('callback_outbox'));
        self::assertSame('MATCHED', $this->orders->find('CX-WEBHOOK')['status']);
    }

    public function testWebhookWithoutMessageIdIsDeduplicatedIntoReviewOnly(): void
    {
        $this->accounts->save('acc_1', '店员', 'gewe_app_1', 'ONLINE', 1700000000);
        $this->orders->register('acc_1', 'ch_1', 'CX-NO-MSG-ID', '8.88', 1700000600, 1700000000);
        $payload = $this->validWebhook('unused');
        unset($payload['Data']['NewMsgId']);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        for ($index = 0; $index < 2; $index++) {
            $this->app->handle(
                'POST',
                '/wechat/message/' . self::WEBHOOK_TOKEN,
                [],
                $body,
                '127.0.0.1',
                1700000110
            );
        }

        self::assertSame(1, $this->countRows('payment_events'));
        self::assertSame(1, $this->countRows('review_events'));
        self::assertSame(0, $this->countRows('callback_outbox'));
        self::assertSame('PENDING', $this->orders->find('CX-NO-MSG-ID')['status']);
    }

    public function testAuthenticatedBusinessErrorResponseIsSigned(): void
    {
        $body = json_encode([
            'account_id' => 'acc_1',
            'out_trade_no' => 'CX-BAD-AMOUNT',
            'amount' => '0.001',
            'expires_at' => 1700000600,
        ], JSON_THROW_ON_ERROR);

        $response = $this->signedRequest('POST', '/v1/orders', $body, 1700000000);

        self::assertSame(400, $response->status);
        self::assertTrue($this->responseSignatureIsValid($response));
    }

    public function testWebhookRejectsWrongTokenAndUntrustedIp(): void
    {
        $body = json_encode($this->validWebhook('bill_rejected'), JSON_THROW_ON_ERROR);

        $wrongToken = $this->app->handle(
            'POST',
            '/wechat/message/' . str_repeat('x', 32),
            [],
            $body,
            '127.0.0.1',
            1700000110
        );
        $wrongIp = $this->app->handle(
            'POST',
            '/wechat/message/' . self::WEBHOOK_TOKEN,
            [],
            $body,
            '10.0.0.8',
            1700000110
        );

        self::assertSame(401, $wrongToken->status);
        self::assertSame(401, $wrongIp->status);
        self::assertArrayNotHasKey('X-CXPAY-Signature', $wrongToken->headers);
        self::assertSame(0, $this->countRows('payment_events'));
    }

    public function testCapabilitiesAndOperationsRoutesUseCurrentAccountState(): void
    {
        $this->accounts->save('acc_ops', '运维店员', 'gewe_ops', 'OFFLINE', 1700000000);

        $capabilities = $this->signedRequest(
            'GET',
            '/v1/accounts/acc_ops/capabilities',
            '',
            1700000000
        );
        $operations = $this->signedRequest('GET', '/v1/ops/status', '', 1700000001);

        self::assertSame('RECEIPT_AVAILABLE', json_decode($capabilities->body, true)['status']);
        self::assertSame(1, json_decode($operations->body, true)['online_count']);
        self::assertSame(0, json_decode($operations->body, true)['outbox']['failed_count']);
    }

    public function testAuthSessionRoutesPersistConfirmedAccount(): void
    {
        $createBody = json_encode(['reference' => 'channel_1'], JSON_THROW_ON_ERROR);
        $created = $this->signedRequest('POST', '/v1/auth-sessions', $createBody, 1700000000);
        $sessionId = (string) json_decode($created->body, true)['session_id'];

        $confirmed = $this->signedRequest('GET', '/v1/auth-sessions/' . $sessionId, '', 1700000001);

        self::assertSame(200, $created->status);
        self::assertSame('CONFIRMED', json_decode($confirmed->body, true)['status']);
        self::assertSame('wxid_test', json_decode($confirmed->body, true)['account_id']);
        self::assertSame('gewe_login_app', $this->accounts->find('wxid_test')['gewe_app_id']);
    }

    private function signedRequest(string $method, string $path, string $body, int $now): HttpResponse
    {
        $nonce = 'nonce-api-request-' . str_pad((string) ++$this->nonceSequence, 6, '0', STR_PAD_LEFT);
        $canonical = implode("\n", [
            $method,
            $path,
            (string) $now,
            $nonce,
            hash('sha256', $body),
        ]);
        return $this->app->handle($method, $path, [
            'x-cxpay-client' => self::CLIENT_ID,
            'x-cxpay-timestamp' => (string) $now,
            'x-cxpay-nonce' => $nonce,
            'x-cxpay-signature' => hash_hmac('sha256', $canonical, self::CLIENT_SECRET),
        ], $body, '127.0.0.1', $now);
    }

    private function responseSignatureIsValid(HttpResponse $response): bool
    {
        return hash_equals(
            hash_hmac('sha256', $response->body, self::CALLBACK_SECRET),
            (string) ($response->headers['X-CXPAY-Signature'] ?? '')
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'account_id' => 'acc_1',
            'source_bill_id' => 'bill_api_default',
            'amount' => '8.88',
            'payer_name' => '张三',
            'remark' => '',
            'occurred_at' => 1700000100,
            'received_at' => 1700000110,
            'raw_hash' => str_repeat('e', 64),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function validWebhook(string $billId): array
    {
        return [
            'TypeName' => 'AddMsg',
            'Appid' => 'gewe_app_1',
            'Data' => [
                'MsgType' => 1,
                'FromUserName' => ['string' => 'fmessage'],
                'Content' => ['string' => '收款助手成功收款8.88元，付款人：张三'],
                'CreateTime' => 1700000100,
                'NewMsgId' => $billId,
            ],
        ];
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}

final class FakeGeweApiClient implements GeweApiClientInterface
{
    public function createLoginSession(): array
    {
        return ['appid' => 'gewe_login_app', 'qr_url' => 'https://qr.example/test', 'uuid' => 'uuid_test'];
    }

    public function checkLoginStatus(string $appId, string $uuid): array
    {
        return ['status' => 'CONFIRMED', 'wxid' => 'wxid_test', 'nickname' => '测试店员'];
    }

    public function getAccountStatus(string $appId): array
    {
        return ['online' => true, 'nickname' => '测试店员'];
    }

    public function setCallback(string $appId, string $callbackUrl): void
    {
    }
}
