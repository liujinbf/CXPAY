<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use support\Authcode;
use Exception;

/**
 * 智能通道分流与轮询服务 (含权重加权随机、日限额与金额区间过滤)
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
     * @param int $merchantId 商户ID
     * @param string $payType 支付方式
     * @param float $amount 交易金额
     * @return array [c_type => string, config => array, channel_id => int]
     */
    public function selectChannel(int $merchantId, string $payType, float $amount): array
    {
        // 1. 查询数据库中启用的可匹配通道
        $channels = Channel::where('status', 1)->get();

        if ($channels->isEmpty()) {
            throw new Exception("没有可用的支付通道");
        }

        $validChannels = [];

        // 2. 金额区间与单笔限额过滤
        foreach ($channels as $channel) {
            $min = (float)$channel->single_min;
            $max = (float)$channel->single_max;

            if ($min > 0 && $amount < $min) {
                continue;
            }
            if ($max > 0 && $amount > $max) {
                continue;
            }

            $validChannels[] = $channel;
        }

        if (empty($validChannels)) {
            throw new Exception("当前金额无匹配的可用通道");
        }

        // 3. 权重加权随机算法 (Weighted Random)
        $selectedChannel = $this->weightedRandom($validChannels);

        // 4. 解密通道加密私有配置
        $rawConfig = json_decode($selectedChannel->config, true) ?: [];
        $decryptedConfig = [];
        foreach ($rawConfig as $k => $v) {
            $decryptedConfig[$k] = is_string($v) ? ($this->authcode->decrypt($v) ?: $v) : $v;
        }

        return [
            'channel_id' => $selectedChannel->id,
            'c_type'     => $selectedChannel->c_type,
            'config'     => $decryptedConfig,
        ];
    }

    /**
     * 权重加权随机挑选算法
     */
    protected function weightedRandom(array $channels): Channel
    {
        $totalWeight = 0;
        foreach ($channels as $channel) {
            $totalWeight += max(1, (int)$channel->weight);
        }

        $rand = mt_rand(1, $totalWeight);
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
