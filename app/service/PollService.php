<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use app\model\PollGroup;
use app\model\PollGroupChannel;
use support\Authcode;
use Exception;
use Webman\Redis\Client as RedisClient;

/**
 * 智能通道分流与轮询服务
 *
 * 支持：
 * 1. 轮询组优先级调度（加权随机 Weighted Random、顺序平滑轮询 Round-Robin、今日收款最小负载优先 Least-Money）
 * 2. 状态/在线监测/单笔限额/日上限自动熔断与健康检查
 * 3. 轮询组无可用通道时自动平滑回退至全局通道池
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
     * @param  int    $merchantId 商户ID（>0 商户专属，0 平台全局）
     * @param  string $payType    支付方式（如 alipay / wxpay / qqpay / usdt）
     * @param  float  $amount     交易金额
     * @return array  [c_type => string, config => array, channel_id => int, poll_group_id => ?int]
     */
    public function selectChannel(int $merchantId, string $payType, float $amount): array
    {
        // ==========================================
        // 阶段一：优先匹配已启用且有健康通道的【轮询组】
        // ==========================================
        try {
            $pollGroups = PollGroup::where('status', 1)
                ->where(function ($query) use ($merchantId) {
                    $query->where('merchant_id', $merchantId)
                        ->orWhere('merchant_id', 0);
                })
                ->where(function ($query) use ($payType) {
                    $query->where('c_type', $payType)
                        ->orWhere('c_type', 'LIKE', $payType . '%');
                })
                ->orderBy('merchant_id', 'desc') // 优先商户专属轮询组
                ->get();

            foreach ($pollGroups as $group) {
                $groupChannels = PollGroupChannel::where('group_id', $group->id)
                    ->with('channel')
                    ->get();

                $candidates = [];
                foreach ($groupChannels as $item) {
                    $channel = $item->channel;
                    if (!$channel || (int)$channel->status !== 1 || (int)$channel->online_status !== 1) {
                        continue;
                    }

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
                    // 日额度上限
                    if ($dayMax > 0 && ((float)$channel->today_money + $amount) > $dayMax) {
                        continue;
                    }

                    $candidates[] = [
                        'channel' => $channel,
                        'weight'  => max(1, (int)$item->weight),
                    ];
                }

                if (!empty($candidates)) {
                    $selectedChannel = $this->dispatchByStrategy((int)$group->strategy, $candidates, (int)$group->id);
                    return $this->formatChannelResult($selectedChannel, (int)$group->id);
                }
            }
        } catch (\Throwable $e) {
            // 轮询组异常时记录日志并继续降级至默认通道池
        }

        // ==========================================
        // 阶段二：回退至默认全局可用通道池
        // ==========================================
        $channels = Channel::where('status', 1)
            ->where('online_status', 1)
            ->where(function ($query) use ($merchantId) {
                $query->where('merchant_id', $merchantId)
                    ->orWhere('merchant_id', 0);
            })
            ->where(function ($query) use ($payType) {
                $query->where('c_type', 'LIKE', $payType . '%')
                    ->orWhere('pay_category', $payType);
            })
            ->get();

        if ($channels->isEmpty()) {
            throw new Exception("没有可用的 [{$payType}] 类型支付通道");
        }

        $validChannels = [];
        foreach ($channels as $channel) {
            $min    = (float)$channel->single_min;
            $max    = (float)$channel->single_max;
            $dayMax = (float)$channel->day_max;

            if ($min > 0 && $amount < $min) {
                continue;
            }
            if ($max > 0 && $amount > $max) {
                continue;
            }
            if ($dayMax > 0 && ((float)$channel->today_money + $amount) > $dayMax) {
                continue;
            }

            $validChannels[] = $channel;
        }

        if (empty($validChannels)) {
            throw new Exception("当前金额 ¥{$amount} 无匹配的可用 [{$payType}] 通道");
        }

        $selectedChannel = $this->weightedRandom($validChannels);
        return $this->formatChannelResult($selectedChannel, null);
    }

    /**
     * 根据轮询组设定的调度策略分发通道
     *
     * @param int   $strategy   1:加权随机 2:顺序轮询 3:最小金额负载
     * @param array $candidates [['channel' => Channel, 'weight' => int], ...]
     * @param int   $groupId    轮询组ID
     * @return Channel
     */
    public function dispatchByStrategy(int $strategy, array $candidates, int $groupId): Channel
    {
        if (count($candidates) === 1) {
            return $candidates[0]['channel'];
        }

        switch ($strategy) {
            case PollGroup::STRATEGY_ROUND_ROBIN: // 2: 顺序平滑轮询
                $cacheKey = "cxpay:poll_rr:group_{$groupId}";
                $count = count($candidates);
                try {
                    $counter = (int)RedisClient::incr($cacheKey);
                    $index = ($counter - 1) % $count;
                    return $candidates[$index]['channel'];
                } catch (\Throwable) {
                    $randIndex = mt_rand(0, $count - 1);
                    return $candidates[$randIndex]['channel'];
                }

            case PollGroup::STRATEGY_LEAST_MONEY: // 3: 今日收款最少优先
                usort($candidates, function ($a, $b) {
                    $moneyA = (float)($a['channel']->today_money ?? 0);
                    $moneyB = (float)($b['channel']->today_money ?? 0);
                    if (abs($moneyA - $moneyB) < 0.001) {
                        return $b['weight'] <=> $a['weight']; // 金额相同时优先更高权重
                    }
                    return $moneyA <=> $moneyB;
                });
                return $candidates[0]['channel'];

            case PollGroup::STRATEGY_WEIGHTED_RANDOM: // 1: 加权随机 (默认)
            default:
                $totalWeight = 0;
                foreach ($candidates as $cand) {
                    $totalWeight += $cand['weight'];
                }
                $rand = mt_rand(1, max(1, $totalWeight));
                $currentSum = 0;
                foreach ($candidates as $cand) {
                    $currentSum += $cand['weight'];
                    if ($rand <= $currentSum) {
                        return $cand['channel'];
                    }
                }
                return $candidates[0]['channel'];
        }
    }

    /**
     * 权重加权随机挑选算法（保留向后兼容）
     *
     * @param  Channel[] $channels
     * @return Channel
     */
    public function weightedRandom(array $channels): Channel
    {
        $totalWeight = 0;
        foreach ($channels as $channel) {
            $totalWeight += max(1, (int)$channel->weight);
        }

        $rand       = mt_rand(1, max(1, $totalWeight));
        $currentSum = 0;

        foreach ($channels as $channel) {
            $currentSum += max(1, (int)$channel->weight);
            if ($rand <= $currentSum) {
                return $channel;
            }
        }

        return $channels[0];
    }

    /**
     * 格式化并解密通道最终输出结果
     */
    private function formatChannelResult(Channel $channel, ?int $pollGroupId): array
    {
        $rawConfig       = json_decode((string)$channel->config, true) ?: [];
        $decryptedConfig = [];
        foreach ($rawConfig as $k => $v) {
            $decryptedConfig[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
        }

        return [
            'channel_id'    => $channel->id,
            'c_type'        => $channel->c_type,
            'config'        => $decryptedConfig,
            'poll_group_id' => $pollGroupId,
        ];
    }
}
