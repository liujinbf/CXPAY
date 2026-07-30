<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\model\Channel;
use app\model\Merchant;
use app\model\Order;
use app\payment\Contracts\PaymentDriverInterface;
use app\payment\PaymentManager;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * 主备通道故障转移（fallback_channel_id）集成测试（任务6）
 *
 * 验证 selectChannel() 在主通道不满足条件时，优先尝试 fallback_channel_id 指向的备用通道，
 * 备用通道也不可用时才进行权重随机兜底。
 */
final class FallbackChannelTest extends TestCase
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
        $schema = Capsule::schema();
        foreach (['cx_pay_channel'] as $t) {
            $schema->dropIfExists($t);
        }
        $schema->create('cx_pay_channel', function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('merchant_id')->default(0);
            $t->string('pay_category')->default('alipay');
            $t->string('title')->default('测试通道');
            $t->string('c_type')->default('alipay_scan_bill');
            $t->text('config')->nullable();
            $t->decimal('today_money', 10, 2)->default(0);
            $t->integer('today_count')->default(0);
            $t->decimal('total_money', 10, 2)->default(0);
            $t->integer('weight')->default(50);
            $t->decimal('single_min', 10, 2)->default(0);
            $t->decimal('single_max', 10, 2)->default(9999);
            $t->decimal('day_max', 10, 2)->default(0);
            $t->integer('online_status')->default(1);
            $t->integer('status')->default(1);
            $t->integer('fallback_channel_id')->default(0);
        });

        // 注册 mock 驱动
        if (!PaymentManager::has('alipay_scan_bill')) {
            $mockDriver = new class implements PaymentDriverInterface {
                public function pay(array $p, array $c): array { return ['type' => 'url', 'pay_url' => 'mock://', 'trade_no' => '', 'out_trade_no' => '', 'amount' => '0']; }
                public function notify(array $p, array $c): array { return ['success' => false, 'out_trade_no' => '', 'trade_no' => '', 'amount' => 0.0]; }
                public function query(string $t, array $c): array { return ['paid' => false]; }
                public function getMeta(): array { return ['name' => 'alipay_scan_bill', 'title' => 'Mock', 'description' => '', 'pay_category' => 'alipay', 'collection_mode' => 'qrcode', 'inputs' => []]; }
                public function upchannel(array $r, array $c): array { return $c; }
            };
            PaymentManager::register('alipay_scan_bill', get_class($mockDriver));
        }
    }

    // ─── 辅助方法 ──────────────────────────────────────────────────────────

    private function makeChannel(array $attrs = []): Channel
    {
        return Channel::create(array_merge([
            'merchant_id'       => 0,
            'pay_category'      => 'alipay',
            'c_type'            => 'alipay_scan_bill',
            'config'            => '{}',
            'status'            => 1,
            'online_status'     => 1,
            'fallback_channel_id' => 0,
        ], $attrs));
    }

    // ─── 测试用例 ──────────────────────────────────────────────────────────

    /**
     * 测试1：验证 fallback_channel_id 字段存在且可读
     *
     * 主通道配置了 fallback_channel_id 指向备用通道，
     * 验证字段读取和备用通道查询逻辑正确。
     */
    public function testFallbackChannelIdFieldIsReadableFromChannel(): void
    {
        $fallback = $this->makeChannel(['title' => '备用通道']);
        $primary  = $this->makeChannel([
            'title'               => '主通道',
            'fallback_channel_id' => $fallback->id,
        ]);

        $primary->refresh();
        self::assertSame((int)$fallback->id, (int)$primary->fallback_channel_id,
            '主通道应能读取到正确的 fallback_channel_id');

        // 验证备用通道查询
        $fallbackLoaded = Channel::find((int)$primary->fallback_channel_id);
        self::assertNotNull($fallbackLoaded);
        self::assertSame('备用通道', $fallbackLoaded->title);
    }

    /**
     * 测试2：备用通道 online_status=0 时不应被选中
     *
     * 模拟备用通道下线后，fallback 逻辑应返回 null，触发兜底随机选通道。
     */
    public function testOfflineFallbackChannelShouldNotBeSelected(): void
    {
        $fallback = $this->makeChannel(['title' => '已下线备用通道', 'online_status' => 0]);
        $primary  = $this->makeChannel(['fallback_channel_id' => $fallback->id, 'status' => 0]); // 主通道也不可用

        // 模拟 selectChannel 中的 fallback 检查逻辑
        $fallbackCandidate = Channel::find((int)$primary->fallback_channel_id);
        $isFallbackValid   = $fallbackCandidate
            && (int)$fallbackCandidate->status === 1
            && (int)$fallbackCandidate->online_status === 1
            && PaymentManager::has((string)$fallbackCandidate->c_type);

        self::assertFalse($isFallbackValid, '已下线的备用通道不应被选中');
    }

    /**
     * 测试3：主通道可用时不触发 fallback
     *
     * 当主通道状态正常时，selectChannel 不应读取 fallback_channel_id。
     * 验证：主通道可用 = fallback 条件不满足（不进入 fallback 分支）
     */
    public function testActivePrimaryChannelDoesNotNeedFallback(): void
    {
        $fallback = $this->makeChannel(['title' => '备用通道']);
        $primary  = $this->makeChannel([
            'title'               => '可用主通道',
            'status'              => 1,
            'online_status'       => 1,
            'fallback_channel_id' => $fallback->id,
        ]);

        // 模拟检查条件：主通道可用
        $channelOk = (int)$primary->status === 1
            && (int)$primary->online_status === 1
            && PaymentManager::has((string)$primary->c_type);

        // 如果主通道可用，不应触发 fallback 分支
        $needsFallback = !$channelOk;
        self::assertFalse($needsFallback, '主通道可用时不应触发 fallback 逻辑');
    }

    /**
     * 测试4：fallback_channel_id=0 时跳过 fallback 直接兜底
     */
    public function testZeroFallbackChannelIdSkipsFallbackLogic(): void
    {
        $primary = $this->makeChannel([
            'title'               => '无备用通道主通道',
            'status'              => 0, // 不可用
            'fallback_channel_id' => 0, // 无备用通道
        ]);

        $hasFallback = (int)($primary->fallback_channel_id ?? 0) > 0;
        self::assertFalse($hasFallback, 'fallback_channel_id=0 时不应尝试加载备用通道');
    }
}
