<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\Support\WxpayClerkDatabaseTestCase;
use Throwable;
use WxpayClerk\CallbackPayloadSigner;
use WxpayClerk\CallbackTransportInterface;
use WxpayClerk\CurlCallbackTransport;
use WxpayClerk\Database;
use WxpayClerk\OutboxDispatcher;
use WxpayClerk\OutboxRepository;
use WxpayClerk\PaymentEventRepository;
use WxpayClerk\PublicHttpsUrlGuard;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';

final class WxpayClerkOutboxDispatcherTest extends WxpayClerkDatabaseTestCase
{
    private Database $database;
    private OutboxRepository $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Database($this->databasePath);
        $this->outbox = new OutboxRepository($this->database->pdo());
    }

    protected function tearDown(): void
    {
        unset($this->outbox, $this->database);
        parent::tearDown();
    }

    public function testFailedDeliveryIsRetriedAndEventuallyMarkedSent(): void
    {
        $this->seedOutbox(1700000000);
        $transport = new SequenceCallbackTransport([
            ['status' => 503, 'body' => 'unavailable'],
            ['status' => 200, 'body' => 'success'],
        ]);
        $dispatcher = $this->dispatcher($transport);

        self::assertTrue($dispatcher->dispatchOne(1700000000));
        self::assertSame('PENDING', $this->outboxRow()['status']);
        self::assertSame(1700000005, (int) $this->outboxRow()['next_attempt_at']);
        self::assertFalse($dispatcher->dispatchOne(1700000004));
        self::assertTrue($dispatcher->dispatchOne(1700000005));
        self::assertSame('SENT', $this->outboxRow()['status']);
        self::assertSame(2, (int) $this->outboxRow()['attempts']);
    }

    public function testLeaseExpiryMakesInterruptedTaskClaimableAgain(): void
    {
        $this->seedOutbox(1700000000);

        $first = $this->outbox->claimDue(1700000000, 60);
        $beforeExpiry = $this->outbox->claimDue(1700000059, 60);
        $recovered = $this->outbox->claimDue(1700000061, 60);

        self::assertSame(1, (int) $first['attempts']);
        self::assertNull($beforeExpiry);
        self::assertSame($first['id'], $recovered['id']);
        self::assertSame(2, (int) $recovered['attempts']);
    }

    public function testNonSuccessBodyEventuallyMovesTaskToFailed(): void
    {
        $this->seedOutbox(1700000000);
        $dispatcher = $this->dispatcher(new SequenceCallbackTransport([
            ['status' => 200, 'body' => 'SUCCESS'],
            ['status' => 200, 'body' => 'accepted'],
        ]), 2);

        self::assertTrue($dispatcher->dispatchOne(1700000000));
        self::assertTrue($dispatcher->dispatchOne(1700000005));

        $row = $this->outboxRow();
        self::assertSame('FAILED', $row['status']);
        self::assertSame(2, (int) $row['attempts']);
        self::assertStringContainsString('未确认', (string) $row['last_error']);
    }

    public function testCallbackPayloadMatchesFixedProtocolVector(): void
    {
        $signer = new CallbackPayloadSigner(
            str_repeat('c', 32),
            static fn (): string => '00112233445566778899aabbccddeeff'
        );

        $fields = $signer->fields([
            'source_bill_id' => 'bill_1',
            'out_trade_no' => 'CX1',
            'amount' => '8.88',
            'occurred_at' => 1700000100,
        ], 1700000200);

        self::assertSame('00112233445566778899aabbccddeeff', $fields['nonce']);
        self::assertSame('3c932b433a5590b9e879aa03583a648dac445e329fddc88e9c839afaa3d8f6df', $fields['sign']);
    }

    public function testHttpsGuardRejectsAnyPrivateDnsAnswer(): void
    {
        $guard = new PublicHttpsUrlGuard(
            static fn (string $host): array => ['93.184.216.34', '10.0.0.8']
        );

        $this->expectException(RuntimeException::class);
        $guard->resolve('https://callback.example.com/notify');
    }

    public function testHttpsGuardResolvesPublicAddressForCurlPinning(): void
    {
        $guard = new PublicHttpsUrlGuard(
            static fn (string $host): array => ['93.184.216.34']
        );

        self::assertSame(
            ['host' => 'callback.example.com', 'port' => 443, 'ip' => '93.184.216.34'],
            $guard->resolve('https://callback.example.com/notify')
        );
    }

    public function testCurlTransportRunsGuardBeforeNetworkCall(): void
    {
        $guard = new PublicHttpsUrlGuard(
            static fn (string $host): array => ['127.0.0.1']
        );
        $transport = new CurlCallbackTransport($guard);

        $this->expectException(RuntimeException::class);
        $transport->post('https://localhost.example/notify', ['test' => 'value']);
    }

    private function seedOutbox(int $now): void
    {
        $events = new PaymentEventRepository($this->database->pdo());
        $created = $events->createOrFind([
            'account_id' => 'acc_1',
            'source_bill_id' => 'bill_outbox_1',
            'amount' => '8.88',
            'payer_name' => '张三',
            'remark' => '',
            'occurred_at' => 1700000100,
            'received_at' => $now,
            'raw_hash' => str_repeat('d', 64),
        ]);
        $this->outbox->create((int) $created['event']['id'], 'CX-OUTBOX-1', $now);
    }

    private function dispatcher(CallbackTransportInterface $transport, int $maxAttempts = 12): OutboxDispatcher
    {
        return new OutboxDispatcher(
            $this->outbox,
            new CallbackPayloadSigner(str_repeat('c', 32), static fn (): string => str_repeat('a', 32)),
            $transport,
            'https://cxpay.example.com/notify/wxpay_clerk_adapter',
            $maxAttempts,
            60
        );
    }

    /** @return array<string, mixed> */
    private function outboxRow(): array
    {
        return $this->database->pdo()->query('SELECT * FROM callback_outbox LIMIT 1')->fetch();
    }
}

final class SequenceCallbackTransport implements CallbackTransportInterface
{
    /** @param list<array{status: int, body: string}|Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, array $fields): array
    {
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (!is_array($response)) {
            throw new RuntimeException('测试响应队列为空');
        }
        return $response;
    }
}
