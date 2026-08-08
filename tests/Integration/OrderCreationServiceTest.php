<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\model\Order;
use app\model\UserMoneyLog;
use app\service\order\ChannelRoutingService;
use app\service\OrderService;
use app\service\PollService;
use app\service\RiskGuardService;
use support\Sign;
use Tests\Support\OrderDatabaseTestCase;

final class OrderCreationServiceTest extends OrderDatabaseTestCase
{
    public function testChannelRouterUsesConfiguredFallbackWhenPrimaryDriverIsUnavailable(): void
    {
        $fallback = $this->channel(['title' => '备用通道']);
        $primary = $this->channel([
            'title' => '主通道',
            'c_type' => 'missing_driver',
            'fallback_channel_id' => $fallback->id,
        ]);

        $router = $this->channelRouterReturning((int)$primary->id);

        self::assertSame((int)$fallback->id, (int)$router->select(0, 'alipay', '100.00')->id);
    }

    public function testChannelRouterRejectsRequestWhenNoChannelIsAvailable(): void
    {
        $router = $this->channelRouterReturning(999999);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('暂无满足条件的可用支付通道');
        $router->select(0, 'alipay', '100.00');
    }

    public function testCreateOrderReservesFeeAndIdempotentRetryDoesNotDeductAgain(): void
    {
        $merchant = $this->merchant('9.75', ['plan_fee_discount_balance' => '0.25']);
        $this->channel([
            'merchant_id' => $merchant->id,
            'config' => json_encode([
                'qr_url' => 'https://qr.alipay.com/test-order',
                'notify_token' => 'test-notify-token',
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $params = [
            'pid' => $merchant->pid,
            'type' => 'alipay',
            'out_trade_no' => 'CREATE-ORDER-1',
            'money' => '100.00',
            'name' => '手续费预占测试',
            'notify_url' => 'https://merchant.example/notify',
            'return_url' => 'https://merchant.example/return',
        ];
        $params['sign'] = Sign::makeSign($params, (string)$merchant->key);
        $service = new OrderService();

        $first = $service->createOrder($params, 'https://pay.example.com', 'payment', '203.0.113.8');
        $second = $service->createOrder($params, 'https://pay.example.com', 'payment', '203.0.113.8');

        self::assertMatchesRegularExpression('/^CX\d{14}[A-F0-9]{20}$/', $first['trade_no']);
        self::assertSame($first['trade_no'], $second['trade_no']);
        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame('0.00', number_format((float)$merchant->fresh()->plan_fee_discount_balance, 2, '.', ''));
        self::assertSame(1, Order::count());
        self::assertSame('1.00', number_format((float)Order::first()->fee_amount, 2, '.', ''));
        self::assertSame('0.75', (string)Order::first()->fee_reserved_cash);
        self::assertSame('0.25', (string)Order::first()->fee_reserved_discount);
        self::assertSame('reserved', (string)Order::first()->fee_reservation_status);
        self::assertSame(1, (int)Order::first()->fee_status);
        self::assertSame(1, UserMoneyLog::count());
    }

    public function testCreateOrderRollsBackWhenAvailableFeeBalanceIsInsufficient(): void
    {
        $merchant = $this->merchant('0.50');
        $this->channel([
            'merchant_id' => $merchant->id,
            'config' => json_encode([
                'qr_url' => 'https://qr.alipay.com/test-insufficient',
                'notify_token' => 'test-notify-token',
            ], JSON_UNESCAPED_SLASHES),
        ]);
        $params = [
            'pid' => $merchant->pid,
            'type' => 'alipay',
            'out_trade_no' => 'CREATE-ORDER-INSUFFICIENT',
            'money' => '100.00',
            'name' => '余额不足测试',
            'notify_url' => 'https://merchant.example/notify',
        ];
        $params['sign'] = Sign::makeSign($params, (string)$merchant->key);

        try {
            (new OrderService())->createOrder($params, 'https://pay.example.com');
            self::fail('余额不足时应拒绝创建订单');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('余额不足', $exception->getMessage());
        }

        self::assertSame('0.50', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame(0, Order::count());
        self::assertSame(0, UserMoneyLog::count());
    }

    private function channelRouterReturning(int $channelId): ChannelRoutingService
    {
        if (!class_exists(ChannelRoutingService::class)) {
            self::fail('通道路由服务尚未实现');
        }

        $pollService = new class($channelId) extends PollService {
            public function __construct(private readonly int $channelId)
            {
            }

            public function selectChannel(int $merchantId, string $payType, float $amount): array
            {
                return ['channel_id' => $this->channelId];
            }
        };

        return new ChannelRoutingService($pollService, new RiskGuardService());
    }
}
