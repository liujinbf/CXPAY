<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\model\Order;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\PaymentManager;
use app\service\OrderService;
use support\Sign;
use Tests\Support\OrderDatabaseTestCase;

final class PaymentInitializationServiceTest extends OrderDatabaseTestCase
{
    public function testConcurrentRetryCannotCallDriverWhilePaymentInitializationIsClaimed(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel([
            'merchant_id' => $merchant->id,
            'config' => json_encode([
                'qr_url' => 'https://qr.alipay.com/test-claimed',
                'notify_token' => 'test-notify-token',
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'pay_init_status' => 1,
            'pay_init_time' => time(),
        ]);
        $params = [
            'pid' => $merchant->pid,
            'type' => 'alipay',
            'out_trade_no' => 'OUT-1',
            'money' => '100.00',
            'name' => '并发出码测试',
            'notify_url' => 'https://merchant.example/notify',
        ];
        $params['sign'] = Sign::makeSign($params, (string)$merchant->key);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('正在初始化支付通道');
        (new OrderService())->createOrder($params, 'https://pay.example.com');
    }

    public function testStalePaymentInitializationClaimCanBeRecovered(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel([
            'merchant_id' => $merchant->id,
            'config' => json_encode([
                'qr_url' => 'https://qr.alipay.com/test-stale-claim',
                'notify_token' => 'test-notify-token',
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $order = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'pay_init_status' => 1,
            'pay_init_time' => time() - 31,
        ]);
        $params = [
            'pid' => $merchant->pid,
            'type' => 'alipay',
            'out_trade_no' => 'OUT-1',
            'money' => '100.00',
            'name' => '过期出码锁恢复测试',
            'notify_url' => 'https://merchant.example/notify',
        ];
        $params['sign'] = Sign::makeSign($params, (string)$merchant->key);

        $result = (new OrderService())->createOrder($params, 'https://pay.example.com');

        self::assertSame('https://qr.alipay.com/test-stale-claim', $result['pay_url']);
        self::assertSame(2, (int)$order->fresh()->pay_init_status);
    }

    public function testOldInitializerCannotOverwriteNewClaimOwner(): void
    {
        PaymentManager::register('claim_takeover_test', ClaimTakeoverTestDriver::class);
        $merchant = $this->merchant('9.00');
        $channel = $this->channel([
            'merchant_id' => $merchant->id,
            'c_type' => 'claim_takeover_test',
            'config' => '{}',
        ]);
        $order = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
        ]);
        $params = [
            'pid' => $merchant->pid,
            'type' => 'alipay',
            'out_trade_no' => 'OUT-1',
            'money' => '100.00',
            'name' => '初始化所有权测试',
            'notify_url' => 'https://merchant.example/notify',
        ];
        $params['sign'] = Sign::makeSign($params, (string)$merchant->key);

        try {
            (new OrderService())->createOrder($params, 'https://pay.example.com');
            self::fail('初始化所有权变化后，旧请求不得写回支付地址');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('其他请求接管', $exception->getMessage());
        }

        $order->refresh();
        self::assertSame(1, (int)$order->pay_init_status);
        self::assertSame('', (string)$order->pay_url);
    }
}

final class ClaimTakeoverTestDriver implements PaymentDriverInterface
{
    public function pay(array $params, array $config): array
    {
        $order = Order::where('trade_no', (string)$params['trade_no'])->firstOrFail();
        Order::where('id', $order->id)->update([
            'pay_init_time' => (int)$order->pay_init_time + 1,
        ]);

        return ['type' => 'qrcode', 'pay_url' => 'https://pay.example.com/claimed-by-old-request'];
    }

    public function notify(array $params, array $config): array
    {
        return ['success' => false];
    }

    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return ['name' => 'claim_takeover_test', 'title' => '初始化认领测试驱动', 'available' => true];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        return $config;
    }
}
