<?php

declare(strict_types=1);

namespace WxpayClerk;

/**
 * 到账匹配核心逻辑。
 *
 * 匹配策略（优先级从高到低）：
 *
 * 1. **备注直接命中**：到账备注包含 out_trade_no（格式匹配），置信度 100%，直接确认。
 * 2. **金额唯一命中**：指定账号下同金额 PENDING 订单恰好只有 1 笔（在时间窗口内），
 *    直接确认。
 * 3. **金额多笔歧义**：同金额有多笔订单，根据配置决定是否自动选最早订单或进入人工审核。
 * 4. **无匹配**：该金额无 PENDING 订单，生成审核事件供人工处理。
 */
final class OrderMatcher
{
    public function __construct(
        private readonly ?OrderStore $store = null,
        private readonly int         $matchWindowSeconds = 600,
        private readonly bool        $autoReviewOnAmbiguous = true
    ) {}

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array{status: string, order: ?array, reason: string}
     */
    public function decide(array $candidates, string $remark): array
    {
        $outTradeNo = $this->extractOutTradeNoFromRemark(trim($remark));
        if ($outTradeNo !== null) {
            foreach ($candidates as $candidate) {
                if (hash_equals((string) $candidate['out_trade_no'], $outTradeNo)) {
                    return ['status' => 'MATCHED', 'order' => $candidate, 'reason' => '备注直接命中'];
                }
            }
        }
        if (count($candidates) === 1) {
            return ['status' => 'MATCHED', 'order' => $candidates[0], 'reason' => '金额唯一命中'];
        }
        if (count($candidates) > 1) {
            return ['status' => 'REVIEW_REQUIRED', 'order' => null, 'reason' => '存在多笔同金额候选订单'];
        }
        return ['status' => 'UNMATCHED', 'order' => null, 'reason' => '没有符合账号、金额和时间的候选订单'];
    }

    /**
     * 匹配一条解析好的到账通知。
     *
     * @param array{
     *   amount: string,
     *   payer_name: string,
     *   remark: string,
     *   occurred_at: int,
     *   source_bill_id: string
     * } $payment
     *
     * @return array{
     *   matched: bool,
     *   out_trade_no?: string,
     *   source_bill_id: string,
     *   review_event_id?: int,
     *   reason: string
     * }
     */
    public function match(string $accountId, array $payment): array
    {
        if ($this->store === null) {
            throw new \LogicException('旧匹配接口缺少 OrderStore');
        }
        $amount      = $payment['amount'];
        $occurredAt  = (int)$payment['occurred_at'];
        $remark      = trim((string)$payment['remark']);
        $payerName   = trim((string)$payment['payer_name']);
        $sourceBillId = trim((string)$payment['source_bill_id']);

        // 清理过期订单（顺带维护）
        $this->store->purgeExpiredOrders();

        // 策略 1：备注中包含可识别的 out_trade_no
        if ($remark !== '') {
            $outTradeNo = $this->extractOutTradeNoFromRemark($remark);
            if ($outTradeNo !== null) {
                $confirmed = $this->store->confirmOrder($outTradeNo, $sourceBillId, $occurredAt);
                if ($confirmed) {
                    return [
                        'matched'      => true,
                        'out_trade_no' => $outTradeNo,
                        'source_bill_id' => $sourceBillId,
                        'reason'       => '备注直接命中',
                    ];
                }
                // 备注命中但订单不存在/已匹配，仍然创建审核事件
            }
        }

        // 策略 2 & 3：按金额 + 时间窗口查询
        // occurred_at 应 >= 订单创建时间，且 <= 创建时间 + matchWindowSeconds
        $createdBefore = $occurredAt; // 订单必须在到账之前创建
        $candidates    = $this->store->findPendingByAmount($accountId, $amount, $createdBefore);

        // 过滤时间窗口：订单创建时间距到账时间不超过 matchWindowSeconds
        $candidates = array_values(array_filter(
            $candidates,
            fn (array $o): bool => ($occurredAt - (int)$o['created_at']) <= $this->matchWindowSeconds
        ));

        if (count($candidates) === 1) {
            // 策略 2：唯一匹配
            $order = $candidates[0];
            $this->store->confirmOrder((string)$order['out_trade_no'], $sourceBillId, $occurredAt);
            return [
                'matched'        => true,
                'out_trade_no'   => (string)$order['out_trade_no'],
                'source_bill_id' => $sourceBillId,
                'reason'         => '金额唯一命中',
            ];
        }

        // 策略 3 / 策略 4：一律进入人工审核，禁止歧义自动核销。
        $reason = count($candidates) > 1 ? "金额 {$amount} 元有 " . count($candidates) . " 笔候选订单，需人工审核" : "金额 {$amount} 元无匹配订单";
        $eventId = $this->store->createReviewEvent(
            $accountId, $amount, $payerName, $remark, $occurredAt, $sourceBillId
        );

        return [
            'matched'         => false,
            'source_bill_id'  => $sourceBillId,
            'review_event_id' => $eventId,
            'reason'          => $reason,
        ];
    }

    /**
     * 从备注字符串中尝试提取 out_trade_no。
     * CXPAY 平台流水号格式：字母+数字+少量特殊符号，长度 4-128。
     */
    private function extractOutTradeNoFromRemark(string $remark): ?string
    {
        // 从较长备注中提取：前缀 "cxpay:" 或 "ORDER:" 等
        if (preg_match('/(?:cxpay|order|trx)[:\-_]([A-Za-z0-9_.:-]{4,128})/i', $remark, $m)) {
            return $m[1];
        }
        // 精确匹配：备注就是 out_trade_no（常见于扫码支付）
        if (preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $remark)) {
            return $remark;
        }
        return null;
    }
}
