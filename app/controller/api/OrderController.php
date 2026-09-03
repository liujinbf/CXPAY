<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use support\Authcode;
use support\Request;

/**
 * 收银台订单查询与商户订单列表。
 */
class OrderController
{
    public function query(Request $request)
    {
        $params = $request->get() + $request->post();
        $tradeNo = trim((string)($params['trade_no'] ?? ''));
        $outTradeNo = trim((string)($params['out_trade_no'] ?? ''));
        $merchant = $request->context['merchant'] ?? null;

        if ($tradeNo !== '') {
            $order = Order::where('trade_no', $tradeNo)->first();
        } elseif ($outTradeNo !== '' && $merchant instanceof Merchant) {
            $order = Order::where('merchant_id', $merchant->id)
                ->where('out_trade_no', $outTradeNo)
                ->first();
        } else {
            return $this->jsonResponse(['code' => -1, 'msg' => '请提供平台流水号；商户订单号查询必须使用签名接口']);
        }

        if (!$order) {
            return $this->jsonResponse(['code' => -1, 'msg' => '订单不存在']);
        }

        // 若订单未支付且绑定了通道，主动向上游渠道查询最新支付状态进行补偿核销
        if ((int)$order->status !== 1 && !empty($order->channel_id)) {
            try {
                $channel = Channel::find($order->channel_id);
                if ($channel && (int)$channel->status === 1 && \app\payment\PaymentManager::has((string)$channel->c_type)) {
                    $config = $this->decryptConfig($channel->config);
                    $config['channel_id'] = (int)$channel->id;
                    $driver = \app\payment\PaymentManager::make((string)$channel->c_type);
                    $queryRes = $driver->query((string)$order->trade_no, $config);
                    if (!empty($queryRes['paid'])) {
                        $orderService = new \app\service\OrderService();
                        $channelTradeNo = (string)($queryRes['trade_no'] ?? '');
                        $amount = (float)($queryRes['amount'] ?? $order->price);
                        $orderService->markAsPaid(
                            (string)$order->trade_no,
                            $channelTradeNo,
                            $amount,
                            (int)$channel->id,
                            false
                        );
                        // 重新载入核销后的订单最新数据
                        $order = Order::where('id', $order->id)->first() ?: $order;
                    }
                }
            } catch (\Throwable $ex) {
                error_log('[OrderController] 订单主动查询补偿异常 trade_no=' . $order->trade_no . ' error=' . $ex->getMessage());
            }
        }

        $payUrl = '';
        if ((int)$order->status === 0 && (int)$order->expire_time > time()) {
            $payUrl = (string)($order->pay_url ?? '');
            if ($payUrl === '') {
                $channel = Channel::find($order->channel_id);
                if ($channel !== null) {
                    $config = $this->decryptConfig($channel->config);
                    $payUrl = trim((string)($config['qr_url'] ?? $config['qr_code_url'] ?? $config['qrcode'] ?? ''));
                }
            }
        }

        return $this->jsonResponse([
            'code' => 1,
            'msg' => '查询成功',
            'data' => [
                'trade_no' => (string)$order->trade_no,
                'out_trade_no' => (string)$order->out_trade_no,
                'amount' => number_format((float)$order->amount, 2, '.', ''),
                'money' => number_format((float)$order->price, 2, '.', ''),
                'price' => number_format((float)$order->price, 2, '.', ''),
                'subject' => (string)$order->subject,
                'status' => (int)$order->status,
                'pay_type' => (string)$order->pay_type,
                'business_type' => (string)($order->business_type ?? 'payment'),
                'pay_url' => $payUrl,
                'pay_mode' => (string)($order->pay_mode ?? 'qrcode'),
                'return_url' => (string)$order->return_url,
                'create_time' => (int)$order->create_time,
                'expire_time' => (int)$order->expire_time,
                'pay_time' => (int)$order->pay_time,
            ],
        ]);
    }

    public function list(Request $request)
    {
        $merchant = $request->context['merchant'] ?? null;
        if (!$merchant instanceof Merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $orders = Order::where('merchant_id', $merchant->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->map(static function (Order $order): array {
                return [
                    'trade_no' => (string)$order->trade_no,
                    'out_trade_no' => (string)$order->out_trade_no,
                    'amount' => number_format((float)$order->amount, 2, '.', ''),
                    'price' => number_format((float)$order->price, 2, '.', ''),
                    'pay_type' => (string)$order->pay_type,
                    'business_type' => (string)($order->business_type ?? 'payment'),
                    'subject' => (string)$order->subject,
                    'status' => (int)$order->status,
                    'notify_status' => (int)$order->notify_status,
                    'create_time' => date('Y-m-d H:i:s', (int)$order->create_time),
                    'pay_time' => !empty($order->pay_time) ? date('Y-m-d H:i:s', (int)$order->pay_time) : '-',
                ];
            })
            ->all();

        return json(['code' => 1, 'msg' => '获取成功', 'data' => $orders]);
    }

    /**
     * 商户手动补单
     */
    public function manualPay(Request $request)
    {
        $merchant = $request->context['merchant'] ?? null;
        if (!$merchant instanceof Merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $tradeNo = trim((string)($request->post('trade_no') ?? ''));
        if ($tradeNo === '') {
            return json(['code' => -1, 'msg' => '订单流水号不能为空']);
        }

        $order = Order::where('trade_no', $tradeNo)
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$order) {
            return json(['code' => -1, 'msg' => '订单不存在或无权操作']);
        }

        if ((int)$order->status === 1) {
            return json(['code' => 1, 'msg' => '该订单已经是已完成状态，无需补单']);
        }

        $orderService = new \app\service\OrderService();
        $success = $orderService->markAsPaid(
            $tradeNo,
            'MANUAL_MERCHANT_' . time(),
            (float)$order->price,
            (int)$order->channel_id,
            false
        );

        if (!$success) {
            return json(['code' => -1, 'msg' => '手动补单失败，请检查订单状态']);
        }

        return json(['code' => 1, 'msg' => '🎉 订单已手动补单成功，已触发统一结算与回调通知！']);
    }

    /**
     * 商户删除单笔未完成订单
     */
    public function delete(Request $request)
    {
        $merchant = $request->context['merchant'] ?? null;
        if (!$merchant instanceof Merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $tradeNo = trim((string)($request->post('trade_no') ?? ''));
        if ($tradeNo === '') {
            return json(['code' => -1, 'msg' => '订单流水号不能为空']);
        }

        $orderService = new \app\service\OrderService();
        $result = $orderService->deleteUnfinishedOrder($tradeNo, (int)$merchant->id);

        return json($result);
    }

    /**
     * 商户一键批量清理已超时/未完成订单
     */
    public function batchClean(Request $request)
    {
        $merchant = $request->context['merchant'] ?? null;
        if (!$merchant instanceof Merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $beforeMinutes = max(0, (int)($request->post('before_minutes') ?? 5));
        $orderService = new \app\service\OrderService();
        $deletedCount = $orderService->batchDeleteUnfinishedOrders((int)$merchant->id, $beforeMinutes * 60);

        return json([
            'code' => 1,
            'msg'  => "已成功清理 {$deletedCount} 笔已超时未完成订单！",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    private function decryptConfig(mixed $rawConfig): array
    {
        $raw = is_string($rawConfig) ? (json_decode($rawConfig, true) ?: []) : (array)$rawConfig;
        $authcode = new Authcode();
        $flat = [];
        if (isset($raw['data']) && is_array($raw['data'])) {
            foreach ($raw['data'] as $k => $v) {
                $flat[$k] = is_string($v) ? $authcode->decryptStored($v) : $v;
            }
        }
        foreach ($raw as $key => $value) {
            if ($key !== 'code' && $key !== 'msg' && $key !== 'data') {
                $flat[$key] = is_string($value) ? $authcode->decryptStored($value) : $value;
            }
        }
        return $flat;
    }

    private function jsonResponse(array $payload)
    {
        return response(
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            200,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );
    }
}
