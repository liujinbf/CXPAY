<?php

declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\Merchant;
use app\model\Channel;
use app\model\Packvip;
use app\service\MerchantNotifyService;
use app\service\RiskGuardService;
use support\SnowFlake;
use support\Sign;
use Exception;

/**
 * 完整 OrderService (集成 RiskGuardService 智能防封与风控检测)
 */
class OrderService
{
    protected MerchantNotifyService $notifyService;
    protected RiskGuardService $riskGuard;

    public function __construct()
    {
        $this->notifyService = new MerchantNotifyService();
        $this->riskGuard     = new RiskGuardService();
    }

    /**
     * 下单网关核心逻辑：验签 -> 风控检测 -> 智能分流 -> 金额去重 -> 建单
     */
    public function createOrder(array $params): array
    {
        $pid = $params['pid'] ?? '';
        if (empty($pid)) {
            throw new Exception('商户 PID (pid) 不能为空');
        }

        // 1) 查询商户
        $merchant = Merchant::where('pid', $pid)->first();
        if (!$merchant) {
            throw new Exception('商户不存在');
        }
        if ((int)$merchant->status !== 1) {
            throw new Exception('商户已被停用，无法发起支付');
        }

        // 2) 验签
        if (!Sign::verifySign($params, $merchant->key)) {
            throw new Exception('签名校验失败，请检查对接密钥');
        }

        $outTradeNo = (string)($params['out_trade_no'] ?? '');
        $notifyUrl  = (string)($params['notify_url'] ?? '');
        $returnUrl  = (string)($params['return_url'] ?? '');
        $name       = (string)($params['name'] ?? '网络支付');
        $money      = (float)($params['money'] ?? 0);
        $type       = (string)($params['type'] ?? 'alipay');

        if (empty($outTradeNo) || empty($notifyUrl) || $money <= 0) {
            throw new Exception('必填字段 (out_trade_no / notify_url / money) 不能为空');
        }

        // 3) 校验商户 VIP 有效期与账户余额
        if (!empty($merchant->packvip_time) && $merchant->packvip_time < time()) {
            throw new Exception('商户 VIP 套餐已过期，请前往商户后台续费');
        }

        $rate = (float)($merchant->rate ?? 0.02);
        $fee  = round($money * $rate, 2);
        if ($fee > 0 && (float)$merchant->money < $fee) {
            throw new Exception("商户余额不足（需至少包含手续费 ¥{$fee}），请先充值");
        }

        // 4) 选可用通道，并通过 RiskGuard 进行防封风控校验
        $channels = Channel::where('c_type', $type)->where('status', 1)->get();
        $selectedChannel = null;

        foreach ($channels as $channel) {
            if ($this->riskGuard->validateRisk($channel, $money)) {
                $selectedChannel = $channel;
                break;
            }
        }

        if (!$selectedChannel) {
            $selectedChannel = Channel::where('status', 1)->first();
            if (!$selectedChannel) {
                throw new Exception('暂无满足风控条件的可用支付通道，请稍后重试');
            }
        }

        // 5) 智能金额随机微浮动与去重 (防封+精确识别)
        $finalPrice = $money;
        if (!empty($merchant->pay_float_min) && (float)$merchant->pay_float_min > 0) {
            $finalPrice = $this->riskGuard->generateSmartFloatMoney(
                $money,
                (float)$merchant->pay_float_min,
                (float)($merchant->pay_float_max ?? 0.09)
            );
        }

        // 同通道待支付相同金额冲突时追加 0.01
        $samePriceOrder = Order::where('channel_id', $selectedChannel->id)
            ->where('price', number_format($finalPrice, 2, '.', ''))
            ->where('status', 0)
            ->where('expire_time', '>', time())
            ->first();

        if ($samePriceOrder) {
            $finalPrice += 0.01;
        }

        // 6) 建单
        $now = time();
        $outTime = max(60, (int)($merchant->pay_outtime ?? 180));
        $tradeNo = 'CX' . SnowFlake::makeId();

        $order = Order::create([
            'merchant_id'  => $merchant->id,
            'out_trade_no' => $outTradeNo,
            'trade_no'     => $tradeNo,
            'channel_id'   => $selectedChannel->id,
            'pay_type'     => $type,
            'amount'       => $money,
            'price'        => number_format($finalPrice, 2, '.', ''),
            'subject'      => $name,
            'notify_url'   => $notifyUrl,
            'return_url'   => $returnUrl,
            'status'       => 0,
            'create_time'  => $now,
            'expire_time'  => $now + $outTime,
        ]);

        return [
            'trade_no' => $tradeNo,
            'money'    => number_format($money, 2, '.', ''),
            'price'    => number_format($finalPrice, 2, '.', ''),
            'pay_type' => $type,
        ];
    }

    /**
     * 标记订单支付成功 + 扣除商户手续费余额 (包含并发原子排他防护与高精度计费)
     */
    public function markAsPaid(string $outTradeNo, string $channelTradeNo, float $amount): bool
    {
        $order = Order::where('out_trade_no', $outTradeNo)->first();
        if (!$order) {
            return false;
        }

        if ((int)$order->status === 1) {
            return true;
        }

        // 原子性更新：只有当数据库中 status 仍为 0 时才成功更新为 1
        $updated = Order::where('id', $order->id)
            ->where('status', 0)
            ->update([
                'status'           => 1,
                'channel_trade_no' => $channelTradeNo,
                'pay_time'         => time(),
            ]);

        if (!$updated) {
            // 表示被并发的并发线程/协程率先完成核销
            return true;
        }

        // 刷新模型状态
        $order->refresh();

        // 扣除商户余额手续费 (采用高精度运算)
        $merchant = Merchant::find($order->merchant_id);
        if ($merchant) {
            $rateStr   = (string)($merchant->rate ?? '0.02');
            $amountStr = (string)$order->amount;
            
            // 手续费 = amount * rate
            $feeStr = bcmul($amountStr, $rateStr, 4);
            $feeStr = number_format((float)$feeStr, 2, '.', '');

            if (bccomp($feeStr, '0.00', 2) > 0) {
                $currentMoney = (string)($merchant->money ?? '0.00');
                $newMoneyStr  = bcsub($currentMoney, $feeStr, 4);
                if (bccomp($newMoneyStr, '0.00', 2) < 0) {
                    $newMoneyStr = '0.00';
                }
                $merchant->money = number_format((float)$newMoneyStr, 2, '.', '');
                $merchant->save();
            }
        }

        // 触发异步回调通知商户
        $this->notifyService->notifyMerchant($order);

        return true;
    }
}
