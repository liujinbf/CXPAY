<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use app\model\UserMoneyLog;
use app\payment\PaymentManager;
use app\service\AlertNotificationService;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;
use support\Authcode;
use support\Sign;
use support\SnowFlake;
use support\IpWhitelist;
use Throwable;

/**
 * 订单创建与支付核销服务。
 */
class OrderService
{
    /** 金额释放后继续隔离，避免超时前发起的延迟付款命中新订单。 */
    private const PRICE_REUSE_COOLDOWN = 600;

    private MerchantNotifyService $notifyService;
    private RiskGuardService $riskGuard;
    private PollService $pollService;

    public function __construct()
    {
        $this->notifyService = new MerchantNotifyService();
        $this->riskGuard = new RiskGuardService();
        $this->pollService = new PollService();
    }

    /**
     * 下单核心逻辑：验签、幂等检查、风控、选通道、金额去重、建单。
     */
    public function createOrder(
        array $params,
        string $gatewayBaseUrl = '',
        string $businessType = 'payment',
        string $remoteIp = ''
    ): array
    {
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

        // ── 商户级下单限频（滑动窗口：60s / 30次）────────────────────
        $this->enforceOrderRateLimit((int)$merchant->id);

        $outTradeNo = trim((string)($params['out_trade_no'] ?? ''));
        $notifyUrl = trim((string)($params['notify_url'] ?? ''));
        $returnUrl = trim((string)($params['return_url'] ?? ''));
        $subject = trim((string)($params['name'] ?? '网络支付'));
        $money = $this->normalizeInputMoney($params['money'] ?? null);
        $type = trim((string)($params['type'] ?? 'alipay'));
        $param = trim((string)($params['param'] ?? ''));
        if (!in_array($businessType, ['payment', 'recharge', 'plugin_purchase', 'plan_purchase'], true)) {
            throw new RuntimeException('不支持的订单业务类型');
        }

        if ($outTradeNo === '' || strlen($outTradeNo) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $outTradeNo)) {
            throw new RuntimeException('out_trade_no 格式不合法');
        }
        // type 为易支付协议标准字段，表示收款钱包分类，不是驱动标识（c_type）。
        // 通道选取时会按 pay_category 或 c_type 前缀匹配，此处仅校验合法范围。
        $allowedTypes = ['alipay', 'wxpay', 'qqpay', 'usdt', 'other', 'epay'];
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

        // 同一商户订单号只允许对应一笔业务；重复请求返回原订单。
        $existing = Order::where('merchant_id', $merchant->id)
            ->where('out_trade_no', $outTradeNo)
            ->first();
        if ($existing) {
            if (bccomp((string)$existing->amount, $money, 2) !== 0
                || (string)$existing->pay_type !== $type
                || (string)($existing->business_type ?? 'payment') !== $businessType) {
                throw new RuntimeException('商户订单号已存在且订单属性不一致');
            }
            return $this->preparePayment($existing, $params, $gatewayBaseUrl);
        }

        if (!empty($merchant->packvip_time) && (int)$merchant->packvip_time < time()) {
            throw new RuntimeException('商户 VIP 套餐已过期，请前往商户后台续费');
        }

        // 充值余额与购买套餐必须进入平台官方系统收款通道，不能用商户自己的收款码收款。
        if (in_array($businessType, ['recharge', 'plan_purchase'], true)) {
            $sysPid = (string)DB::table('cx_config')->where('name', 'system_recharge_pid')->value('value');
            $sysMerchant = $sysPid !== '' ? Merchant::where('pid', $sysPid)->first() : null;
            $channelOwnerId = $sysMerchant ? (int)$sysMerchant->id : 0;
        } else {
            $channelOwnerId = (int)$merchant->id;
        }
        $selectedChannel = $this->selectChannel($channelOwnerId, $type, (float)$money);
        $this->assertChannelReady($selectedChannel);
        $basePrice = $money;
        if (bccomp((string)($merchant->pay_float_max ?? '0.00'), '0.00', 2) > 0) {
            $floated = $this->riskGuard->generateSmartFloatMoney(
                (float)$money,
                max(0.01, (float)($merchant->pay_float_min ?? 0.01)),
                max(0.01, (float)($merchant->pay_float_max ?? 0.09))
            );
            // 浮动后若超出通道单笔上限或低于单笔下限，则回退为原始金额，避免突破通道风控边界。
            $singleMin = (float)($selectedChannel->single_min ?? 0);
            $singleMax = (float)($selectedChannel->single_max ?? 0);
            $floatExceedsMax = $singleMax > 0 && bccomp($floated, number_format($singleMax, 2, '.', ''), 2) > 0;
            $floatBelowMin  = $singleMin > 0 && bccomp($floated, number_format($singleMin, 2, '.', ''), 2) < 0;
            $basePrice = ($floatExceedsMax || $floatBelowMin) ? $money : $floated;
        }

        $now = time();
        $tradeNo = 'CX' . SnowFlake::makeId();
        $merchantId = (int)$merchant->id;
        $channelId = (int)$selectedChannel->id;

        // 锁定商户可防止并发订单共同通过同一余额检查；锁定通道可防止并发占用同一识别金额。
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
            $param,
            $notifyUrl,
            $returnUrl,
            $now
        ): Order {
            $lockedMerchant = Merchant::where('id', $merchantId)->lockForUpdate()->first();
            if (!$lockedMerchant || (int)$lockedMerchant->status !== 1) {
                throw new RuntimeException('商户不存在或已被停用');
            }

            // 无套餐 (plan_id <= 0) 或套餐已过期的商户禁止发起任何收单交易
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

            // 事务内用最新数据（已加排他锁）做日限额二次校验，消除并发超限竞态
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
                    // 已支付、已超时的订单也必须经过冷却期后才能复用识别金额。
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
            if ($businessType === 'payment') {
                // 差异化费率：优先按支付类型从 rate_config 取费率，不存在则回退全局 rate
                $rateConfig = json_decode((string)($lockedMerchant->rate_config ?? ''), true);
                $effectiveRate = (is_array($rateConfig) && isset($rateConfig[$type]))
                    ? (string)$rateConfig[$type]
                    : (string)($lockedMerchant->rate ?? '0.02');
                $fee = bcmul($money, $effectiveRate, 2);
                $discountBalance = $this->normalizeMoney($lockedMerchant->plan_fee_discount_balance ?? 0);
                $moneyBalance    = $this->normalizeMoney($lockedMerchant->money ?? 0);
                $totalAvailable  = bcadd($discountBalance, $moneyBalance, 2);

                if (bccomp($totalAvailable, $fee, 2) < 0) {
                    throw new RuntimeException("商户可用余额不足（需手续费 ¥{$fee}，充值余额 ¥{$moneyBalance}，套餐抵扣金 ¥{$discountBalance}），请先充值或购买套餐");
                }

                if (bccomp($fee, '0.00', 2) > 0) {
                    $useDiscount = '0.00';
                    $useMoney    = '0.00';

                    if (bccomp($discountBalance, $fee, 2) >= 0) {
                        // 抵扣金足够全额抵扣
                        $useDiscount = $fee;
                        $newDiscount = bcsub($discountBalance, $fee, 2);
                        $lockedMerchant->plan_fee_discount_balance = $newDiscount;
                        $lockedMerchant->save();

                        UserMoneyLog::log(
                            $merchantId,
                            '0.00',
                            $moneyBalance,
                            $moneyBalance,
                            "支付订单 {$tradeNo} 手续费预占（从套餐抵扣金抵扣 ¥{$useDiscount}，剩余抵扣金 ¥{$newDiscount}）"
                        );
                    } else {
                        // 抵扣金部分抵扣，剩余扣除通用余额
                        $useDiscount = $discountBalance;
                        $remainFee   = bcsub($fee, $useDiscount, 2);
                        $useMoney    = $remainFee;

                        $newMoney = bcsub($moneyBalance, $useMoney, 2);
                        $lockedMerchant->plan_fee_discount_balance = '0.00';
                        $lockedMerchant->money = $newMoney;
                        $lockedMerchant->save();

                        UserMoneyLog::log(
                            $merchantId,
                            '-' . $useMoney,
                            $moneyBalance,
                            $newMoney,
                            "支付订单 {$tradeNo} 手续费预占（套餐抵扣金抵扣 ¥{$useDiscount}，账户余额预扣 ¥{$useMoney}）"
                        );
                    }
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
                'param' => $param,
                'fee_amount' => $fee,
                'fee_discount_amount' => $useDiscount ?? '0.00',
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

        return $this->preparePayment($order, $params, $gatewayBaseUrl);
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
        if ((int)$targetOrder->status === 1) {
            return true;
        }
        if (!in_array((int)$targetOrder->status, [0, 2], true)) {
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
                // 与建单、关单保持“商户→订单”的固定锁顺序，降低交叉事务死锁概率。
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
                if (!in_array((int)$order->status, [0, 2], true)) {
                    return ['success' => false, 'newly_paid' => false, 'order' => null];
                }
                if ($validateAmount && bccomp((string)$order->price, $receivedAmount, 2) !== 0) {
                    return ['success' => false, 'newly_paid' => false, 'order' => null];
                }

                $before = $this->normalizeMoney($merchant->money);
                $bType = (string)($order->business_type ?? 'payment');

                if ($bType === 'recharge' || str_starts_with((string)$order->param, 'recharge:')) {
                    $targetBuyerId = $targetMerchantId;
                    if (str_starts_with((string)$order->param, 'recharge:')) {
                        $targetBuyerId = (int)substr((string)$order->param, strlen('recharge:'));
                    }
                    $rechargeMerchant = ($targetBuyerId === $targetMerchantId)
                        ? $merchant
                        : (Merchant::where('id', $targetBuyerId)->lockForUpdate()->first() ?: $merchant);

                    $rBefore = $this->normalizeMoney($rechargeMerchant->money);
                    $change  = $this->normalizeMoney($order->amount);
                    $rAfter  = bcadd($rBefore, $change, 2);
                    $memo    = "在线充值订单 {$order->trade_no} 服务费入账";

                    $rechargeMerchant->money = $rAfter;
                    $rechargeMerchant->save();
                    UserMoneyLog::log(
                        (int)$rechargeMerchant->id,
                        '+' . $change,
                        $rBefore,
                        $rAfter,
                        $memo
                    );
                } elseif ($bType === 'plugin_purchase') {
                    $change = $this->normalizeMoney($order->amount);
                    $after = bcadd($before, $change, 2);
                    $memo = "官方插件商城订单 {$order->trade_no} 款项入账";
                    $merchant->money = $after;
                    $merchant->save();
                    UserMoneyLog::log((int)$merchant->id, '+' . $change, $before, $after, $memo);
                } elseif ($bType === 'plan_purchase' || str_starts_with((string)$order->param, 'plan:')) {
                    // 在线购买套餐：资金直接进入系统官方收款商户，不扣除手续费
                } else {
                    $feeStatus = (int)($order->fee_status ?? 0);
                    $fee = $this->normalizeMoney($order->fee_amount ?? 0);
                    if ($feeStatus === 1) {
                        // 新订单已在创建事务中扣除余额，此处只消费预占，禁止重复扣费。
                        $change = '0.00';
                        $after = $before;
                        $memo = '';
                    } elseif ($feeStatus === 2) {
                        // 异常恢复场景：手续费已核销但订单仍待支付，不再重复扣费。
                        $change = '0.00';
                        $after = $before;
                        $memo = '';
                    } else {
                        // 兼容迁移前创建的旧订单：没有预占记录时在核销阶段扣费。
                        if (bccomp($fee, '0.00', 2) === 0) {
                            // 差异化费率：优先读取 rate_config，与建单逻辑保持一致
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

                    if (bccomp($change, '0.00', 2) !== 0) {
                        $merchant->money = $after;
                        $merchant->save();
                        UserMoneyLog::log(
                            (int)$merchant->id,
                            $change,
                            $before,
                            $after,
                            $memo
                        );
                    }
                }

                Channel::where('id', $order->channel_id)->update([
                    'today_money' => DB::raw('today_money + ' . $this->quoteDecimal((string)$order->price)),
                    'today_count' => DB::raw('today_count + 1'),
                    'total_money' => DB::raw('total_money + ' . $this->quoteDecimal((string)$order->price)),
                ]);

                $order->status = 1;
                // 支付结果已经确认后不再允许重试上游初始化，避免遗留“处理中”状态。
                $order->pay_init_status = 2;
                $order->channel_trade_no = mb_substr($channelTradeNo, 0, 128);
                $order->pay_time = time();
                $order->save();

                // 插件购买订单：自动下发授权并写入本地 Entitlements
                if ($bType === 'plugin_purchase' || str_starts_with((string)$order->param, 'plugin:')) {
                    try {
                        $paramParts = explode(':', (string)$order->param);
                        $pluginId = $paramParts[1] ?? '';
                        if ($pluginId !== '') {
                            $entitlementFile = runtime_path() . '/instance/entitlements.json';
                            $dir = dirname($entitlementFile);
                            if (!is_dir($dir)) {
                                @mkdir($dir, 0777, true);
                            }
                            $entitlements = [];
                            if (file_exists($entitlementFile)) {
                                $entitlements = json_decode((string)file_get_contents($entitlementFile), true) ?: [];
                            }
                            $entitlements[$pluginId] = [
                                'plugin_id'  => $pluginId,
                                'granted_at' => date('Y-m-d H:i:s'),
                                'type'       => 'PERMANENT',
                            ];
                            @file_put_contents($entitlementFile, json_encode($entitlements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                            // 更新临时订单文件
                            $orderFile = runtime_path() . "/orders/{$order->out_trade_no}.json";
                            if (file_exists($orderFile)) {
                                $oData = json_decode((string)file_get_contents($orderFile), true) ?: [];
                                $oData['status'] = 'PAID';
                                $oData['pay_time'] = time();
                                @file_put_contents($orderFile, json_encode($oData, JSON_UNESCAPED_UNICODE));
                            }

                            // 同步更新 AgentPluginLicense
                            if (class_exists(\app\model\AgentPluginLicense::class)) {
                                \app\model\AgentPluginLicense::updateOrCreate(
                                    ['domain' => $paramParts[2] ?? 'cs.fcwan.cn', 'plugin_id' => $pluginId],
                                    ['expire_time' => 0, 'auth_key' => bin2hex(random_bytes(16))]
                                );
                            }
                        }
                    } catch (\Throwable $licEx) {
                        error_log('[OrderService] 自动授权发放异常: ' . $licEx->getMessage());
                    }
                }

                // 套餐订阅直充订单：自动激活商户 VIP 套餐与手续费抵扣金
                if ($bType === 'plan_purchase' || str_starts_with((string)$order->param, 'plan:')) {
                    try {
                        $pParts = explode(':', (string)$order->param);
                        $targetPlanId = (int)($pParts[1] ?? 0);
                        $buyerId = isset($pParts[3]) ? (int)$pParts[3] : (int)$order->merchant_id;

                        $buyer = Merchant::find($buyerId);
                        $targetPlan = \app\model\Plan::find($targetPlanId);
                        if ($buyer && $targetPlan) {
                            $buyer->plan_id = $targetPlan->id;
                            $buyer->rate    = number_format((float)$targetPlan->rate / 100.0, 4, '.', '');

                            $pPrice = (float)$order->amount;
                            if ($pPrice > 0) {
                                $currDiscount = (float)($buyer->plan_fee_discount_balance ?? 0.00);
                                $buyer->plan_fee_discount_balance = number_format($currDiscount + $pPrice, 2, '.', '');
                            }

                            if ($targetPlan->channel_quota > 0) {
                                $buyer->channel_quota = $targetPlan->channel_quota;
                            }

                            $nowTs = time();
                            $currExp = (int)$buyer->plan_expire_time;
                            if ($targetPlan->days > 0) {
                                $baseTs = ($currExp > $nowTs) ? $currExp : $nowTs;
                                $buyer->plan_expire_time = $baseTs + ($targetPlan->days * 86400);
                            } else {
                                $buyer->plan_expire_time = 0;
                            }
                            $buyer->save();

                            // 写入商户套餐购买日志
                            \app\model\MerchantPlanLog::create([
                                'merchant_id' => $buyer->id,
                                'plan_id'     => $targetPlan->id,
                                'plan_name'   => $targetPlan->name,
                                'price'       => number_format($pPrice, 2, '.', ''),
                                'days'        => $targetPlan->days,
                                'rate'        => number_format((float)$targetPlan->rate, 2, '.', ''),
                                'create_time' => time(),
                            ]);

                            // 写入资金变动日志 (记录直充套餐购买)
                            UserMoneyLog::log(
                                (int)$buyer->id,
                                '0.00',
                                (string)$buyer->money,
                                (string)$buyer->money,
                                "在线扫码支付购买套餐【{$targetPlan->name}】¥" . number_format($pPrice, 2)
                            );
                        }
                    } catch (\Throwable $planEx) {
                        error_log('[OrderService] 在线购买套餐自动激活异常: ' . $planEx->getMessage());
                    }
                }

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

            // 派发系统/商户告警通知
            try {
                $alertSvc = new AlertNotificationService();
                $m = Merchant::find($result['order']->merchant_id);
                $pid = $m ? (string)$m->pid : '';
                $alertPayload = [
                    'trade_no' => $result['order']->trade_no,
                    'amount'   => number_format((float)$result['order']->price, 2, '.', ''),
                    'pid'      => $pid,
                ];
                $alertSvc->dispatchAdmin('order_paid', $alertPayload);
                if ($pid !== '') {
                    $alertSvc->dispatchMerchant($pid, 'order_paid', $alertPayload);

                    // 检查商户服务费低余额预警
                    if ($m) {
                        $mCfg = $alertSvc->getMerchantConfig($pid);
                        $threshold = (float)($mCfg['low_balance_threshold'] ?? 10.00);
                        $currentMoney = (float)($m->money ?? 0.00);
                        if ($currentMoney < $threshold) {
                            $alertSvc->dispatchMerchant($pid, 'low_balance', [
                                'balance'   => number_format($currentMoney, 2, '.', ''),
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

    /**
     * 关闭待支付订单，并在同一事务内释放已预占的手续费。
     */
    public function closePendingOrder(string $tradeNo, string $reason = '订单关闭'): bool
    {
        $targetOrder = Order::where('trade_no', $tradeNo)->first();
        if (!$targetOrder) {
            return false;
        }
        if ((int)$targetOrder->status === 2) {
            return true;
        }
        if ((int)$targetOrder->status !== 0) {
            return false;
        }
        $targetOrderId = (int)$targetOrder->id;
        $targetMerchantId = (int)$targetOrder->merchant_id;

        try {
            return DB::connection()->transaction(function () use (
                $targetOrderId,
                $targetMerchantId,
                $reason
            ): bool {
                // 先锁商户再锁订单，与建单及核销保持一致的锁顺序。
                $merchant = Merchant::where('id', $targetMerchantId)->lockForUpdate()->first();
                $order = Order::where('id', $targetOrderId)->lockForUpdate()->first();
                if (!$order) {
                    return false;
                }
                if ((int)$order->status === 2) {
                    return true;
                }
                if ((int)$order->status !== 0) {
                    return false;
                }

                $fee = $this->normalizeMoney($order->fee_amount ?? 0);
                $feeDiscount = $this->normalizeMoney($order->fee_discount_amount ?? 0);
                $feeMoney = bccomp($fee, $feeDiscount, 2) > 0 ? bcsub($fee, $feeDiscount, 2) : '0.00';

                if ((int)($order->fee_status ?? 0) === 1 && bccomp($fee, '0.00', 2) > 0) {
                    if (!$merchant) {
                        throw new RuntimeException('订单所属商户不存在，无法释放手续费');
                    }

                    // 1. 若曾预扣现金余额，退回现金余额
                    if (bccomp($feeMoney, '0.00', 2) > 0) {
                        $before = $this->normalizeMoney($merchant->money);
                        $after = bcadd($before, $feeMoney, 2);
                        $merchant->money = $after;
                        UserMoneyLog::log(
                            (int)$merchant->id,
                            '+' . $feeMoney,
                            $before,
                            $after,
                            "{$reason}，释放订单 {$order->trade_no} 预扣服务费余额"
                        );
                    }

                    // 2. 若曾抵扣套餐抵扣金，原路退回套餐抵扣金
                    if (bccomp($feeDiscount, '0.00', 2) > 0) {
                        $beforeDiscount = $this->normalizeMoney($merchant->plan_fee_discount_balance ?? 0);
                        $afterDiscount = bcadd($beforeDiscount, $feeDiscount, 2);
                        $merchant->plan_fee_discount_balance = $afterDiscount;
                        $curMoney = $this->normalizeMoney($merchant->money);
                        UserMoneyLog::log(
                            (int)$merchant->id,
                            '0.00',
                            $curMoney,
                            $curMoney,
                            "{$reason}，释放订单 {$order->trade_no} 套餐抵扣金 ¥{$feeDiscount}（抵扣金余额 ¥{$afterDiscount}）"
                        );
                    }

                    $merchant->save();
                    $order->fee_status = 3;
                }

                $order->status = 2;
                // 关闭订单会终止可能仍在进行的支付初始化，后续驱动结果不得再写回。
                if ((int)($order->pay_init_status ?? 0) === 1) {
                    $order->pay_init_status = 3;
                }
                $order->save();
                return true;
            }, 3);
        } catch (Throwable $e) {
            error_log('[OrderService] 关闭订单失败 trade_no=' . $tradeNo . ' error=' . $e->getMessage());
            return false;
        }
    }

    /**
     * 优先按平台流水号定位；仅为旧接入保留商户订单号的唯一匹配兼容。
     */
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

    /**
     * 分批关闭超时订单，避免定时任务一次锁定过多记录。
     */
    public function expirePendingOrders(int $limit = 500): int
    {
        $limit = max(1, min(2000, $limit));
        $tradeNumbers = Order::where('status', 0)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', time())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('trade_no');

        $closed = 0;
        foreach ($tradeNumbers as $tradeNo) {
            if ($this->closePendingOrder((string)$tradeNo, '订单超时')) {
                $closed++;
            }
        }
        return $closed;
    }

    /**
     * 安全删除单笔未完成订单（待支付/已超时/已作废订单）
     * 若处于待支付状态且预占了手续费，将先在同一事务中释放手续费后物理删除。
     */
    public function deleteUnfinishedOrder(string $tradeNo, ?int $merchantId = null): array
    {
        $tradeNo = trim($tradeNo);
        if ($tradeNo === '') {
            return ['code' => -1, 'msg' => '订单号不能为空'];
        }

        $query = Order::where('trade_no', $tradeNo);
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }
        $order = $query->first();
        if (!$order) {
            return ['code' => -1, 'msg' => '订单不存在或无权操作'];
        }

        if ((int)$order->status === 1) {
            return ['code' => -1, 'msg' => '已支付成功的订单涉及商户资金与账务流水，禁止删除'];
        }

        // 若处于待支付状态，先走关单流程退回预占手续费
        if ((int)$order->status === 0) {
            $this->closePendingOrder($tradeNo, '删除订单释放预占手续费');
        }

        $order->delete();

        return ['code' => 1, 'msg' => '未完成订单已成功删除'];
    }

    /**
     * 一键批量清理未完成/已超时订单
     */
    public function batchDeleteUnfinishedOrders(?int $merchantId = null, int $beforeSeconds = 300): int
    {
        $cutoffTime = time() - max(0, $beforeSeconds);
        $query = Order::whereIn('status', [0, 2, 3])
            ->where('create_time', '<=', $cutoffTime);

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        $pendingTradeNumbers = (clone $query)->where('status', 0)->limit(500)->pluck('trade_no');
        foreach ($pendingTradeNumbers as $pTradeNo) {
            $this->closePendingOrder((string)$pTradeNo, '批量清理未完成订单');
        }

        return (int)$query->delete();
    }

    /**
     * 定时任务自动清理超期废弃未完成订单（防止数据库长期膨胀）
     * 默认清理保留期（keepDays，默认15天）之前的已作废/超时订单。
     */
    public function autoPurgeArchivedOrders(int $keepDays = 15): int
    {
        $cutoffTime = time() - ($keepDays * 86400);
        $deleted = 0;
        
        // 分批清理，避免大事务锁表
        for ($i = 0; $i < 5; $i++) {
            $ids = Order::whereIn('status', [2, 3])
                ->where('create_time', '<', $cutoffTime)
                ->limit(1000)
                ->pluck('id');
            
            if ($ids->isEmpty()) {
                break;
            }
            
            $count = Order::whereIn('id', $ids->all())->delete();
            $deleted += $count;
            if ($count < 1000) {
                break;
            }
        }
        
        return $deleted;
    }

    private function selectChannel(int $merchantId, string $type, float $money): Channel
    {
        try {
            $result = $this->pollService->selectChannel($merchantId, $type, $money);
            $channel = Channel::find($result['channel_id']);
        } catch (Throwable) {
            $channel = null;
        }

        // 主通道不可用时，尝试 fallback_channel_id（主备通道自动故障转移）
        if (!$channel
            || !PaymentManager::has((string)$channel->c_type)
            || !$this->riskGuard->validateRisk($channel, $money)) {

            $fallback = null;
            if ($channel && (int)($channel->fallback_channel_id ?? 0) > 0) {
                $fallbackCandidate = Channel::find((int)$channel->fallback_channel_id);
                if ($fallbackCandidate
                    && (int)$fallbackCandidate->status === 1
                    && (int)$fallbackCandidate->online_status === 1
                    && PaymentManager::has((string)$fallbackCandidate->c_type)
                    && $this->riskGuard->validateRisk($fallbackCandidate, $money)) {
                    $fallback = $fallbackCandidate;
                }
            }

            if ($fallback) {
                $channel = $fallback;
            } else {
                // T12：兜底改用权重加权随机，与 PollService::weightedRandom 算法一致，
                // 避免全部流量压向权重最高的单一通道（原 orderBy+first 行为）。
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
                        && $this->riskGuard->validateRisk($candidate, $money))
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

    private function assertChannelReady(Channel $channel): void
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

    private function formatOrderResult(Order $order): array
    {
        return [
            'trade_no' => (string)$order->trade_no,
            'money' => $this->normalizeMoney($order->amount),
            'price' => $this->normalizeMoney($order->price),
            'pay_type' => (string)$order->pay_type,
            'business_type' => (string)($order->business_type ?? 'payment'),
            'status' => (int)$order->status,
            'pay_url' => (string)($order->pay_url ?? ''),
            'pay_mode' => (string)($order->pay_mode ?? 'qrcode'),
        ];
    }

    /**
     * 调用订单绑定的支付驱动并持久化出码结果，重复请求复用已有结果。
     */
    private function preparePayment(Order $order, array $originalParams, string $gatewayBaseUrl): array
    {
        [$order, $claimed, $claimTime] = DB::connection()->transaction(function () use ($order): array {
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
            if (!$lockedOrder) {
                throw new RuntimeException('订单不存在');
            }
            if ((string)($lockedOrder->pay_url ?? '') !== '' || (int)$lockedOrder->status !== 0) {
                return [$lockedOrder, false, 0];
            }

            $initStatus = (int)($lockedOrder->pay_init_status ?? 0);
            $initTime = (int)($lockedOrder->pay_init_time ?? 0);
            if ($initStatus === 1 && $initTime > time() - 30) {
                throw new RuntimeException('订单正在初始化支付通道，请稍后重试查询');
            }
            $claimTime = time();
            $lockedOrder->pay_init_status = 1;
            $lockedOrder->pay_init_time = $claimTime;
            $lockedOrder->save();
            return [$lockedOrder, true, $claimTime];
        }, 3);

        if (!$claimed) {
            return $this->formatOrderResult($order);
        }

        try {
            $channel = Channel::find($order->channel_id);
            if (!$channel || (int)$channel->status !== 1 || !PaymentManager::has((string)$channel->c_type)) {
                throw new RuntimeException('订单绑定的支付驱动不可用');
            }

            $config = $this->decryptChannelConfig($channel);
            $config['channel_id'] = (int)$channel->id;
            $baseUrl = rtrim($gatewayBaseUrl, '/');
            if ($baseUrl === '') {
                throw new RuntimeException('支付网关地址未配置');
            }

            // 上游订单号统一使用平台流水号，确保回调可以精确定位本平台订单。
            $driverParams = $originalParams;
            $driverParams['trade_no'] = (string)$order->trade_no;
            $driverParams['out_trade_no'] = (string)$order->trade_no;
            $driverParams['merchant_out_trade_no'] = (string)$order->out_trade_no;
            $driverParams['money'] = $this->normalizeMoney($order->price);
            $driverParams['expire_time'] = (int)$order->expire_time;
            $driverParams['name'] = (string)$order->subject;
            $driverParams['notify_url'] = $baseUrl . '/notify/' . rawurlencode((string)$channel->c_type);
            $driverParams['return_url'] = (string)$order->return_url;

            $payResult = PaymentManager::make((string)$channel->c_type)->pay($driverParams, $config);
            $payUrl = trim((string)($payResult['pay_url'] ?? ''));
            $payMode = trim((string)($payResult['type'] ?? 'qrcode'));
            if ($payUrl === '' || !in_array($payMode, ['url', 'qrcode'], true)) {
                throw new RuntimeException('支付驱动未返回有效的支付地址');
            }

            $order = DB::connection()->transaction(function () use ($order, $payUrl, $payMode, $claimTime): Order {
                $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();
                if (!$lockedOrder) {
                    throw new RuntimeException('订单不存在');
                }
                if ((int)$lockedOrder->status === 0 && (string)($lockedOrder->pay_url ?? '') === '') {
                    if (
                        (int)($lockedOrder->pay_init_status ?? 0) !== 1
                        || (int)($lockedOrder->pay_init_time ?? 0) !== $claimTime
                    ) {
                        throw new RuntimeException('支付初始化已由其他请求接管，请稍后查询');
                    }
                    $lockedOrder->pay_url = $payUrl;
                    $lockedOrder->pay_mode = $payMode;
                    $lockedOrder->pay_init_status = 2;
                    $lockedOrder->save();
                }
                return $lockedOrder;
            }, 3);
            return $this->formatOrderResult($order);
        } catch (Throwable $e) {
            try {
                Order::where('id', $order->id)
                    ->where('pay_init_status', 1)
                    ->where('pay_init_time', $claimTime)
                    ->update(['pay_init_status' => 3]);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    /**
     * 商户级下单限频（Redis 滑动窗口：60秒内最多 30 次）。
     * Redis 不可用时 fail-open 降级放行，避免缓存故障影响正常支付。
     *
     * @throws RuntimeException 超出限频时抛出
     */
    private function enforceOrderRateLimit(int $merchantId): void
    {
        /** 窗口时长（秒） */
        $windowSeconds = 60;
        /** 窗口内最大下单次数 */
        $maxRequests = 30;

        try {
            $redis = \Webman\Redis\Client::connection();
            $now   = (int)(microtime(true) * 1000); // 毫秒时间戳
            $key   = 'cx:rate_limit:order:' . $merchantId;
            $windowStart = $now - $windowSeconds * 1000;

            // 移除窗口外的旧成员，加入当前时间戳，统计窗口内请求数
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
            // 限频异常直接向上抛出
            throw $e;
        } catch (\Throwable) {
            // Redis 故障：fail-open，允许请求继续
            error_log('[OrderService] 限频 Redis 不可用，降级放行 merchant_id=' . $merchantId);
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

    private function normalizeMoney(mixed $amount): string
    {
        if (!is_numeric($amount)) {
            return '0.00';
        }
        return number_format((float)$amount, 2, '.', '');
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

    private function quoteDecimal(string $amount): string
    {
        $normalized = $this->normalizeMoney($amount);
        if (!preg_match('/^\d+\.\d{2}$/', $normalized)) {
            throw new RuntimeException('金额格式不合法');
        }
        return $normalized;
    }

    private function isHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
