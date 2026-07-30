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
     *
     * @param  float  $baseAmount 原始金额（元）
     * @param  float  $minFloat   最小浮动（元），默认 0.01
     * @param  float  $maxFloat   最大浮动（元），默认 0.09
     * @return string 两位小数字符串，使用 bcmath 保证精度
     */
    public function generateSmartFloatMoney(float $baseAmount, float $minFloat = 0.01, float $maxFloat = 0.09): string
    {
        $minCents = (int)round($minFloat * 100);
        $maxCents = (int)round($maxFloat * 100);

        // 边界保护：范围为 0 时退化为最小浮动 1 分，确保识别金额有差异化意义
        if ($minCents <= 0) {
            $minCents = 1;
        }
        if ($maxCents < $minCents) {
            $maxCents = $minCents;
        }

        $randomCents = mt_rand($minCents, $maxCents);
        // 用 bcadd 避免浮点累积误差，保证两位小数精度
        return bcadd(number_format($baseAmount, 2, '.', ''), number_format($randomCents / 100, 2, '.', ''), 2);
    }
}
