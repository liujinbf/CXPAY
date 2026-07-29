<?php

declare(strict_types=1);

namespace app\controller\notify;

use app\model\Channel;
use app\model\Order;
use app\payment\PaymentManager;
use app\service\OrderService;
use support\Authcode;
use support\Request;
use support\Response;
use Throwable;

/**
 * 上游支付通道异步回调控制器。
 */
class NotifyController
{
    private OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    public function index(Request $request, string $cType): Response
    {
        try {
            $params = $request->get() + $request->post();
            $order = $this->resolveOrder($params, $cType);
            if (!$order) {
                // 微信 V3 等回调的订单号位于加密 resource 内，只能按同类型通道逐一解密定位。
                return response($this->handleEncryptedPayload($params, $cType) ? 'success' : 'fail');
            }

            $channel = Channel::find($order->channel_id);
            if (!$channel || (string)$channel->c_type !== $cType) {
                return response('fail');
            }

            $config = $this->resolveChannelConfig($channel);
            if ($config === []) {
                error_log('[NotifyController] 通道配置为空 channel_id=' . $channel->id);
                return response('fail');
            }

            $result = PaymentManager::make($cType)->notify($params, $config);
            return response($this->settleResult($order, $channel, $result) ? 'success' : 'fail');
        } catch (Throwable $e) {
            error_log('[NotifyController] cType=' . $cType . ' error=' . $e->getMessage());
            return response('fail');
        }
    }

    /**
     * 优先按平台流水号定位；兼容旧上游时，只接受同驱动下唯一的商户订单号。
     */
    private function resolveOrder(array $params, string $cType): ?Order
    {
        $identifier = trim((string)($params['out_trade_no'] ?? $params['trade_no'] ?? ''));
        if ($identifier === '') {
            return null;
        }

        $order = Order::where('trade_no', $identifier)->first();
        if ($order) {
            return $order;
        }

        $channelIds = Channel::where('c_type', $cType)->pluck('id');
        if ($channelIds->isEmpty()) {
            return null;
        }

        $orders = Order::where('out_trade_no', $identifier)
            ->whereIn('channel_id', $channelIds)
            ->whereIn('status', [0, 1])
            ->limit(2)
            ->get();

        return $orders->count() === 1 ? $orders->first() : null;
    }

    private function resolveChannelConfig(Channel $channel): array
    {
        $raw = is_string($channel->config)
            ? (json_decode($channel->config, true) ?: [])
            : (array)$channel->config;
        if ($raw === []) {
            return [];
        }

        $authcode = new Authcode();
        $decrypted = [];
        foreach ($raw as $key => $value) {
            $decrypted[$key] = is_string($value) ? $authcode->decryptStored($value) : $value;
        }
        return $decrypted;
    }

    private function handleEncryptedPayload(array $params, string $cType): bool
    {
        if (empty($params['resource'])) {
            return false;
        }

        $channels = Channel::where('c_type', $cType)
            ->where('status', 1)
            ->limit(100)
            ->get();
        foreach ($channels as $channel) {
            $config = $this->resolveChannelConfig($channel);
            if ($config === []) {
                continue;
            }
            $result = PaymentManager::make($cType)->notify($params, $config);
            if (empty($result['success'])) {
                continue;
            }
            $platformTradeNo = trim((string)($result['out_trade_no'] ?? ''));
            $order = $platformTradeNo !== ''
                ? Order::where('trade_no', $platformTradeNo)->first()
                : null;
            if ($order && (int)$order->channel_id === (int)$channel->id) {
                return $this->settleResult($order, $channel, $result);
            }
        }
        return false;
    }

    private function settleResult(Order $order, Channel $channel, array $result): bool
    {
        if (empty($result['success'])) {
            return false;
        }
        $reportedOrderNo = trim((string)($result['out_trade_no'] ?? ''));
        if ($reportedOrderNo !== '') {
            $matchesPlatform = hash_equals((string)$order->trade_no, $reportedOrderNo);
            $matchesLegacyMerchantOrder = hash_equals((string)$order->out_trade_no, $reportedOrderNo);
            if (!$matchesPlatform && !$matchesLegacyMerchantOrder) {
                return false;
            }
        }

        return $this->orderService->markAsPaid(
            (string)$order->trade_no,
            (string)($result['trade_no'] ?? ''),
            (float)($result['amount'] ?? 0),
            (int)$channel->id,
            true
        );
    }
}
