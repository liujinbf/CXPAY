<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\Channel;
use app\payment\PaymentManager;
use app\service\PollService;
use app\service\RiskGuardService;
use RuntimeException;
use support\Authcode;
use Throwable;

/**
 * 根据支付类型、金额、权重和备用关系选择可用通道。
 */
final class ChannelRoutingService
{
    private PollService $pollService;
    private RiskGuardService $riskGuard;

    public function __construct(?PollService $pollService = null, ?RiskGuardService $riskGuard = null)
    {
        $this->pollService = $pollService ?? new PollService();
        $this->riskGuard = $riskGuard ?? new RiskGuardService();
    }

    public function select(int $merchantId, string $type, string $money): Channel
    {
        $amount = (float)$money;
        try {
            $result = $this->pollService->selectChannel($merchantId, $type, $amount);
            $channel = Channel::find($result['channel_id']);
        } catch (Throwable) {
            $channel = null;
        }

        if (!$channel
            || !PaymentManager::has((string)$channel->c_type)
            || !$this->riskGuard->validateRisk($channel, $amount)) {
            $fallback = null;
            if ($channel && (int)($channel->fallback_channel_id ?? 0) > 0) {
                $fallbackCandidate = Channel::find((int)$channel->fallback_channel_id);
                if ($fallbackCandidate
                    && (int)$fallbackCandidate->status === 1
                    && (int)$fallbackCandidate->online_status === 1
                    && PaymentManager::has((string)$fallbackCandidate->c_type)
                    && $this->riskGuard->validateRisk($fallbackCandidate, $amount)) {
                    $fallback = $fallbackCandidate;
                }
            }

            if ($fallback) {
                $channel = $fallback;
            } else {
                $candidates = Channel::where(function ($query) use ($merchantId) {
                        $query->where('merchant_id', $merchantId)->orWhere('merchant_id', 0);
                    })
                    ->where(function ($query) use ($type) {
                        $query->where('c_type', 'LIKE', $type . '%')->orWhere('pay_category', $type);
                    })
                    ->where('status', 1)
                    ->where('online_status', 1)
                    ->get()
                    ->filter(fn(Channel $candidate) => PaymentManager::has((string)$candidate->c_type)
                        && $this->riskGuard->validateRisk($candidate, $amount))
                    ->values()
                    ->all();

                $channel = !empty($candidates)
                    ? $this->pollService->weightedRandom($candidates)
                    : null;
            }
        }

        if (!$channel) {
            throw new RuntimeException('暂无满足条件的可用支付通道');
        }

        return $channel;
    }

    public function assertReady(Channel $channel): void
    {
        if (!PaymentManager::has((string)$channel->c_type)) {
            throw new RuntimeException('支付通道驱动不存在');
        }
        $config = $this->decryptChannelConfig($channel);
        $validated = PaymentManager::make((string)$channel->c_type)
            ->upchannel($channel->toArray(), $config);
        if (isset($validated['code']) && (int)$validated['code'] !== 1) {
            throw new RuntimeException((string)($validated['msg'] ?? '支付通道配置不完整'));
        }
    }

    private function decryptChannelConfig(Channel $channel): array
    {
        $raw = is_string($channel->config)
            ? (json_decode($channel->config, true) ?: [])
            : (array)$channel->config;
        $authcode = new Authcode();
        foreach ($raw as $key => $value) {
            if (is_string($value)) {
                $raw[$key] = $authcode->decryptStored($value);
            }
        }
        return $raw;
    }
}
