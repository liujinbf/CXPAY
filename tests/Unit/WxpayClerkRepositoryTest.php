<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\WxpayClerkDatabaseTestCase;
use WxpayClerk\AccountRepository;
use WxpayClerk\ApiException;
use WxpayClerk\AuthSessionRepository;
use WxpayClerk\Database;
use WxpayClerk\OrderRepository;
use WxpayClerk\PaymentEventRepository;
use WxpayClerk\ReviewRepository;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';

final class WxpayClerkRepositoryTest extends WxpayClerkDatabaseTestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Database($this->databasePath);
    }

    protected function tearDown(): void
    {
        unset($this->database);
        parent::tearDown();
    }

    public function testAccountLookupUsesGeweAppIdIndex(): void
    {
        $accounts = new AccountRepository($this->database->pdo());
        $accounts->save('acc_1', '店员', 'gewe_app_1', 'ONLINE', 1700000000);

        self::assertSame('acc_1', $accounts->findByGeweAppId('gewe_app_1')['id']);
        self::assertSame('ONLINE', $accounts->find('acc_1')['status']);

        $plan = $this->database->pdo()
            ->query("EXPLAIN QUERY PLAN SELECT * FROM accounts WHERE gewe_app_id = 'gewe_app_1' LIMIT 1")
            ->fetchAll();
        self::assertStringContainsString('idx_accounts_gewe_app_id', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    public function testDuplicatePaymentEventReturnsExistingRow(): void
    {
        $events = new PaymentEventRepository($this->database->pdo());
        $input = [
            'account_id' => 'acc_1',
            'source_bill_id' => 'bill_stable_001',
            'amount' => '10.00',
            'payer_name' => '付款人',
            'remark' => '',
            'occurred_at' => 1700000010,
            'received_at' => 1700000020,
            'raw_hash' => str_repeat('a', 64),
        ];

        $first = $events->createOrFind($input);
        $again = $events->createOrFind($input);

        self::assertTrue($first['created']);
        self::assertFalse($again['created']);
        self::assertSame($first['event']['id'], $again['event']['id']);
        self::assertSame('RECEIVED', $again['event']['status']);
    }

    public function testDuplicatePaymentEventWithChangedFactsConflicts(): void
    {
        $events = new PaymentEventRepository($this->database->pdo());
        $input = [
            'account_id' => 'acc_1',
            'source_bill_id' => 'bill_stable_002',
            'amount' => '10.00',
            'payer_name' => '',
            'remark' => '',
            'occurred_at' => 1700000010,
            'received_at' => 1700000020,
            'raw_hash' => str_repeat('b', 64),
        ];
        $events->createOrFind($input);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(409);
        $events->createOrFind(array_merge($input, ['amount' => '11.00']));
    }

    public function testOrderRegistrationIsIdempotentOnlyForSameFacts(): void
    {
        $orders = new OrderRepository($this->database->pdo());

        self::assertSame(
            ['accepted' => true, 'idempotent' => false],
            $orders->register('acc_1', 'ch_1', 'CX1001', '12.30', 1700000600, 1700000000)
        );
        self::assertSame(
            ['accepted' => true, 'idempotent' => true],
            $orders->register('acc_1', 'ch_1', 'CX1001', '12.30', 1700000600, 1700000001)
        );
        self::assertSame('PENDING', $orders->find('CX1001')['status']);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(409);
        $orders->register('acc_1', 'ch_1', 'CX1001', '12.31', 1700000600, 1700000002);
    }

    public function testOrderCandidatesRespectAccountAmountAndPaymentTime(): void
    {
        $orders = new OrderRepository($this->database->pdo());
        $orders->register('acc_1', 'ch_1', 'VALID', '8.88', 1700000600, 1700000000);
        $orders->register('acc_2', 'ch_1', 'OTHER_ACCOUNT', '8.88', 1700000600, 1700000000);
        $orders->register('acc_1', 'ch_1', 'OTHER_AMOUNT', '9.99', 1700000600, 1700000000);
        $orders->register('acc_1', 'ch_1', 'CREATED_AFTER', '8.88', 1700000600, 1700000200);
        $orders->register('acc_1', 'ch_1', 'EXPIRED_BEFORE', '8.88', 1700000050, 1700000000);

        $candidates = $orders->candidates('acc_1', '8.88', 1700000100);

        self::assertSame(['VALID'], array_column($candidates, 'out_trade_no'));
    }

    public function testAuthSessionRepositoryDoesNotReturnExpiredSession(): void
    {
        $sessions = new AuthSessionRepository($this->database->pdo());
        $sessions->create('session_1', 'channel_1', 300, 1700000000);
        self::assertTrue($sessions->update('session_1', 'QR_READY', 'https://qr.example/test', ''));
        self::assertSame('QR_READY', $sessions->findActive('session_1', 1700000299)['status']);
        self::assertNull($sessions->findActive('session_1', 1700000301));
    }

    public function testReviewRepositoryPersistsResolutionAudit(): void
    {
        $reviews = new ReviewRepository($this->database->pdo());
        $eventId = $reviews->create([
            'id' => 7,
            'account_id' => 'acc_1',
            'amount' => '6.66',
            'payer_name' => '付款人',
            'remark' => '',
            'occurred_at' => 1700000100,
            'received_at' => 1700000110,
            'source_bill_id' => 'bill_review_1',
        ], 'REVIEW_REQUIRED');

        self::assertCount(1, $reviews->pending('acc_1'));
        self::assertTrue($reviews->recordResolution($eventId, 'MATCHED', 'CX2001', 'admin', '人工核对', 1700000200));

        $resolved = $reviews->find($eventId);
        self::assertSame('MATCHED', $resolved['status']);
        self::assertSame('admin', $resolved['operator']);
        self::assertSame(1700000200, (int) $resolved['resolved_at']);
        self::assertSame([], $reviews->pending('acc_1'));
    }
}
