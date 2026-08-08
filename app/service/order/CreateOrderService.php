<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use app\model\UserMoneyLog;
use app\service\RiskGuardService;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;
use support\IpWhitelist;
use support\Sign;

/**
 * 完成订单请求校验、手续费预留、持久化和支付初始化。
 */
final class CreateOrderService
{
    private const PRICE_REUSE_COOLDOWN = 600;

    private OrderNumberGenerator $orderNumberGenerator;
    private FeeReservationService $feeReservationService;
    private ChannelRoutingService $channelRoutingService;
    private PaymentInitializationService $paymentInitializationService;
    private RiskGuardService $riskGuardService;

    public function __construct(
        ?OrderNumberGenerator $orderNumberGenerator = null,
        ?FeeReservationService $feeReservationService = null,
        ?ChannelRoutingService $channelRoutingService = null,
        ?PaymentInitializationService $paymentInitializationService = null,
        ?RiskGuardService $riskGuardService = null,
    ) {
        $this->orderNumberGenerator = $orderNumberGenerator ?? new OrderNumberGenerator();
        $this->feeReservationService = $feeReservationService ?? new FeeReservationService();
        $this->channelRoutingService = $channelRoutingService ?? new ChannelRoutingService();
        $this->paymentInitializationService = $paymentInitializationService ?? new PaymentInitializationService();
        $this->riskGuardService = $riskGuardService ?? new RiskGuardService();
    }

    public function create(
        array $params,
        string $gatewayBaseUrl = '',
        string $businessType = 'payment',
        string $remoteIp = '',
    ): array {
        $pid = trim((string)($params['pid'] ?? ''));
        if ($pid === '') {
            throw new RuntimeException('商户 PID (pid) 不能为空');
        }

        $merchant = Merchant::where('pid', $pid)->first();
        if (!$merchant || (int)$merchant->status !== 1) {
            throw new RuntimeException('商户不存在或已被停用');
        }
        if ($remoteIp !== '' && !IpWhitelist::allows($remoteIp, (string)($merchant->ip_white ?? ''))) {
            throw new RuntimeException('当前请求 IP 不在商户白名单中');
        }
        if (!Sign::verifySign($params, (string)$merchant->key)) {
            throw new RuntimeException('签名校验失败，请检查对接密钥');
        }

        $this->enforceOrderRateLimit((int)$merchant->id);

        $outTradeNo = trim((string)($params['out_trade_no'] ?? ''));
        $notifyUrl = trim((string)($params['notify_url'] ?? ''));
        $returnUrl = trim((string)($params['return_url'] ?? ''));
        $subject = trim((string)($params['name'] ?? '网络支付'));
        $money = $this->normalizeInputMoney($params['money'] ?? null);
        $type = trim((string)($params['type'] ?? 'alipay'));
        if (!in_array($businessType, ['payment', 'recharge'], true)) {
            throw new RuntimeException('不支持的订单业务类型');
        }
        if ($outTradeNo === '' || strlen($outTradeNo) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $outTradeNo)) {
            throw new RuntimeException('out_trade_no 格式不合法');
        }
        $allowedTypes = ['alipay', 'wxpay', 'qqpay'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new RuntimeException('不支持的支付类型（type），允许值：' . implode('/', $allowedTypes));
        }
        if (bccomp($money, '0.00', 2) <= 0) {
            throw new RuntimeException('支付金额必须大于 0');
        }
        if (($businessType === 'payment' && !$this->isHttpUrl($notifyUrl))
            || ($notifyUrl !== '' && !$this->isHttpUrl($notifyUrl))
            || ($returnUrl !== '' && !$this->isHttpUrl($returnUrl))) {
            throw new RuntimeException('notify_url 或 return_url 格式不合法');
        }

        $existing = Order::where('merchant_id', $merchant->id)
            ->where('out_trade_no', $outTradeNo)
            ->first();
        if ($existing) {
            if (bccomp((string)$existing->amount, $money, 2) !== 0
                || (string)$existing->pay_type !== $type
                || (string)($existing->business_type ?? 'payment') !== $businessType) {
                throw new RuntimeException('商户订单号已存在且订单属性不一致');
            }
            return $this->paymentInitializationService->prepare($existing, $params, $gatewayBaseUrl);
        }

        if (!empty($merchant->packvip_time) && (int)$merchant->packvip_time < time()) {
            throw new RuntimeException('商户 VIP 套餐已过期，请前往商户后台续费');
        }

        $channelOwnerId = $businessType === 'recharge' ? 0 : (int)$merchant->id;
        $selectedChannel = $this->channelRoutingService->select($channelOwnerId, $type, $money);
        $this->channelRoutingService->assertReady($selectedChannel);
        $basePrice = $money;
        if (bccomp((string)($merchant->pay_float_max ?? '0.00'), '0.00', 2) > 0) {
            $floated = $this->riskGuardService->generateSmartFloatMoney(
                (float)$money,
                max(0.01, (float)($merchant->pay_float_min ?? 0.01)),
                max(0.01, (float)($merchant->pay_float_max ?? 0.09))
            );
            $singleMin = (float)($selectedChannel->single_min ?? 0);
            $singleMax = (float)($selectedChannel->single_max ?? 0);
            $floatExceedsMax = $singleMax > 0 && bccomp($floated, number_format($singleMax, 2, '.', ''), 2) > 0;
            $floatBelowMin = $singleMin > 0 && bccomp($floated, number_format($singleMin, 2, '.', ''), 2) < 0;
            $basePrice = ($floatExceedsMax || $floatBelowMin) ? $money : $floated;
        }

        $now = time();
        $tradeNo = $this->orderNumberGenerator->generate();
        $merchantId = (int)$merchant->id;
        $channelId = (int)$selectedChannel->id;

        $order = DB::connection()->transaction(function () use (
            $merchantId,
            $channelId,
            $outTradeNo,
            $tradeNo,
            $type,
            $businessType,
            $money,
            $basePrice,
            $subject,
            $notifyUrl,
            $returnUrl,
            $now
        ): Order {
            $lockedMerchant = Merchant::where('id', $merchantId)->lockForUpdate()->first();
            if (!$lockedMerchant || (int)$lockedMerchant->status !== 1) {
                throw new RuntimeException('商户不存在或已被停用');
            }

            $planId = (int)($lockedMerchant->plan_id ?? 0);
            $planExpire = (int)($lockedMerchant->plan_expire_time ?? 0);
            if ($planId <= 0 || ($planExpire > 0 && $planExpire < $now)) {
                throw new RuntimeException('该商户尚未开通有效套餐或套餐已到期，请先前往控制台「套餐订阅广场」开通/续费套餐后方可进行交易');
            }

            $existingOrder = Order::where('merchant_id', $merchantId)
                ->where('out_trade_no', $outTradeNo)
                ->lockForUpdate()
                ->first();
            if ($existingOrder) {
                if (bccomp((string)$existingOrder->amount, $money, 2) !== 0
                    || (string)$existingOrder->pay_type !== $type
                    || (string)($existingOrder->business_type ?? 'payment') !== $businessType) {
                    throw new RuntimeException('商户订单号已存在且订单属性不一致');
                }
                return $existingOrder;
            }

            $lockedChannel = Channel::where('id', $channelId)->lockForUpdate()->first();
            if (!$lockedChannel || (int)$lockedChannel->status !== 1 || (int)$lockedChannel->online_status !== 1) {
                throw new RuntimeException('支付通道状态已变化，请重新下单');
            }
            if ((float)($lockedChannel->day_max ?? 0) > 0
                && bccomp(
                    bcadd((string)($lockedChannel->today_money ?? '0'), $money, 2),
                    number_format((float)$lockedChannel->day_max, 2, '.', ''),
                    2
                ) > 0) {
                throw new RuntimeException('当前通道今日收款额度已满，请稍后重试或更换通道');
            }

            $finalPrice = $basePrice;
            $priceAvailable = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $conflict = Order::where('channel_id', $channelId)
                    ->where('price', $finalPrice)
                    ->where('expire_time', '>', $now - self::PRICE_REUSE_COOLDOWN)
                    ->exists();
                if (!$conflict) {
                    $priceAvailable = true;
                    break;
                }
                $finalPrice = bcadd($finalPrice, '0.01', 2);
            }
            if (!$priceAvailable) {
                throw new RuntimeException('当前通道可识别金额已占满，请稍后重试');
            }

            $fee = '0.00';
            $feeStatus = 0;
            $feeReservedCash = '0.00';
            $feeReservedDiscount = '0.00';
            $feeReservationStatus = 'consumed';
            if ($businessType === 'payment') {
                $rateConfig = json_decode((string)($lockedMerchant->rate_config ?? ''), true);
                $effectiveRate = (is_array($rateConfig) && isset($rateConfig[$type]))
                    ? (string)$rateConfig[$type]
                    : (string)($lockedMerchant->rate ?? '0.02');
                $fee = bcmul($money, $effectiveRate, 2);
                $discountBalance = $this->normalizeMoney($lockedMerchant->plan_fee_discount_balance ?? 0);
                $moneyBalance = $this->normalizeMoney($lockedMerchant->money ?? 0);

                if (bccomp($fee, '0.00', 2) > 0) {
                    $reservation = $this->feeReservationService->allocate($fee, $moneyBalance, $discountBalance);
                    $feeReservedCash = $reservation->cash;
                    $feeReservedDiscount = $reservation->discount;
                    $feeReservationStatus = 'reserved';

                    $newMoney = bcsub($moneyBalance, $reservation->cash, 2);
                    $newDiscount = bcsub($discountBalance, $reservation->discount, 2);
                    $lockedMerchant->money = $newMoney;
                    $lockedMerchant->plan_fee_discount_balance = $newDiscount;
                    $lockedMerchant->save();

                    UserMoneyLog::log(
                        $merchantId,
                        bccomp($reservation->cash, '0.00', 2) > 0 ? '-' . $reservation->cash : '0.00',
                        $moneyBalance,
                        $newMoney,
                        "支付订单 {$tradeNo} 手续费预占（套餐抵扣金 ¥{$reservation->discount}，账户余额 ¥{$reservation->cash}）"
                    );
                    $feeStatus = 1;
                }
            }

            return Order::create([
                'merchant_id' => $merchantId,
                'out_trade_no' => $outTradeNo,
                'trade_no' => $tradeNo,
                'channel_id' => $channelId,
                'pay_type' => $type,
                'business_type' => $businessType,
                'fee_amount' => $fee,
                'fee_reserved_cash' => $feeReservedCash,
                'fee_reserved_discount' => $feeReservedDiscount,
                'fee_reservation_status' => $feeReservationStatus,
                'fee_status' => $feeStatus,
                'amount' => $money,
                'price' => $finalPrice,
                'subject' => mb_substr($subject !== '' ? $subject : '网络支付', 0, 255),
                'notify_url' => $notifyUrl,
                'return_url' => $returnUrl,
                'pay_init_status' => 0,
                'pay_init_time' => 0,
                'notify_status' => 0,
                'status' => 0,
                'create_time' => $now,
                'expire_time' => $now + max(60, (int)($lockedMerchant->pay_outtime ?? 180)),
            ]);
        }, 3);

        return $this->paymentInitializationService->prepare($order, $params, $gatewayBaseUrl);
    }

    private function enforceOrderRateLimit(int $merchantId): void
    {
        $windowSeconds = 60;
        $maxRequests = 30;

        try {
            $redis = \Webman\Redis\Client::connection();
            $now = (int)(microtime(true) * 1000);
            $key = 'cx:rate_limit:order:' . $merchantId;
            $windowStart = $now - $windowSeconds * 1000;

            $redis->zRemRangeByScore($key, '-inf', (string)$windowStart);
            $redis->zAdd($key, $now, $now . '_' . random_int(0, 9999));
            $redis->expire($key, $windowSeconds * 2);
            $count = $redis->zCard($key);

            if ($count > $maxRequests) {
                throw new RuntimeException(
                    "下单频率超限，请稍后再试（单商户每分钟最多 {$maxRequests} 次）"
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            error_log('[CreateOrderService] 限频 Redis 不可用，降级放行 merchant_id=' . $merchantId);
        }
    }

    private function normalizeMoney(mixed $amount): string
    {
        return is_numeric($amount) ? number_format((float)$amount, 2, '.', '') : '0.00';
    }

    private function normalizeInputMoney(mixed $amount): string
    {
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            throw new RuntimeException('支付金额格式不合法');
        }
        $raw = trim((string)$amount);
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw)) {
            throw new RuntimeException('支付金额最多8位整数和2位小数');
        }
        return number_format((float)$raw, 2, '.', '');
    }

    private function isHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
