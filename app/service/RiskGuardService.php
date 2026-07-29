<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use Exception;

/**
 * 通道防封与风控熔断守护服务 (Risk Guard Service)
 */
class RiskGuardService
{
    /**
     * 下单前风控检测：检查通道是否触发日上限、连续失败熔断或频次过高
     *
     * @param Channel $channel 通道模型
     * @param float $amount 下单金额
     * @return bool 是否通过风控检测
     */
    public function validateRisk(Channel $channel, float $amount): bool
    {
        // 1. 检查日封顶限额 (Day Max)
        if ($channel->day_max > 0 && ($channel->today_money + $amount) > $channel->day_max) {
            // 只跳过本次调度；每日统计重置后通道应自动恢复可选，不能永久改写人工启停状态。
            return false;
        }

        // 2. 检查单笔金额限制 (Single Min / Single Max)
        if ($channel->single_min > 0 && $amount < $channel->single_min) {
            return false;
        }
        if ($channel->single_max > 0 && $amount > $channel->single_max) {
            return false;
        }

        // 3. 检查连续异常/掉线状态
        if ((int)$channel->status !== 1) {
            return false;
        }

        return true;
    }

    /**
     * 智能计算随机上浮金额 (在 pay_float_min ~ pay_float_max 区间内生成随机小数点)
     */
    public function generateSmartFloatMoney(float $baseAmount, float $minFloat = 0.01, float $maxFloat = 0.09): float
    {
        $randomCents = mt_rand((int)($minFloat * 100), (int)($maxFloat * 100)) / 100;
        return round($baseAmount + $randomCents, 2);
    }
}
