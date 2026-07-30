<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\model\Channel;
use app\model\Callbill;
use app\model\Merchant;
use app\model\Order;
use app\model\UserMoneyLog;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\PaymentManager;
use app\service\OrderService;
use app\service\CallbillService;
use support\Sign;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class OrderFeeReservationTest extends TestCase
{
    private static Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule();
        self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();
    }

    protected function setUp(): void
    {
        $schema = self::$capsule->schema();
        foreach (['cx_config', 'cx_callbill', 'cx_user_money_log', 'cx_pay_channel', 'cx_order', 'cx_merchant'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->create('cx_config', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value')->nullable();
            $table->string('title')->default('');
        });

        $schema->create('cx_merchant', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('pid')->unique();
            $table->string('name')->default('测试商户');
            $table->string('key')->default('');
            $table->decimal('money', 10, 2)->default(0);
            $table->decimal('rate', 5, 4)->default(0.01);
            $table->integer('packvip_time')->default(0);
            $table->decimal('pay_float_min', 6, 2)->default(0);
            $table->decimal('pay_float_max', 6, 2)->default(0);
            $table->integer('pay_outtime')->default(180);
            $table->text('ip_white')->nullable();
            $table->integer('status')->default(1);
        });
        $schema->create('cx_order', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('merchant_id');
            $table->string('out_trade_no');
            $table->string('trade_no')->unique();
            $table->integer('channel_id')->default(0);
            $table->string('pay_type')->default('alipay');
            $table->string('business_type')->default('payment');
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->integer('fee_status')->default(0);
            $table->decimal('amount', 10, 2);
            $table->decimal('price', 10, 2);
            $table->string('subject')->default('测试订单');
            $table->text('notify_url')->nullable();
            $table->text('return_url')->nullable();
            $table->text('pay_url')->nullable();
            $table->string('pay_mode')->default('qrcode');
            $table->integer('pay_init_status')->default(0);
            $table->integer('pay_init_time')->default(0);
            $table->string('channel_trade_no')->default('');
            $table->integer('status')->default(0);
            $table->integer('notify_status')->default(0);
            $table->integer('create_time')->default(0);
            $table->integer('expire_time')->default(0);
            $table->integer('pay_time')->default(0);
        });
        $schema->create('cx_pay_channel', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('merchant_id')->default(0);
            $table->string('pay_category')->default('alipay');
            $table->string('title')->default('测试通道');
            $table->string('c_type')->default('alipay_scan_bill');
            $table->text('config')->nullable();
            $table->decimal('today_money', 10, 2)->default(0);
            $table->integer('today_count')->default(0);
            $table->decimal('total_money', 10, 2)->default(0);
            $table->integer('weight')->default(50);
            $table->decimal('single_min', 10, 2)->default(0);
            $table->decimal('single_max', 10, 2)->default(0);
            $table->decimal('day_max', 10, 2)->default(0);
            $table->integer('online_status')->default(1);
            $table->integer('status')->default(1);
        });
        $schema->create('cx_user_money_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('merchant_id');
            $table->decimal('money', 10, 2);
            $table->decimal('before', 10, 2);
            $table->decimal('after', 10, 2);
            $table->string('memo')->default('');
            $table->integer('create_time')->default(0);
        });
        $schema->create('cx_callbill', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('device_id', 64);
            $table->string('source_bill_id', 128);
            $table->string('app_name', 50);
            $table->decimal('money', 10, 2);
            $table->string('remark')->default('');
            $table->integer('channel_id');
            $table->string('trade_no', 64)->default('');
            $table->integer('order_id')->default(0);
            $table->integer('occurred_at')->default(0);
            $table->string('raw_hash', 64)->default('');
            $table->string('client_version', 32)->default('');
            $table->string('review_note')->default('');
            $table->integer('status')->default(0);
            $table->integer('create_time')->default(0);
            $table->unique(['channel_id', 'source_bill_id']);
        });
    }

    public function testClosingPendingOrderRefundsReservedFeeExactlyOnce(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'pay_init_status' => 1,
            'pay_init_time' => time(),
        ]);
        $service = new OrderService();

        self::assertTrue($service->closePendingOrder((string)$order->trade_no, '测试关闭'));
        self::assertTrue($service->closePendingOrder((string)$order->trade_no, '重复关闭'));

        self::assertSame('10.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame(2, (int)$order->fresh()->status);
        self::assertSame(3, (int)$order->fresh()->fee_status);
        self::assertSame(3, (int)$order->fresh()->pay_init_status);
        self::assertSame(1, UserMoneyLog::count());
        self::assertSame('1.00', number_format((float)UserMoneyLog::first()->money, 2, '.', ''));
    }

    public function testPaidOrderConsumesReservationWithoutSecondDeduction(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
            'pay_init_status' => 1,
            'pay_init_time' => time(),
        ]);

        self::assertTrue((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'UPSTREAM-1',
            100.00,
            (int)$channel->id,
            true
        ));
        self::assertTrue((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'UPSTREAM-RETRY',
            100.00,
            (int)$channel->id,
            true
        ));
        self::assertFalse((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'WRONG-CHANNEL',
            100.00,
            (int)$channel->id + 1,
            true
        ));

        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame(1, (int)$order->fresh()->status);
        self::assertSame(2, (int)$order->fresh()->fee_status);
        self::assertSame(2, (int)$order->fresh()->pay_init_status);
        self::assertSame(0, UserMoneyLog::count());
        self::assertSame('100.00', number_format((float)$channel->fresh()->today_money, 2, '.', ''));
        self::assertSame(1, (int)$channel->fresh()->today_count);
    }

    public function testLegacyOrderWithoutReservationStillDeductsFeeOnPayment(): void
    {
        $merchant = $this->merchant('10.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel, ['fee_amount' => '0.00', 'fee_status' => 0]);

        self::assertTrue((new OrderService())->markAsPaid(
            (string)$order->trade_no,
            'UPSTREAM-LEGACY',
            100.00,
            (int)$channel->id,
            true
        ));

        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame('1.00', number_format((float)$order->fresh()->fee_amount, 2, '.', ''));
        self::assertSame(2, (int)$order->fresh()->fee_status);
        self::assertSame('-1.00', number_format((float)UserMoneyLog::first()->money, 2, '.', ''));
    }

    public function testExpiryBatchClosesOnlyExpiredPendingOrders(): void
    {
        $merchant = $this->merchant('8.00');
        $channel = $this->channel();
        $expired = $this->order($merchant, $channel, [
            'trade_no' => 'CX-EXPIRED', 'out_trade_no' => 'OUT-EXPIRED',
            'fee_amount' => '1.00', 'fee_status' => 1, 'expire_time' => time() - 1,
        ]);
        $active = $this->order($merchant, $channel, [
            'trade_no' => 'CX-ACTIVE', 'out_trade_no' => 'OUT-ACTIVE',
            'fee_amount' => '1.00', 'fee_status' => 1, 'expire_time' => time() + 300,
        ]);

        self::assertSame(1, (new OrderService())->expirePendingOrders());
        self::assertSame(2, (int)$expired->fresh()->status);
        self::assertSame(0, (int)$active->fresh()->status);
        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
    }

    public function testCreateOrderReservesFeeAndIdempotentRetryDoesNotDeductAgain(): void
    {
        $merchant = $this->merchant('10.00');
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

        self::assertSame($first['trade_no'], $second['trade_no']);
        self::assertSame('9.00', number_format((float)$merchant->fresh()->money, 2, '.', ''));
        self::assertSame(1, Order::count());
        self::assertSame('1.00', number_format((float)Order::first()->fee_amount, 2, '.', ''));
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

    public function testDuplicateAssistantBillCannotSettleAnotherOrder(): void
    {
        $merchant = $this->merchant('9.00');
        $channel = $this->channel();
        $occurredAt = time();
        $firstOrder = $this->order($merchant, $channel, [
            'fee_amount' => '1.00',
            'fee_status' => 1,
        ]);
        $service = new CallbillService();

        $first = $service->processPush(
            'alipay_app_asst',
            'ANDROID_device-01',
            100.00,
            '支付宝成功收款100.00元',
            (int)$channel->id,
            'notification.alipay.0001',
            $occurredAt,
            hash('sha256', 'same-notification'),
            '1.0.0'
        );
        self::assertTrue($first['success']);
        self::assertSame(1, (int)$firstOrder->fresh()->status);

        $secondOrder = $this->order($merchant, $channel, [
            'trade_no' => 'CX-2',
            'out_trade_no' => 'OUT-2',
            'fee_amount' => '0.00',
            'fee_status' => 0,
        ]);
        $duplicate = $service->processPush(
            'alipay_app_asst',
            'ANDROID_device-01',
            100.00,
            '支付宝成功收款100.00元',
            (int)$channel->id,
            'notification.alipay.0001',
            $occurredAt,
            hash('sha256', 'same-notification'),
            '1.0.0'
        );

        self::assertTrue($duplicate['success']);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame(0, (int)$secondOrder->fresh()->status);
        self::assertSame(1, Callbill::count());
    }

    public function testExpiredAssistantBillIsSentToManualReview(): void
    {
        $merchant = $this->merchant('10.00');
        $channel = $this->channel();
        $occurredAt = time() - 60;
        $order = $this->order($merchant, $channel, [
            'status' => 2,
            'create_time' => $occurredAt - 30,
            'expire_time' => $occurredAt + 30,
        ]);

        $result = (new CallbillService())->processPush(
            'wxpay_app_asst',
            'ANDROID_device-01',
            100.00,
            '微信支付收款100.00元',
            (int)$channel->id,
            'notification.wxpay.0001',
            $occurredAt,
            hash('sha256', 'expired-notification'),
            '1.0.0'
        );

        self::assertFalse($result['success']);
        self::assertSame(3, (int)Callbill::first()->status);
        self::assertSame(2, (int)$order->fresh()->status);
    }

    public function testManualReviewStillEnforcesChannelAndAmount(): void
    {
        $merchant = $this->merchant('10.00');
        $channel = $this->channel();
        $order = $this->order($merchant, $channel);
        $bill = Callbill::create([
            'device_id' => 'ANDROID_device-01',
            'source_bill_id' => 'notification.review.0001',
            'app_name' => 'alipay_app_asst',
            'money' => '99.99',
            'remark' => '待复核账单',
            'channel_id' => (int)$channel->id,
            'occurred_at' => time(),
            'raw_hash' => hash('sha256', 'review-bill'),
            'client_version' => '1.0.0',
            'status' => 3,
            'create_time' => time(),
        ]);
        $service = new CallbillService();

        $rejected = $service->reviewMatch((int)$bill->id, (string)$order->trade_no, 'tester');
        self::assertFalse($rejected['success']);
        self::assertSame(3, (int)$bill->fresh()->status);
        self::assertSame(0, (int)$order->fresh()->status);

        $bill->money = '100.00';
        $bill->save();
        $accepted = $service->reviewMatch((int)$bill->id, (string)$order->trade_no, 'tester');
        self::assertTrue($accepted['success']);
        self::assertSame(1, (int)$bill->fresh()->status);
        self::assertSame((int)$order->id, (int)$bill->fresh()->order_id);
        self::assertSame(1, (int)$order->fresh()->status);
    }

    private function merchant(string $money): Merchant
    {
        return Merchant::create([
            'pid' => 'M10001', 'name' => '测试商户', 'key' => str_repeat('k', 48),
            'money' => $money, 'rate' => '0.0100', 'status' => 1,
        ]);
    }

    private function channel(array $overrides = []): Channel
    {
        return Channel::create(array_merge([
            'merchant_id' => 0,
            'pay_category' => 'alipay',
            'title' => '支付宝测试通道',
            'c_type' => 'alipay_scan_bill',
            'config' => '{}',
            'today_money' => 0,
            'today_count' => 0,
            'total_money' => 0,
            'weight' => 50,
            'single_min' => 0,
            'single_max' => 0,
            'day_max' => 0,
            'online_status' => 1,
            'status' => 1,
        ], $overrides));
    }

    private function order(Merchant $merchant, Channel $channel, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'merchant_id' => $merchant->id,
            'out_trade_no' => 'OUT-1',
            'trade_no' => 'CX-1',
            'channel_id' => $channel->id,
            'pay_type' => 'alipay',
            'business_type' => 'payment',
            'fee_amount' => '0.00',
            'fee_status' => 0,
            'amount' => '100.00',
            'price' => '100.00',
            'notify_url' => '',
            'return_url' => '',
            'status' => 0,
            'notify_status' => 0,
            'create_time' => time(),
            'expire_time' => time() + 300,
            'pay_time' => 0,
        ], $overrides));
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
