<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDOException;
use Tests\Support\WxpayClerkDatabaseTestCase;
use WxpayClerk\ApiException;
use WxpayClerk\Database;
use WxpayClerk\OrderMatcher;
use WxpayClerk\OrderRepository;
use WxpayClerk\OutboxRepository;
use WxpayClerk\PaymentEventRepository;
use WxpayClerk\PaymentMatchingService;
use WxpayClerk\ReviewRepository;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';

final class WxpayClerkPaymentMatchingServiceTest extends WxpayClerkDatabaseTestCase
{
    private Database $database;
    private OrderRepository $orders;
    private PaymentEventRepository $events;
    private ReviewRepository $reviews;
    private OutboxRepository $outbox;
    private PaymentMatchingService $matching;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Database($this->databasePath);
        $this->orders = new OrderRepository($this->database->pdo());
        $this->events = new PaymentEventRepository($this->database->pdo());
        $this->reviews = new ReviewRepository($this->database->pdo());
        $this->outbox = new OutboxRepository($this->database->pdo());
        $this->matching = new PaymentMatchingService(
            $this->database,
            $this->orders,
            $this->events,
            $this->reviews,
            $this->outbox,
            new OrderMatcher(),
            600
        );
    }

    protected function tearDown(): void
    {
        unset(
            $this->matching,
            $this->outbox,
            $this->reviews,
            $this->events,
            $this->orders,
            $this->database
        );
        parent::tearDown();
    }

    public function testDuplicateEventMatchesOnceAndCreatesOneOutboxTask(): void
    {
        $this->orders->register('acc_1', 'ch_1', 'CX2001', '8.88', 1700000600, 1700000000);
        $event = $this->event(['source_bill_id' => 'bill_2001']);

        $first = $this->matching->ingest($event);
        $again = $this->matching->ingest($event);

        self::assertSame('MATCHED', $first['status']);
        self::assertSame($first, $again);
        self::assertSame('MATCHED', $this->orders->find('CX2001')['status']);
        self::assertSame(1, $this->countRows('payment_events'));
        self::assertSame(1, $this->countRows('callback_outbox'));
    }

    public function testAmbiguousAmountAlwaysRequiresReview(): void
    {
        $this->orders->register('acc_1', 'ch_1', 'CX-A', '8.88', 1700000600, 1700000000);
        $this->orders->register('acc_1', 'ch_1', 'CX-B', '8.88', 1700000600, 1700000001);

        $result = $this->matching->ingest($this->event(['source_bill_id' => 'bill_ambiguous']));

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertNull($result['out_trade_no']);
        self::assertSame('PENDING', $this->orders->find('CX-A')['status']);
        self::assertSame('PENDING', $this->orders->find('CX-B')['status']);
        self::assertSame(1, $this->countRows('review_events'));
        self::assertSame(0, $this->countRows('callback_outbox'));
    }

    public function testExactRemarkChoosesValidatedOrderAmongSameAmountCandidates(): void
    {
        $this->orders->register('acc_1', 'ch_1', 'CX-REMARK-A', '8.88', 1700000600, 1700000000);
        $this->orders->register('acc_1', 'ch_1', 'CX-REMARK-B', '8.88', 1700000600, 1700000001);

        $result = $this->matching->ingest($this->event([
            'source_bill_id' => 'bill_remark',
            'remark' => 'cxpay:CX-REMARK-B',
        ]));

        self::assertSame('MATCHED', $result['status']);
        self::assertSame('CX-REMARK-B', $result['out_trade_no']);
        self::assertSame('PENDING', $this->orders->find('CX-REMARK-A')['status']);
        self::assertSame('MATCHED', $this->orders->find('CX-REMARK-B')['status']);
    }

    public function testManualMatchRevalidatesAndCreatesSameOutboxFlow(): void
    {
        $unmatched = $this->matching->ingest($this->event(['source_bill_id' => 'bill_manual']));
        $this->orders->register('acc_1', 'ch_1', 'CX-MANUAL', '8.88', 1700000600, 1700000000);

        $matched = $this->matching->matchReview(
            $unmatched['event_id'],
            'CX-MANUAL',
            'operator_1',
            '已核对付款人'
        );
        $again = $this->matching->matchReview(
            $unmatched['event_id'],
            'CX-MANUAL',
            'operator_1',
            '重复提交'
        );

        self::assertSame('MATCHED', $matched['status']);
        self::assertSame($matched, $again);
        self::assertSame(1, $this->countRows('callback_outbox'));
        self::assertSame('MATCHED', $this->reviews->findByPaymentEvent($unmatched['event_id'])['status']);
    }

    public function testManualMatchRejectsAmountMismatchWithoutChangingState(): void
    {
        $unmatched = $this->matching->ingest($this->event(['source_bill_id' => 'bill_bad_amount']));
        $this->orders->register('acc_1', 'ch_1', 'CX-WRONG-AMOUNT', '9.99', 1700000600, 1700000000);

        try {
            $this->matching->matchReview($unmatched['event_id'], 'CX-WRONG-AMOUNT', 'operator_1', '');
            self::fail('金额不一致时人工匹配必须失败');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->status);
        }

        self::assertSame('PENDING', $this->orders->find('CX-WRONG-AMOUNT')['status']);
        self::assertSame(0, $this->countRows('callback_outbox'));
    }

    public function testIgnoreReviewRequiresReasonAndRecordsAudit(): void
    {
        $unmatched = $this->matching->ingest($this->event(['source_bill_id' => 'bill_ignore']));

        try {
            $this->matching->ignoreReview($unmatched['event_id'], 'operator_1', '');
            self::fail('忽略到账事件必须填写原因');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status);
        }

        $ignored = $this->matching->ignoreReview($unmatched['event_id'], 'operator_1', '非本平台订单');

        self::assertSame('IGNORED', $ignored['status']);
        self::assertSame('IGNORED', $this->reviews->findByPaymentEvent($unmatched['event_id'])['status']);
        self::assertSame(0, $this->countRows('callback_outbox'));
    }

    public function testOutboxInsertFailureRollsBackEventAndOrder(): void
    {
        $this->orders->register('acc_1', 'ch_1', 'CX-ROLLBACK', '8.88', 1700000600, 1700000000);
        $this->database->pdo()->exec(<<<'SQL'
            CREATE TRIGGER reject_outbox BEFORE INSERT ON callback_outbox
            BEGIN
                SELECT RAISE(ABORT, 'outbox unavailable');
            END
            SQL);

        try {
            $this->matching->ingest($this->event(['source_bill_id' => 'bill_rollback']));
            self::fail('发件箱写入失败必须让整个匹配事务失败');
        } catch (PDOException $exception) {
            self::assertStringContainsString('outbox unavailable', $exception->getMessage());
        }

        self::assertSame('PENDING', $this->orders->find('CX-ROLLBACK')['status']);
        self::assertSame(0, $this->countRows('payment_events'));
        self::assertSame(0, $this->countRows('callback_outbox'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'account_id' => 'acc_1',
            'source_bill_id' => 'bill_default',
            'amount' => '8.88',
            'payer_name' => '张三',
            'remark' => '',
            'occurred_at' => 1700000100,
            'received_at' => 1700000110,
            'raw_hash' => str_repeat('b', 64),
        ], $overrides);
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
