<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use app\model\UserMoneyLog;
use app\service\order\ChannelRoutingService;
use app\service\order\CloseOrderService;
use app\service\order\CreateOrderService;
use app\service\order\FeeReservationService;
use app\service\order\OrderNumberGenerator;
use app\service\order\PaymentInitializationService;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;
use Throwable;

/**
 * 订单领域兼容门面；创建和关闭用例由独立应用服务实现。
 */
class OrderService
{
    private MerchantNotifyService $notifyService;
    private CreateOrderService $createOrderService;
    private CloseOrderService $closeOrderService;

    public function __construct(
        ?OrderNumberGenerator $orderNumberGenerator = null,
        ?FeeReservationService $feeReservationService = null,
        ?ChannelRoutingService $channelRoutingService = null,
        ?PaymentInitializationService $paymentInitializationService = null,
        ?CreateOrderService $createOrderService = null,
        ?CloseOrderService $closeOrderService = null,
    ) {
        $this->notifyService = new MerchantNotifyService();
        $this->createOrderService = $createOrderService ?? new CreateOrderService(
            $orderNumberGenerator,
            $feeReservationService,
            $channelRoutingService,
            $paymentInitializationService,
        );
        $this->closeOrderService = $closeOrderService ?? new CloseOrderService();
    }

    public function createOrder(
        array $params,
        string $gatewayBaseUrl = '',
        string $businessType = 'payment',
        string $remoteIp = ''
    ): array {
        return $this->createOrderService->create($params, $gatewayBaseUrl, $businessType, $remoteIp);
    }

    /**
     * 事务化核销订单。订单状态、余额、余额流水和通道统计要么全部成功，要么全部回滚。
     */
    public function markAsPaid(
        string $orderNo,
        string $channelTradeNo,
        float $amount,
        ?int $channelId = null,
        bool $validateAmount = true
    ): bool {
        $receivedAmount = $this->normalizeMoney($amount);
        $targetOrder = $this->findOrderForSettlement($orderNo, $channelId);
        if (!$targetOrder) {
            return false;
        }
        if ($channelId !== null && (int)$targetOrder->channel_id !== $channelId) {
            return false;
        }
        if ((int)$targetOrder->status === 1) {
            return true;
        }
        if ((int)$targetOrder->status !== 0) {
            return false;
        }
        $targetOrderId = (int)$targetOrder->id;
        $targetMerchantId = (int)$targetOrder->merchant_id;

        try {
            $result = DB::connection()->transaction(function () use (
                $targetOrderId,
                $targetMerchantId,
                $channelTradeNo,
                $receivedAmount,
                $channelId,
                $validateAmount
            ): array {
                $merchant = Merchant::where('id', $targetMerchantId)->lockForUpdate()->first();
                if (!$merchant) {
                    throw new RuntimeException('订单所属商户不存在');
                }
                $order = Order::where('id', $targetOrderId)->lockForUpdate()->first();
                if (!$order) {
                    return ['success' => false, 'newly_paid' => false, 'order' => null];
                }

                if ($channelId !== null && (int)$order->channel_id !== $channelId) {
                    return ['success' => false, 'newly_paid' => false, 'order' => null];
                }
                if ((int)$order->status === 1) {
                    return ['success' => true, 'newly_paid' => false, 'order' => $order];
                }
                if ((int)$order->status !== 0) {
                    return ['success' => false, 'newly_paid' => false, 'order' => null];
                }
                if ($validateAmount && bccomp((string)$order->price, $receivedAmount, 2) !== 0) {
                    return ['success' => false, 'newly_paid' => false, 'order' => null];
                }

                $before = $this->normalizeMoney($merchant->money);
                if ((string)($order->business_type ?? 'payment') === 'recharge') {
                    $change = $this->normalizeMoney($order->amount);
                    $after = bcadd($before, $change, 2);
                    $memo = "充值订单 {$order->trade_no} 余额入账";
                } else {
                    $feeStatus = (int)($order->fee_status ?? 0);
                    $fee = $this->normalizeMoney($order->fee_amount ?? 0);
                    if ($feeStatus === 1 || $feeStatus === 2) {
                        $change = '0.00';
                        $after = $before;
                        $memo = '';
                    } else {
                        if (bccomp($fee, '0.00', 2) === 0) {
                            $rateConfigLegacy = json_decode((string)($merchant->rate_config ?? ''), true);
                            $payType = (string)($order->pay_type ?? '');
                            $effectiveRateLegacy = (is_array($rateConfigLegacy) && isset($rateConfigLegacy[$payType]))
                                ? (string)$rateConfigLegacy[$payType]
                                : (string)($merchant->rate ?? '0.02');
                            $fee = bcmul((string)$order->amount, $effectiveRateLegacy, 2);
                        }
                        $change = bccomp($fee, '0.00', 2) > 0 ? '-' . $fee : '0.00';
                        $after = bcsub($before, $fee, 2);
                        $memo = "支付订单 {$order->trade_no} 手续费扣除";
                    }
                    $order->fee_amount = $fee;
                    $order->fee_status = 2;
                    $order->fee_reservation_status = 'consumed';
                }

                if (bccomp($change, '0.00', 2) !== 0) {
                    $merchant->money = $after;
                    $merchant->save();
                    UserMoneyLog::log((int)$merchant->id, $change, $before, $after, $memo);
                }

                Channel::where('id', $order->channel_id)->update([
                    'today_money' => DB::raw('today_money + ' . $this->quoteDecimal((string)$order->price)),
                    'today_count' => DB::raw('today_count + 1'),
                    'total_money' => DB::raw('total_money + ' . $this->quoteDecimal((string)$order->price)),
                ]);

                $order->status = 1;
                $order->pay_init_status = 2;
                $order->channel_trade_no = mb_substr($channelTradeNo, 0, 128);
                $order->pay_time = time();
                $order->save();

                return ['success' => true, 'newly_paid' => true, 'order' => $order];
            }, 3);
        } catch (Throwable $e) {
            error_log('[OrderService] 核销失败 order_no=' . $orderNo . ' error=' . $e->getMessage());
            return false;
        }

        if (!$result['success']) {
            return false;
        }
        if ($result['newly_paid'] && $result['order'] instanceof Order) {
            try {
                $this->notifyService->notifyMerchant($result['order']);
            } catch (Throwable $e) {
                error_log('[OrderService] 商户通知启动失败 trade_no=' . $orderNo . ' error=' . $e->getMessage());
            }

            try {
                $alertSvc = new AlertNotificationService();
                $merchant = Merchant::find($result['order']->merchant_id);
                $pid = $merchant ? (string)$merchant->pid : '';
                $alertPayload = [
                    'trade_no' => $result['order']->trade_no,
                    'amount' => number_format((float)$result['order']->price, 2, '.', ''),
                    'pid' => $pid,
                ];
                $alertSvc->dispatchAdmin('order_paid', $alertPayload);
                if ($pid !== '') {
                    $alertSvc->dispatchMerchant($pid, 'order_paid', $alertPayload);
                    if ($merchant) {
                        $merchantConfig = $alertSvc->getMerchantConfig($pid);
                        $threshold = (float)($merchantConfig['low_balance_threshold'] ?? 10.00);
                        $currentMoney = (float)($merchant->money ?? 0.00);
                        if ($currentMoney < $threshold) {
                            $alertSvc->dispatchMerchant($pid, 'low_balance', [
                                'balance' => number_format($currentMoney, 2, '.', ''),
                                'threshold' => number_format($threshold, 2, '.', ''),
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[OrderService] 告警通知派发失败 trade_no=' . $orderNo . ' error=' . $e->getMessage());
            }
        }
        return true;
    }

    public function resendNotify(string $tradeNo): array
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return ['code' => -1, 'msg' => '订单不存在'];
        }
        if ((int)$order->status !== 1) {
            return ['code' => -1, 'msg' => '未支付订单无法重新发送通知'];
        }

        return [
            'code' => 1,
            'msg' => '商户异步通知已重新发送',
            'detail' => $this->notifyService->notifyMerchant($order),
        ];
    }

    public function closePendingOrder(string $tradeNo, string $reason = '订单关闭'): bool
    {
        return $this->closeOrderService->close($tradeNo, $reason);
    }

    public function expirePendingOrders(int $limit = 500): int
    {
        return $this->closeOrderService->expire(max(1, min(2000, $limit)));
    }

    private function findOrderForSettlement(string $orderNo, ?int $channelId): ?Order
    {
        $order = Order::where('trade_no', $orderNo)->first();
        if ($order) {
            return $order;
        }

        $legacyQuery = Order::where('out_trade_no', $orderNo);
        if ($channelId !== null) {
            $legacyQuery->where('channel_id', $channelId);
        }
        $legacyOrders = $legacyQuery->limit(2)->get();

        return $legacyOrders->count() === 1 ? $legacyOrders->first() : null;
    }

    private function normalizeMoney(mixed $amount): string
    {
        return is_numeric($amount) ? number_format((float)$amount, 2, '.', '') : '0.00';
    }

    private function quoteDecimal(string $amount): string
    {
        $normalized = $this->normalizeMoney($amount);
        if (!preg_match('/^\d+\.\d{2}$/', $normalized)) {
            throw new RuntimeException('金额格式不合法');
        }
        return $normalized;
    }
}
