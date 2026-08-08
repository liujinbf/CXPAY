<?php

declare(strict_types=1);

namespace Tests\Support;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\PaymentManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

abstract class OrderDatabaseTestCase extends TestCase
{
    protected static Capsule $capsule;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule();
        self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();
    }

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->integer('plan_id')->default(0);
            $table->integer('plan_expire_time')->default(0);
            $table->decimal('plan_fee_discount_balance', 10, 2)->default(0);
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
            $table->decimal('fee_reserved_cash', 10, 2)->default(0);
            $table->decimal('fee_reserved_discount', 10, 2)->default(0);
            $table->string('fee_reservation_status', 16)->default('legacy');
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
            $table->string('c_type')->default('order_fee_test');
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

        $mockDriver = new class implements PaymentDriverInterface {
            public function pay(array $params, array $config): array
            {
                return [
                    'type' => 'qrcode',
                    'pay_url' => (string)($config['qr_url'] ?? 'mock://order-fee'),
                    'trade_no' => (string)($params['trade_no'] ?? ''),
                    'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
                    'amount' => (string)($params['money'] ?? '0'),
                ];
            }

            public function notify(array $params, array $config): array
            {
                return ['success' => false, 'out_trade_no' => '', 'trade_no' => '', 'amount' => 0.0];
            }

            public function query(string $tradeNo, array $config): array
            {
                return ['paid' => false];
            }

            public function getMeta(): array
            {
                return [
                    'name' => 'order_fee_test',
                    'title' => '订单手续费测试驱动',
                    'description' => '',
                    'pay_category' => 'alipay',
                    'collection_mode' => 'qrcode',
                    'inputs' => [],
                ];
            }

            public function upchannel(array $request, array $config): array
            {
                return $config;
            }
        };

        PaymentManager::register('order_fee_test', get_class($mockDriver));
    }

    protected function merchant(string $money, array $overrides = []): Merchant
    {
        return Merchant::create(array_merge([
            'pid' => 'M10001',
            'name' => '测试商户',
            'key' => str_repeat('k', 48),
            'money' => $money,
            'rate' => '0.0100',
            'plan_id' => 1,
            'plan_expire_time' => time() + 3600,
            'status' => 1,
        ], $overrides));
    }

    protected function channel(array $overrides = []): Channel
    {
        return Channel::create(array_merge([
            'merchant_id' => 0,
            'pay_category' => 'alipay',
            'title' => '支付宝测试通道',
            'c_type' => 'order_fee_test',
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

    protected function order(Merchant $merchant, Channel $channel, array $overrides = []): Order
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
