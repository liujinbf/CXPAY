<?php

declare(strict_types=1);

namespace app\service;

use app\model\Callbill;
use app\model\Order;
use app\service\OrderService;
use Throwable;

/**
 * 挂机助手账单监听与自动挂账匹配服务 (含数据库匹配与幂等回调)
 */
class CallbillService
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 接收挂机助手推送的账单消息
     *
     * 修复：新增 channelId 参数，Order 查询时增加 channel_id 过滤，
     * 防止 A 通道收到的金额错误核销 B 通道的同等金额待付订单
     *
     * @param  string $appName   应用类型 (alipay_asst/wxpay_asst)
     * @param  string $deviceId  挂机设备唯一标识
     * @param  float  $money     到账金额
     * @param  string $remark    账单备注/备注单号
     * @param  int    $channelId 来源通道 ID（必须精确指定）
     * @param  string $sourceBillId 收款端稳定账单唯一标识
     * @param  int    $occurredAt 账单实际发生时间
     */
    public function processPush(
        string $appName,
        string $deviceId,
        float  $money,
        string $remark,
        int    $channelId = 0,
        string $sourceBillId = '',
        int    $occurredAt = 0,
        string $rawHash = '',
        string $clientVersion = ''
    ): array {
        if ($channelId <= 0) {
            return ['success' => false, 'msg' => '必须指定有效的来源通道'];
        }
        if ($sourceBillId === '' || $occurredAt <= 0) {
            return ['success' => false, 'msg' => '账单唯一标识或发生时间不能为空'];
        }

        // 部分通知监听API无法提供支付平台账单号。短时间内相同通知摘要作为第二道去重保护，
        // 配合订单金额冷却期可阻止同一真实到账因系统重复通知而核销下一笔订单。
        if ($rawHash !== '') {
            $sameNotification = Callbill::where('channel_id', $channelId)
                ->where('raw_hash', $rawHash)
                ->whereBetween('occurred_at', [$occurredAt - 120, $occurredAt + 120])
                ->first();
            if ($sameNotification) {
                return $this->duplicateResult($sameNotification);
            }
        }

        // source_bill_id 是业务幂等键。nonce 只防短期网络重放，不能替代真实账单去重。
        $existing = Callbill::where('channel_id', $channelId)
            ->where('source_bill_id', $sourceBillId)
            ->first();
        if ($existing) {
            return $this->duplicateResult($existing);
        }

        // 1. 写入原始账单日志（记录 channel_id 便于追溯来源通道）
        try {
            $bill = Callbill::create([
                'app_name'      => $appName,
                'device_id'     => $deviceId,
                'source_bill_id'=> $sourceBillId,
                'money'         => $money,
                'remark'        => $remark,
                'channel_id'    => $channelId,
                'occurred_at'   => $occurredAt,
                'raw_hash'      => $rawHash,
                'client_version'=> $clientVersion,
                'status'        => 0,
                'create_time'   => time(),
            ]);
        } catch (Throwable $e) {
            // 并发上报可能同时通过上面的只读检查，最终由数据库唯一索引裁决。
            $existing = Callbill::where('channel_id', $channelId)
                ->where('source_bill_id', $sourceBillId)
                ->first();
            if ($existing) {
                return $this->duplicateResult($existing);
            }
            throw $e;
        }

        // 2. 匹配"待支付"且在有效期内、实际支付金额（price）一致的单据
        //    注意：助手上报的是到账金额，对应 price 字段（含微浮动），而非原始 amount
        $now          = time();
        $priceFormatted = number_format($money, 2, '.', '');

        $query = Order::where('status', 0)
            ->where('price', $priceFormatted)
            ->where('create_time', '<=', $occurredAt + 90)
            ->where('expire_time', '>=', $occurredAt)
            ->where('expire_time', '>', $now);

        $query->where('channel_id', $channelId);

        $orders = $query->orderBy('id', 'desc')->limit(2)->get();

        if ($orders->count() !== 1) {
            if ($orders->count() > 1) {
                $bill->status = 3;
                $message = '存在多笔候选订单，账单已转人工复核';
            } else {
                $historicalCandidate = Order::where('channel_id', $channelId)
                    ->where('price', $priceFormatted)
                    ->where('create_time', '<=', $occurredAt + 90)
                    ->where('expire_time', '>=', $occurredAt)
                    ->exists();

                $bill->status = $historicalCandidate ? 3 : 2;
                $message = $historicalCandidate
                    ? '订单已超时或状态已变化，账单已转人工复核'
                    : '当前通道暂无匹配的待支付订单';
            }
            $bill->save();
            return ['success' => false, 'msg' => $message, 'bill_id' => (int)$bill->id];
        }
        $order = $orders->first();

        // 3. 自动挂账并标记订单已支付
        $paid = $this->orderService->markAsPaid(
            (string)$order->trade_no,
            'ASST_' . $bill->id,
            $money,
            (int)$order->channel_id,
            true
        );
        if (!$paid) {
            $bill->status = 2;
            $bill->save();
            return ['success' => false, 'msg' => '订单核销校验失败'];
        }

        $bill->trade_no = $order->trade_no;
        $bill->order_id = $order->id;
        $bill->status = 1;
        $bill->save();

        return [
            'success'          => true,
            'matched_trade_no' => $order->trade_no,
            'bill_id'          => (int)$bill->id,
        ];
    }

    private function duplicateResult(Callbill $bill): array
    {
        return [
            'success' => true,
            'duplicate' => true,
            'msg' => '账单已接收，无需重复处理',
            'bill_id' => (int)$bill->id,
            'matched_trade_no' => (string)($bill->trade_no ?? ''),
            'bill_status' => (int)$bill->status,
        ];
    }

    /**
     * 管理员将待复核账单绑定到仍处于待支付状态的订单。
     * 账单与订单必须通道、金额完全一致，不能借人工入口绕过核心核销不变量。
     */
    public function reviewMatch(int $billId, string $tradeNo, string $operator): array
    {
        $tradeNo = trim($tradeNo);
        if ($billId <= 0 || $tradeNo === '') {
            return ['success' => false, 'msg' => '账单ID和订单号不能为空'];
        }

        $bill = Callbill::find($billId);
        if (!$bill || !in_array((int)$bill->status, [2, 3], true)) {
            return ['success' => false, 'msg' => '账单不存在或已被其他操作处理'];
        }
        $originalStatus = (int)$bill->status;

        // 原子认领：将 status 从 2/3 改为 4（处理中），防止并发复核
        $claimed = Callbill::where('id', $billId)
            ->whereIn('status', [2, 3])
            ->update([
                'status'      => 4,
                'review_note' => mb_substr("{$operator} 正在复核", 0, 255),
            ]);
        if ($claimed !== 1) {
            return ['success' => false, 'msg' => '账单已被其他管理员处理，请刷新列表'];
        }

        // $success = true 时直接 return，不再经过失败回滚路径
        $result = null;
        try {
            $bill  = Callbill::find($billId);
            $order = Order::where('trade_no', $tradeNo)->first();
            if (!$order || (int)$order->status !== 0) {
                $result = ['success' => false, 'msg' => '目标订单不存在或已不是待支付状态'];
            } elseif ((int)$order->channel_id !== (int)$bill->channel_id) {
                $result = ['success' => false, 'msg' => '账单来源通道与目标订单不一致'];
            } elseif (bccomp((string)$order->price, (string)$bill->money, 2) !== 0) {
                $result = ['success' => false, 'msg' => '账单金额与目标订单实付金额不一致'];
            } elseif ((int)$order->create_time > (int)$bill->occurred_at + 10
                || (int)$order->expire_time < (int)$bill->occurred_at) {
                $result = ['success' => false, 'msg' => '账单发生时间不在目标订单的有效支付窗口内'];
            }

            if ($result !== null) {
                // 前置校验失败，释放认领锁后返回
                Callbill::where('id', $billId)->where('status', 4)->update([
                    'status'      => $originalStatus,
                    'review_note' => mb_substr("{$operator} 复核校验未通过：{$result['msg']}", 0, 255),
                ]);
                return $result;
            }

            $reviewTradeNo = 'REVIEW_' . $billId;
            $paid = $this->orderService->markAsPaid(
                (string)$order->trade_no,
                $reviewTradeNo,
                (float)$bill->money,
                (int)$bill->channel_id,
                true
            );
            if (!$paid) {
                Callbill::where('id', $billId)->where('status', 4)->update([
                    'status'      => $originalStatus,
                    'review_note' => mb_substr("{$operator} 核销失败，订单状态已变化", 0, 255),
                ]);
                return ['success' => false, 'msg' => '订单状态已变化或核销失败'];
            }

            // 再次确认本次操作是赢家（防止 markAsPaid 内部并发竞争）
            $settledOrder = Order::find($order->id);
            if (!$settledOrder || !hash_equals($reviewTradeNo, (string)$settledOrder->channel_trade_no)) {
                // 订单被其他事件抢先核销，将账单状态恢复，不能让此账单凭空消耗掉
                Callbill::where('id', $billId)->where('status', 4)->update([
                    'status'      => $originalStatus,
                    'review_note' => mb_substr("{$operator} 订单已被其他事件抢先核销", 0, 255),
                ]);
                return ['success' => false, 'msg' => '订单已由其他到账事件抢先核销，请重新选择订单'];
            }

            // 成功：将账单状态最终写为 1（已匹配）
            Callbill::where('id', $billId)->where('status', 4)->update([
                'order_id'    => (int)$order->id,
                'trade_no'    => (string)$order->trade_no,
                'status'      => 1,
                'review_note' => mb_substr("{$operator} 人工匹配订单 {$order->trade_no}", 0, 255),
            ]);
            return ['success' => true, 'msg' => '账单人工匹配并核销成功'];

        } catch (Throwable $e) {
            // 意外异常：释放认领锁，保持账单可重试
            error_log('[CallbillService] reviewMatch 异常 bill_id=' . $billId . ' ' . $e->getMessage());
            Callbill::where('id', $billId)->where('status', 4)->update([
                'status'      => $originalStatus,
                'review_note' => mb_substr("{$operator} 复核异常，已自动释放", 0, 255),
            ]);
            return ['success' => false, 'msg' => '复核处理异常，账单已释放，请重试'];
        }
    }


    public function ignoreReview(int $billId, string $operator, string $reason): array
    {
        $reason = trim($reason);
        if ($billId <= 0 || $reason === '') {
            return ['success' => false, 'msg' => '账单ID和忽略原因不能为空'];
        }
        $updated = Callbill::where('id', $billId)
            ->whereIn('status', [2, 3])
            ->update([
                'status' => 5,
                'review_note' => mb_substr("{$operator} 忽略：{$reason}", 0, 255),
            ]);
        return $updated === 1
            ? ['success' => true, 'msg' => '账单已标记为忽略']
            : ['success' => false, 'msg' => '账单不存在或状态已变化'];
    }
}
