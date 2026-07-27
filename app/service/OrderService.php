<?php

declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\Merchant;
use app\model\Channel;
use app\model\UserMoneyLog;
use app\service\MerchantNotifyService;
use app\service\RiskGuardService;
use app\service\PollService;
use support\SnowFlake;
use support\Sign;
use Illuminate\Database\Capsule\Manager as DB;
use Exception;

/**
 * 完整 OrderService
 * 修复：
 *   - 统一使用 PollService 权重算法选通道（废弃独立 foreach 逻辑）
 *   - 兜底通道选择必须保留 c_type 过滤，防止跨类型路由
 *   - 余额扣费改为原子 SQL UPDATE 防并发竞态
 *   - 增加 UserMoneyLog 手续费明细记录
 */
class OrderService
{
    protected MerchantNotifyService $notifyService;
    protected RiskGuardService $riskGuard;
    protected PollService $pollService;

    public function __construct()
    {
        $this->notifyService = new MerchantNotifyService();
        $this->riskGuard     = new RiskGuardService();
        $this->pollService   = new PollService();
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

        // 4) 通过 PollService 权重算法智能选取通道（统一入口，废弃独立 foreach）
        $selectedChannel = null;
        try {
            $channelResult   = $this->pollService->selectChannel($merchant->id, $type, $money);
            $selectedChannel = Channel::find($channelResult['channel_id']);
        } catch (Exception) {
            // PollService 无通道时兜底：必须保留 c_type 前缀过滤，防止路由到错误类型通道
            $selectedChannel = Channel::where('c_type', 'LIKE', $type . '%')
                ->where('status', 1)
                ->orderBy('weight', 'desc')
                ->first();

            if (!$selectedChannel) {
                throw new Exception('暂无满足条件的可用支付通道，请稍后重试');
            }
        }

        // 5) RiskGuard 风控二次校验（日限额、单笔限额、在线状态）
        if (!$this->riskGuard->validateRisk($selectedChannel, $money)) {
            // 风控拦截后尝试同类型其他通道（保留 c_type 前缀过滤）
            $fallback = Channel::where('c_type', 'LIKE', $type . '%')
                ->where('status', 1)
                ->where('id', '!=', $selectedChannel->id)
                ->orderBy('weight', 'desc')
                ->first();

            if (!$fallback || !$this->riskGuard->validateRisk($fallback, $money)) {
                throw new Exception('当前支付通道已触发风控限制，请稍后重试');
            }
            $selectedChannel = $fallback;
        }

        // 6) 智能金额随机微浮动与去重（防封 + 精确识别）
        $finalPrice = $money;
        if (!empty($merchant->pay_float_min) && (float)$merchant->pay_float_min > 0) {
            $finalPrice = $this->riskGuard->generateSmartFloatMoney(
                $money,
                (float)$merchant->pay_float_min,
                (float)($merchant->pay_float_max ?? 0.09)
            );
        }

        // 同通道待支付相同金额冲突时逐次追加 0.01（最多尝试 3 次）
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $conflict = Order::where('channel_id', $selectedChannel->id)
                ->where('price', number_format($finalPrice, 2, '.', ''))
                ->where('status', 0)
                ->where('expire_time', '>', time())
                ->exists();

            if (!$conflict) {
                break;
            }
            $finalPrice += 0.01;
        }

        // 7) 建单
        $now     = time();
        $outTime = max(60, (int)($merchant->pay_outtime ?? 180));
        $tradeNo = 'CX' . SnowFlake::makeId();

        Order::create([
            'merchant_id'   => $merchant->id,
            'out_trade_no'  => $outTradeNo,
            'trade_no'      => $tradeNo,
            'channel_id'    => $selectedChannel->id,
            'pay_type'      => $type,
            'amount'        => $money,
            'price'         => number_format($finalPrice, 2, '.', ''),
            'subject'       => $name,
            'notify_url'    => $notifyUrl,
            'return_url'    => $returnUrl,
            'notify_status' => 0,
            'status'        => 0,
            'create_time'   => $now,
            'expire_time'   => $now + $outTime,
        ]);

        return [
            'trade_no' => $tradeNo,
            'money'    => number_format($money, 2, '.', ''),
            'price'    => number_format($finalPrice, 2, '.', ''),
            'pay_type' => $type,
        ];
    }

    /**
     * 标记订单支付成功 + 原子扣除商户手续费（防并发竞态）
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

        // 原子性更新：只有当数据库中 status 仍为 0 时才成功更新为 1，防并发重复核销
        $updated = Order::where('id', $order->id)
            ->where('status', 0)
            ->update([
                'status'           => 1,
                'channel_trade_no' => $channelTradeNo,
                'pay_time'         => time(),
            ]);

        if (!$updated) {
            // 被并发协程率先完成核销，视为成功
            return true;
        }

        $order->refresh();

        // 原子扣除商户余额手续费（SQL 表达式防并发竞态，避免 Read-Modify-Write 问题）
        $merchant = Merchant::find($order->merchant_id);
        if ($merchant) {
            $rateStr = (string)($merchant->rate ?? '0.02');
            $feeStr  = number_format((float)bcmul((string)$order->amount, $rateStr, 4), 2, '.', '');

            if (bccomp($feeStr, '0.00', 2) > 0) {
                $beforeMoney = (float)$merchant->money;

                // 使用数据库原子表达式：SET money = GREATEST(0, money - fee)
                DB::table('cx_merchant')
                    ->where('id', $order->merchant_id)
                    ->update([
                        'money' => DB::raw("GREATEST(0.00, CAST(money AS DECIMAL(10,4)) - {$feeStr})"),
                    ]);

                $merchant->refresh();
                $afterMoney = (float)$merchant->money;

                // 写入余额变动明细日志（便于对账审计）
                UserMoneyLog::log(
                    $order->merchant_id,
                    -(float)$feeStr,
                    $beforeMoney,
                    $afterMoney,
                    "订单 {$order->trade_no} 手续费扣除"
                );
            }
        }

        // 触发异步回调通知商户
        $this->notifyService->notifyMerchant($order);

        return true;
    }

    /**
     * 手动重发/补发商户异步 HTTP 回调通知 (Re-notify)
     */
    public function resendNotify(string $tradeNo): array
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return ['code' => -1, 'msg' => '订单不存在'];
        }
        if ((int)$order->status !== 1) {
            return ['code' => -1, 'msg' => '未支付订单无法重新发送通知'];
        }

        $result = $this->notifyService->notifyMerchant($order);

        return [
            'code'   => 1,
            'msg'    => '成功重新向商户异步通知 URL 发起回调推送！',
            'detail' => $result,
        ];
    }
}
