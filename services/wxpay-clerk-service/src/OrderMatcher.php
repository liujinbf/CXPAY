<?php

declare(strict_types=1);

namespace WxpayClerk;

/**
 * 只负责在已通过账号、金额和时间校验的候选订单中做确定性决策。
 */
final class OrderMatcher
{
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

    private function extractOutTradeNoFromRemark(string $remark): ?string
    {
        if (preg_match('/(?:cxpay|order|trx)[:\-_]([A-Za-z0-9_.:-]{4,128})/i', $remark, $matches)) {
            return $matches[1];
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{4,128}$/', $remark)) {
            return $remark;
        }
        return null;
    }
}
