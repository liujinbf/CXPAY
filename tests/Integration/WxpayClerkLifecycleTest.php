<?php

declare(strict_types=1);

namespace Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Support\WxpayClerkDatabaseTestCase;
use WxpayClerk\AccountRepository;
use WxpayClerk\ApiApplication;
use WxpayClerk\AuthSessionManager;
use WxpayClerk\AuthSessionRepository;
use WxpayClerk\CallbackPayloadSigner;
use WxpayClerk\CallbackTransportInterface;
use WxpayClerk\Database;
use WxpayClerk\GeweApiClientInterface;
use WxpayClerk\NonceRepository;
use WxpayClerk\OrderMatcher;
use WxpayClerk\OrderRepository;
use WxpayClerk\OutboxDispatcher;
use WxpayClerk\OutboxRepository;
use WxpayClerk\PaymentEventRepository;
use WxpayClerk\PaymentMatchingService;
use WxpayClerk\PaymentNotificationParser;
use WxpayClerk\RequestAuthenticator;
use WxpayClerk\ReviewRepository;
use WxpayClerk\SignatureHelper;
use WxpayClerk\WechatWebhookHandler;
use plugin\cxpay\wxpay_clerk_adapter\Driver;
use plugin\cxpay\wxpay_clerk_adapter\ProviderClient;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';
require_once __DIR__ . '/../../plugins-src/wxpay-clerk-adapter/src/Driver.php';

final class WxpayClerkLifecycleTest extends WxpayClerkDatabaseTestCase
{
    private const CLIENT_ID = 'client_lifecycle';
    private const CLIENT_SECRET = 'ssssssssssssssssssssssssssssssss';
    private const CALLBACK_SECRET = 'cccccccccccccccccccccccccccccccc';
    private const WEBHOOK_TOKEN = 'webhook-lifecycle-0123456789abcdef';

    private Database $database;

    protected function tearDown(): void
    {
        unset($this->database);
        parent::tearDown();
    }

    public function testRegisteredOrderSurvivesDuplicateWebhookAndCallbackOutage(): void
    {
        $this->database = new Database($this->databasePath);
        $orders = new OrderRepository($this->database->pdo());
        $events = new PaymentEventRepository($this->database->pdo());
        $reviews = new ReviewRepository($this->database->pdo());
        $outbox = new OutboxRepository($this->database->pdo());
        $accounts = new AccountRepository($this->database->pdo());
        $matching = new PaymentMatchingService(
            $this->database,
            $orders,
            $events,
            $reviews,
            $outbox,
            new OrderMatcher(),
            600
        );
        $gewe = new LifecycleGeweApiClient();
        $application = new ApiApplication(
            new RequestAuthenticator(
                self::CLIENT_ID,
                self::CLIENT_SECRET,
                new NonceRepository($this->database->pdo())
            ),
            new SignatureHelper(self::CLIENT_ID, self::CLIENT_SECRET, self::CALLBACK_SECRET),
            $orders,
            $events,
            $outbox,
            $reviews,
            $accounts,
            $matching,
            new AuthSessionManager(
                $gewe,
                new AuthSessionRepository($this->database->pdo()),
                $accounts,
                'https://clerk.example.com/wechat/message/' . self::WEBHOOK_TOKEN,
                300
            ),
            $gewe,
            new WechatWebhookHandler(new PaymentNotificationParser(), $matching, $accounts, ''),
            self::WEBHOOK_TOKEN,
            ['127.0.0.1']
        );

        $handler = static function (RequestInterface $request, array $options) use ($application) {
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', $values);
            }
            $response = $application->handle(
                $request->getMethod(),
                $request->getUri()->getPath(),
                $headers,
                (string) $request->getBody(),
                '127.0.0.1',
                time()
            );
            return Create::promiseFor(new Response($response->status, $response->headers, $response->body));
        };
        $driver = new Driver(new ProviderClient(new Client(['handler' => $handler])));
        $config = [
            'monitor_base_url' => 'https://93.184.216.34',
            'account_id' => 'account-e2e-0001',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'callback_secret' => self::CALLBACK_SECRET,
            'qr_url' => 'wxp://lifecycle-test',
        ];
        $accounts->save('account-e2e-0001', '验收店员', 'gewe_e2e', 'ONLINE');
        $now = time();

        $payment = $driver->pay([
            'trade_no' => 'CX-E2E-1001',
            'out_trade_no' => 'MERCHANT-E2E-1001',
            'money' => '6.66',
            'expire_time' => $now + 300,
        ], $config);
        self::assertSame('qrcode', $payment['type']);

        $webhookBody = json_encode([
            'TypeName' => 'AddMsg',
            'Appid' => 'gewe_e2e',
            'Data' => [
                'MsgType' => 1,
                'FromUserName' => ['string' => 'fmessage'],
                'Content' => ['string' => '收款助手成功收款6.66元，付款人：验收用户'],
                'CreateTime' => $now + 1,
                'NewMsgId' => 'stable_bill_e2e_1001',
            ],
        ], JSON_THROW_ON_ERROR);
        for ($index = 0; $index < 10; $index++) {
            $response = $application->handle(
                'POST',
                '/wechat/message/' . self::WEBHOOK_TOKEN,
                [],
                $webhookBody,
                '127.0.0.1',
                $now + 2
            );
            self::assertSame(200, $response->status);
        }
        self::assertSame(1, $this->countRows('payment_events'));
        self::assertSame(1, $this->countRows('callback_outbox'));

        $transport = new LifecycleCallbackTransport($driver, $config);
        $dispatcher = new OutboxDispatcher(
            $outbox,
            new CallbackPayloadSigner(self::CALLBACK_SECRET),
            $transport,
            'https://cxpay.example.com/notify/wxpay_clerk_adapter'
        );
        self::assertTrue($dispatcher->dispatchOne($now + 2));
        self::assertTrue($driver->query('CX-E2E-1001', $config)['paid']);
        self::assertTrue($dispatcher->dispatchOne($now + 7));

        self::assertSame('SENT', $this->outboxStatus());
        self::assertTrue($driver->query('CX-E2E-1001', $config)['paid']);
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function outboxStatus(): string
    {
        return (string) $this->database->pdo()->query('SELECT status FROM callback_outbox LIMIT 1')->fetchColumn();
    }
}

final class LifecycleGeweApiClient implements GeweApiClientInterface
{
    public function createLoginSession(): array { return ['appid' => 'app', 'qr_url' => 'https://qr', 'uuid' => 'uuid']; }
    public function checkLoginStatus(string $appId, string $uuid): array { return ['status' => 'WAITING']; }
    public function getAccountStatus(string $appId): array { return ['online' => true, 'nickname' => '']; }
    public function setCallback(string $appId, string $callbackUrl): void {}
}

final class LifecycleCallbackTransport implements CallbackTransportInterface
{
    private int $attempts = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly Driver $driver, private readonly array $config)
    {
    }

    public function post(string $url, array $fields): array
    {
        if (++$this->attempts === 1) {
            return ['status' => 503, 'body' => 'down'];
        }
        $verified = $this->driver->notify($fields, $this->config);
        return [
            'status' => ($verified['success'] ?? false) ? 200 : 400,
            'body' => ($verified['success'] ?? false) ? 'success' : 'invalid',
        ];
    }
}
