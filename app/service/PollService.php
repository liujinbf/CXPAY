<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use support\Authcode;
use Exception;

/**
 * 智能通道分流与轮询服务（含权重加权随机、日限额与金额区间过滤）
 * 修复：原版查询所有通道未按 pay_type/c_type 过滤，导致微信通道可能被分配给支付宝订单
 */
class PollService
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 根据商户、支付方式与订单金额智能选取最匹配通道
     *
     * @param  int    $merchantId 商户ID（保留，用于未来按商户分配通道）
     * @param  string $payType    支付方式（如 alipay / wxpay / qqpay）
     * @param  float  $amount     交易金额
     * @return array  [c_type => string, config => array, channel_id => int]
     */
    public function selectChannel(int $merchantId, string $payType, float $amount): array
    {
        // 1. 按支付类型前缀筛选启用通道（关键修复：之前未过滤 c_type）
        $channels = Channel::where('status', 1)
            ->where('online_status', 1)
            ->where(function ($query) use ($merchantId) {
                $query->where('merchant_id', $merchantId)
                    ->orWhere('merchant_id', 0);
            })
            ->where(function ($query) use ($payType) {
                // c_type 以 payType 开头，例如 alipay_app_asst / wxpay_app_asst
                $query->where('c_type', 'LIKE', $payType . '%')
                    ->orWhere('pay_category', $payType);
            })
            ->get();

        if ($channels->isEmpty()) {
            throw new Exception("没有可用的 [{$payType}] 类型支付通道");
        }

        // 2. 单笔金额区间与日限额过滤
        $validChannels = [];
        foreach ($channels as $channel) {
            $min    = (float)$channel->single_min;
            $max    = (float)$channel->single_max;
            $dayMax = (float)$channel->day_max;

            // 单笔下限
            if ($min > 0 && $amount < $min) {
                continue;
            }
            // 单笔上限
            if ($max > 0 && $amount > $max) {
                continue;
            }
            // 日额度上限（已累计 + 本次 > 日上限则跳过）
            if ($dayMax > 0 && ((float)$channel->today_money + $amount) > $dayMax) {
                continue;
            }

            $validChannels[] = $channel;
        }

        if (empty($validChannels)) {
            throw new Exception("当前金额 ¥{$amount} 无匹配的可用 [{$payType}] 通道");
        }

        // 3. 权重加权随机算法（Weighted Random）
        $selectedChannel = $this->weightedRandom($validChannels);

        // 4. 解密通道加密私有配置
        $rawConfig       = json_decode($selectedChannel->config, true) ?: [];
        $decryptedConfig = [];
        foreach ($rawConfig as $k => $v) {
            $decryptedConfig[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
        }

        return [
            'channel_id' => $selectedChannel->id,
            'c_type'     => $selectedChannel->c_type,
            'config'     => $decryptedConfig,
        ];
    }

    /**
     * 权重加权随机挑选算法（Weighted Random Sampling）
     *
     * 供 PollService 自身调用，也供 OrderService 回退路径复用，保持全局调度策略一致。
     *
     * @param  Channel[] $channels  候选通道数组（索引连续，权重字段 weight 必须存在）
     * @return Channel
     */
    public function weightedRandom(array $channels): Channel
    {
        $totalWeight = 0;
        foreach ($channels as $channel) {
            $totalWeight += max(1, (int)$channel->weight);
        }

        $rand       = mt_rand(1, $totalWeight);
        $currentSum = 0;

        foreach ($channels as $channel) {
            $currentSum += max(1, (int)$channel->weight);
            if ($rand <= $currentSum) {
                return $channel;
            }
        }

        return $channels[0];
    }
}
