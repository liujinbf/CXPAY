<?php

declare(strict_types=1);

namespace Tests\Integration;

use app\service\LegacyPaymentDriverCleanupService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyPaymentDriverCleanupServiceTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->db = $capsule->getConnection();

        $this->createSchema();
        $this->seedData();
    }

    public function testPreviewDoesNotModifyDataAndApplyArchivesAndDeletes(): void
    {
        $service = new LegacyPaymentDriverCleanupService($this->db);
        $service->ensureArchiveTable();

        $preview = $service->preview();

        self::assertSame(2, $preview['channel_count']);
        self::assertSame(2, $preview['poll_group_links']);
        self::assertSame(1, $preview['plans_to_update']);
        self::assertSame(0, $preview['pending_orders']);
        self::assertCount(2, $preview['channels']);

        foreach ($preview['channels'] as $channel) {
            self::assertArrayNotHasKey('config', $channel);
        }

        // preview 必须是纯 dry-run。
        self::assertSame(
            3,
            $this->db->table('cx_pay_channel')->count()
        );
        self::assertSame(
            0,
            $this->db->table('cx_pay_channel_archive')->count()
        );
        self::assertSame(
            'alipay,alipay_official,qqpay_app_asst,wxpay_protocol_cloud',
            $this->db->table('cx_plan')
                ->where('id', 1)
                ->value('allowed_channels')
        );

        $result = $service->apply();

        self::assertSame(2, $result['archived']);
        self::assertSame(2, $result['poll_group_links_deleted']);
        self::assertSame(1, $result['plans_updated']);
        self::assertSame(2, $result['channels_deleted']);
        self::assertSame(0, $result['remaining']);

        self::assertSame(
            2,
            $this->db->table('cx_pay_channel_archive')->count()
        );

        self::assertSame(
            ['removed_placeholder_or_shared_token_driver'],
            $this->db->table('cx_pay_channel_archive')
                ->whereIn('original_channel_id', [10, 11])
                ->distinct()
                ->pluck('archive_reason')
                ->values()
                ->all()
        );

        self::assertSame(
            1,
            $this->db->table('cx_pay_channel')
                ->where('id', 12)
                ->count()
        );
        self::assertSame(
            0,
            $this->db->table('cx_pay_channel')
                ->whereIn('id', [10, 11])
                ->count()
        );

        self::assertFalse(
            $this->db->getSchemaBuilder()
                ->hasColumn('cx_pay_channel_archive', 'config')
        );

        self::assertSame(
            'alipay,qqpay_app_asst',
            $this->db->table('cx_plan')
                ->where('id', 1)
                ->value('allowed_channels')
        );

        self::assertSame(
            1,
            $this->db->table('cx_poll_group_channel')
                ->where('channel_id', 12)
                ->count()
        );
        self::assertSame(
            0,
            $this->db->table('cx_poll_group_channel')
                ->whereIn('channel_id', [10, 11])
                ->count()
        );

        // 历史订单与账单引用不得改写或删除。
        self::assertSame(
            10,
            (int)$this->db->table('cx_order')
                ->where('id', 100)
                ->value('channel_id')
        );
        self::assertSame(
            1,
            (int)$this->db->table('cx_order')
                ->where('id', 100)
                ->value('status')
        );
        self::assertSame(
            11,
            (int)$this->db->table('cx_callbill')
                ->where('id', 200)
                ->value('channel_id')
        );
        self::assertSame(
            3,
            (int)$this->db->table('cx_callbill')
                ->where('id', 200)
                ->value('status')
        );
    }

    public function testQqpayEpayUsesReplacementArchiveReason(): void
    {
        $this->db->table('cx_pay_channel')->insert([
            'id' => 13,
            'merchant_id' => 0,
            'pay_category' => 'qqpay',
            'title' => '重复QQ易支付上游',
            'c_type' => 'qqpay_epay',
            'remark' => '',
            'config' =>
                '{"pid":"secret","key":"must-not-archive"}',
            'weight' => 50,
            'single_min' => 0,
            'single_max' => 5000,
            'day_max' => 10000,
            'today_money' => 0,
            'today_count' => 0,
            'total_money' => 0,
            'online_status' => 0,
            'status' => 0,
        ]);

        $service = new LegacyPaymentDriverCleanupService(
            $this->db
        );
        $service->ensureArchiveTable();

        $result = $service->apply();

        self::assertSame(
            3,
            $result['archived']
        );

        self::assertSame(
            'superseded_by_epay_generic',
            $this->db
                ->table('cx_pay_channel_archive')
                ->where('original_channel_id', 13)
                ->value('archive_reason')
        );

        self::assertFalse(
            $this->db->getSchemaBuilder()
                ->hasColumn(
                    'cx_pay_channel_archive',
                    'config'
                )
        );

        self::assertSame(
            0,
            $this->db->table('cx_pay_channel')
                ->where('id', 13)
                ->count()
        );
    }

    public function testApplyIsIdempotent(): void
    {
        $service = new LegacyPaymentDriverCleanupService($this->db);
        $service->ensureArchiveTable();

        $service->apply();
        $second = $service->apply();

        self::assertSame(0, $second['archived']);
        self::assertSame(0, $second['poll_group_links_deleted']);
        self::assertSame(0, $second['plans_updated']);
        self::assertSame(0, $second['channels_deleted']);
        self::assertSame(0, $second['remaining']);

        self::assertSame(
            2,
            $this->db->table('cx_pay_channel_archive')->count()
        );
    }

    public function testPendingOrderBlocksAllCleanupChanges(): void
    {
        $service = new LegacyPaymentDriverCleanupService($this->db);
        $service->ensureArchiveTable();

        $this->db->table('cx_order')->insert([
            'id' => 99,
            'channel_id' => 10,
            'status' => 0,
        ]);

        try {
            $service->apply();
            self::fail('存在待支付订单时必须阻止清理');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('待支付订单', $e->getMessage());
        }

        self::assertSame(
            0,
            $this->db->table('cx_pay_channel_archive')->count()
        );
        self::assertSame(
            3,
            $this->db->table('cx_pay_channel')->count()
        );
        self::assertSame(
            3,
            $this->db->table('cx_poll_group_channel')->count()
        );
        self::assertSame(
            'alipay,alipay_official,qqpay_app_asst,wxpay_protocol_cloud',
            $this->db->table('cx_plan')
                ->where('id', 1)
                ->value('allowed_channels')
        );
    }

    public function testFailureDuringDeleteRollsBackAllDml(): void
    {
        $service = new LegacyPaymentDriverCleanupService($this->db);
        $service->ensureArchiveTable();

        $this->db->statement(
            <<<'SQL'
CREATE TRIGGER fail_legacy_channel_delete
BEFORE DELETE ON cx_pay_channel
WHEN OLD.id = 11
BEGIN
    SELECT RAISE(ABORT, 'forced cleanup rollback');
END
SQL
        );

        try {
            $service->apply();
            self::fail('删除阶段被强制中断时必须抛出异常');
        } catch (\Throwable $e) {
            self::assertStringContainsString(
                'forced cleanup rollback',
                $e->getMessage()
            );
        }

        self::assertSame(
            0,
            $this->db->table('cx_pay_channel_archive')->count()
        );
        self::assertSame(
            3,
            $this->db->table('cx_pay_channel')->count()
        );
        self::assertSame(
            3,
            $this->db->table('cx_poll_group_channel')->count()
        );
        self::assertSame(
            'alipay,alipay_official,qqpay_app_asst,wxpay_protocol_cloud',
            $this->db->table('cx_plan')
                ->where('id', 1)
                ->value('allowed_channels')
        );
    }

    public function testExistingIncompleteArchiveTableIsRejected(): void
    {
        $schema = $this->db->getSchemaBuilder();

        $schema->create(
            'cx_pay_channel_archive',
            static function (Blueprint $table): void {
                $table->increments('archive_id');
                $table->unsignedInteger('original_channel_id')->unique();
            }
        );

        $service = new LegacyPaymentDriverCleanupService($this->db);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('归档表结构不完整');

        $service->ensureArchiveTable();
    }

    private function createSchema(): void
    {
        $schema = $this->db->getSchemaBuilder();

        $schema->create(
            'cx_pay_channel',
            static function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('merchant_id')->default(0);
                $table->string('pay_category', 32);
                $table->string('title', 100);
                $table->string('c_type', 64);
                $table->string('remark', 255)->default('');
                $table->text('config')->nullable();
                $table->unsignedInteger('weight')->default(0);
                $table->decimal('single_min', 12, 2)->default(0);
                $table->decimal('single_max', 12, 2)->default(0);
                $table->decimal('day_max', 12, 2)->default(0);
                $table->decimal('today_money', 12, 2)->default(0);
                $table->unsignedInteger('today_count')->default(0);
                $table->decimal('total_money', 14, 2)->default(0);
                $table->unsignedTinyInteger('online_status')->default(0);
                $table->unsignedTinyInteger('status')->default(0);
            }
        );

        $schema->create(
            'cx_poll_group_channel',
            static function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('group_id');
                $table->unsignedInteger('channel_id');
            }
        );

        $schema->create(
            'cx_plan',
            static function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->text('allowed_channels')->nullable();
            }
        );

        $schema->create(
            'cx_order',
            static function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('channel_id');
                $table->unsignedTinyInteger('status');
            }
        );

        $schema->create(
            'cx_callbill',
            static function (Blueprint $table): void {
                $table->unsignedInteger('id')->primary();
                $table->unsignedInteger('channel_id');
                $table->unsignedTinyInteger('status');
            }
        );
    }

    private function seedData(): void
    {
        $base = [
            'merchant_id' => 0,
            'pay_category' => 'alipay',
            'title' => '',
            'c_type' => '',
            'remark' => '',
            'config' => '{"token":"must-not-be-archived"}',
            'weight' => 100,
            'single_min' => 0,
            'single_max' => 5000,
            'day_max' => 10000,
            'today_money' => 18.88,
            'today_count' => 1,
            'total_money' => 188.88,
            'online_status' => 1,
            'status' => 1,
        ];

        $this->db->table('cx_pay_channel')->insert([
            array_merge($base, [
                'id' => 10,
                'pay_category' => 'alipay',
                'title' => '旧支付宝官方占位',
                'c_type' => 'alipay_official',
            ]),
            array_merge($base, [
                'id' => 11,
                'pay_category' => 'wxpay',
                'title' => '旧微信共享Token',
                'c_type' => 'wxpay_protocol_cloud',
            ]),
            array_merge($base, [
                'id' => 12,
                'pay_category' => 'qqpay',
                'title' => '保留QQ助手',
                'c_type' => 'qqpay_app_asst',
            ]),
        ]);

        $this->db->table('cx_poll_group_channel')->insert([
            ['group_id' => 1, 'channel_id' => 10],
            ['group_id' => 1, 'channel_id' => 11],
            ['group_id' => 1, 'channel_id' => 12],
        ]);

        $this->db->table('cx_plan')->insert([
            [
                'id' => 1,
                'allowed_channels' =>
                    'alipay,alipay_official,qqpay_app_asst,'
                    . 'wxpay_protocol_cloud',
            ],
            [
                'id' => 2,
                'allowed_channels' => 'alipay,wxpay,qqpay',
            ],
        ]);

        $this->db->table('cx_order')->insert([
            'id' => 100,
            'channel_id' => 10,
            'status' => 1,
        ]);

        $this->db->table('cx_callbill')->insert([
            'id' => 200,
            'channel_id' => 11,
            'status' => 3,
        ]);
    }
}
