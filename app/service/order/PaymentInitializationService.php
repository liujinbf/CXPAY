<?php

declare(strict_types=1);

namespace app\service\order;

use app\model\Channel;
use app\model\Order;
use app\payment\PaymentManager;
use Illuminate\Database\Capsule\Manager as DB;
use RuntimeException;
use support\Authcode;
use Throwable;

/**
 * 领取订单支付初始化权并持久化支付驱动返回结果。
 */
final class PaymentInitializationService
{
    public function prepare(Order $order, array $originalParams, string $gatewayBaseUrl): array
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
        return is_numeric($amount) ? number_format((float)$amount, 2, '.', '') : '0.00';
    }
}
