<?php

declare(strict_types=1);

namespace WxpayClerk;

final class PaymentMatchingService
{
    public function __construct(
        private readonly Database $database,
        private readonly OrderRepository $orders,
        private readonly PaymentEventRepository $events,
        private readonly ReviewRepository $reviews,
        private readonly OutboxRepository $outbox,
        private readonly OrderMatcher $matcher,
        private readonly int $matchWindowSeconds
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @return array{event_id: int, status: string, out_trade_no: ?string}
     */
    public function ingest(array $event): array
    {
        return $this->database->transaction(function () use ($event): array {
            $stored = $this->events->createOrFind($event);
            if (!$stored['created']) {
                return $this->result($stored['event']);
            }

            $paymentEvent = $stored['event'];
            $occurredAt = (int) $paymentEvent['occurred_at'];
            $candidates = array_values(array_filter(
                $this->orders->candidates(
                    (string) $paymentEvent['account_id'],
                    (string) $paymentEvent['amount'],
                    $occurredAt
                ),
                fn (array $order): bool => $occurredAt - (int) $order['created_at'] <= $this->matchWindowSeconds
            ));
            $decision = $this->matcher->decide($candidates, (string) $paymentEvent['remark']);
            $eventId = (int) $paymentEvent['id'];

            if ($decision['status'] !== 'MATCHED') {
                $this->events->markStatus($eventId, $decision['status']);
                $paymentEvent['status'] = $decision['status'];
                $this->reviews->create($paymentEvent, $decision['reason']);
                return ['event_id' => $eventId, 'status' => $decision['status'], 'out_trade_no' => null];
            }

            $order = $decision['order'];
            $outTradeNo = (string) $order['out_trade_no'];
            $this->completeMatch($paymentEvent, $outTradeNo);
            return ['event_id' => $eventId, 'status' => 'MATCHED', 'out_trade_no' => $outTradeNo];
        });
    }

    /** @return array{event_id: int, status: string, out_trade_no: ?string} */
    public function matchReview(int $eventId, string $outTradeNo, string $operator, string $note): array
    {
        return $this->database->transaction(function () use ($eventId, $outTradeNo, $operator, $note): array {
            $event = $this->events->find($eventId);
            if ($event === null) {
                throw new ApiException(404, '到账事件不存在');
            }
            if ($event['status'] === 'MATCHED') {
                if (hash_equals((string) $event['out_trade_no'], $outTradeNo)) {
                    return $this->result($event);
                }
                throw new ApiException(409, '到账事件已匹配其他订单');
            }
            if (!in_array($event['status'], ['REVIEW_REQUIRED', 'UNMATCHED'], true)) {
                throw new ApiException(409, '到账事件当前状态不可人工匹配');
            }

            $order = $this->orders->find($outTradeNo);
            if ($order === null) {
                throw new ApiException(404, '订单不存在');
            }
            $occurredAt = (int) $event['occurred_at'];
            $valid = $order['status'] === 'PENDING'
                && hash_equals((string) $order['account_id'], (string) $event['account_id'])
                && hash_equals((string) $order['amount'], (string) $event['amount'])
                && (int) $order['created_at'] <= $occurredAt
                && (int) $order['expires_at'] >= $occurredAt;
            if (!$valid) {
                throw new ApiException(409, '订单与到账事件的账号、金额、时间或状态不匹配');
            }

            $this->completeMatch($event, $outTradeNo);
            if (!$this->reviews->recordResolutionByPaymentEvent(
                $eventId,
                'MATCHED',
                $outTradeNo,
                $operator,
                $note
            )) {
                throw new ApiException(409, '人工复核记录已被处理');
            }
            return ['event_id' => $eventId, 'status' => 'MATCHED', 'out_trade_no' => $outTradeNo];
        });
    }

    /** @return array{event_id: int, status: string, out_trade_no: ?string} */
    public function ignoreReview(int $eventId, string $operator, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException(400, '忽略到账事件必须填写原因');
        }
        return $this->database->transaction(function () use ($eventId, $operator, $reason): array {
            $event = $this->events->find($eventId);
            if ($event === null) {
                throw new ApiException(404, '到账事件不存在');
            }
            if ($event['status'] === 'IGNORED') {
                return $this->result($event);
            }
            if (!in_array($event['status'], ['REVIEW_REQUIRED', 'UNMATCHED'], true)) {
                throw new ApiException(409, '到账事件当前状态不可忽略');
            }
            $this->events->markStatus($eventId, 'IGNORED');
            if (!$this->reviews->recordResolutionByPaymentEvent(
                $eventId,
                'IGNORED',
                '',
                $operator,
                $reason
            )) {
                throw new ApiException(409, '人工复核记录已被处理');
            }
            return ['event_id' => $eventId, 'status' => 'IGNORED', 'out_trade_no' => null];
        });
    }

    /** @param array<string, mixed> $event */
    private function completeMatch(array $event, string $outTradeNo): void
    {
        $eventId = (int) $event['id'];
        if (!$this->orders->markMatched(
            $outTradeNo,
            $eventId,
            (string) $event['source_bill_id'],
            (int) $event['occurred_at']
        )) {
            throw new ApiException(409, '订单已被其他到账事件占用');
        }
        $this->events->markStatus($eventId, 'MATCHED', $outTradeNo);
        $this->outbox->create($eventId, $outTradeNo, (int) $event['received_at']);
    }

    /** @param array<string, mixed> $event @return array{event_id: int, status: string, out_trade_no: ?string} */
    private function result(array $event): array
    {
        return [
            'event_id' => (int) $event['id'],
            'status' => (string) $event['status'],
            'out_trade_no' => isset($event['out_trade_no']) && $event['out_trade_no'] !== ''
                ? (string) $event['out_trade_no']
                : null,
        ];
    }
}
