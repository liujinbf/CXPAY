<?php

declare(strict_types=1);

namespace app\service;

use app\model\Merchant;
use app\model\Order;
use Exception;

/**
 * 商户费率、清算扣率与 VIP 升级计算服务
 */
class MerchantService
{
    /**
     * 根据商户 VIP 级别计算交易手续费与实际到账金额
     *
     * @param int $merchantId 商户ID
     * @param float $amount 交易总金额
     * @return array [fee => float, net_amount => float, rate => float]
     */
    public function calculateFee(int $merchantId, float $amount): array
    {
        $merchant = Merchant::find($merchantId);
        if (!$merchant) {
            throw new Exception("商户不存在 [PID: {$merchantId}]");
        }

        // 默认商户费率 (如 0.02 = 2%)
        $rate = (float)($merchant->rate ?? 0.02);

        // 如果商户拥有 VIP 身份则自动享受折扣
        if (!empty($merchant->vip_level)) {
            if ($merchant->vip_level === 1) {
                $rate = 0.018; // VIP 月卡 1.8%
            } elseif ($merchant->vip_level === 2) {
                $rate = 0.015; // VIP 年卡 1.5%
            }
        }

        $fee = round($amount * $rate, 2);
        $netAmount = round($amount - $fee, 2);

        return [
            'rate'       => $rate,
            'fee'        => $fee,
            'net_amount' => max(0.00, $netAmount),
        ];
    }
}
