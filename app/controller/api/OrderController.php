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

        $payUrl = '';
        if ((int)$order->status === 0 && (int)$order->expire_time > time()) {
            $payUrl = (string)($order->pay_url ?? '');
            $channel = $payUrl === '' ? Channel::find($order->channel_id) : null;
            if ($channel !== null) {
                $config = $this->decryptConfig($channel->config);
                $payUrl = trim((string)($config['qr_url'] ?? $config['qr_code_url'] ?? ''));
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

    private function decryptConfig(mixed $rawConfig): array
    {
        $config = is_string($rawConfig) ? (json_decode($rawConfig, true) ?: []) : (array)$rawConfig;
        $authcode = new Authcode();
        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $config[$key] = $authcode->decryptStored($value);
            }
        }
        return $config;
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
