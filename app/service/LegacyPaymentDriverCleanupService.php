<?php

declare(strict_types=1);

namespace app\service;

use app\payment\RemovedPaymentDrivers;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use RuntimeException;

final class LegacyPaymentDriverCleanupService
{
    /** @var list<string> */
    private const CHANNEL_COLUMNS = [
        'id',
        'merchant_id',
        'pay_category',
        'title',
        'c_type',
        'remark',
        'weight',
        'single_min',
        'single_max',
        'day_max',
        'today_money',
        'today_count',
        'total_money',
        'online_status',
        'status',
    ];

    /** @var list<string> */
    private const ARCHIVE_COLUMNS = [
        'archive_id',
        'original_channel_id',
        'merchant_id',
        'pay_category',
        'title',
        'c_type',
        'remark',
        'weight',
        'single_min',
        'single_max',
        'day_max',
        'today_money',
        'today_count',
        'total_money',
        'online_status',
        'status',
        'archive_reason',
        'archived_at',
    ];

    public function __construct(
        private readonly Connection $db
    ) {
    }

    /**
     * 创建并校验非敏感通道归档表。
     *
     * 此方法只执行 DDL，不删除或修改活动数据。
     */
    public function ensureArchiveTable(): void
    {
        $schema = $this->db->getSchemaBuilder();

        if (!$schema->hasTable('cx_pay_channel')) {
            throw new RuntimeException('活动支付通道表不存在');
        }

        if (!$schema->hasTable('cx_pay_channel_archive')) {
            $schema->create(
                'cx_pay_channel_archive',
                static function (Blueprint $table): void {
                    $table->bigIncrements('archive_id');

                    $table->unsignedInteger('original_channel_id')
                        ->unique();

                    $table->unsignedInteger('merchant_id')
                        ->nullable();

                    $table->string('pay_category', 32)
                        ->nullable();

                    $table->string('title', 100)
                        ->nullable();

                    $table->string('c_type', 64);

                    $table->string('remark', 255)
                        ->nullable();

                    $table->unsignedInteger('weight')
                        ->nullable();

                    $table->decimal('single_min', 12, 2)
                        ->nullable();

                    $table->decimal('single_max', 12, 2)
                        ->nullable();

                    $table->decimal('day_max', 12, 2)
                        ->nullable();

                    $table->decimal('today_money', 12, 2)
                        ->nullable();

                    $table->unsignedInteger('today_count')
                        ->nullable();

                    $table->decimal('total_money', 14, 2)
                        ->nullable();

                    $table->unsignedTinyInteger('online_status')
                        ->nullable();

                    $table->unsignedTinyInteger('status')
                        ->nullable();

                    $table->string('archive_reason', 80);
                    $table->unsignedBigInteger('archived_at');
                }
            );
        }

        if (!$schema->hasColumns(
            'cx_pay_channel_archive',
            self::ARCHIVE_COLUMNS
        )) {
            throw new RuntimeException('归档表结构不完整');
        }

        // 明确禁止归档敏感配置字段。
        if ($schema->hasColumn(
            'cx_pay_channel_archive',
            'config'
        )) {
            throw new RuntimeException(
                '归档表不得包含敏感配置字段 config'
            );
        }
    }

    /**
     * 返回待处理数据统计，不执行任何 DML。
     *
     * @return array{
     *   channel_count:int,
     *   poll_group_links:int,
     *   plans_to_update:int,
     *   pending_orders:int,
     *   channels:list<array<string,mixed>>
     * }
     */
    public function preview(): array
    {
        $this->ensureArchiveTable();

        $channels = $this->targetChannels(false);
        $channelIds = $this->channelIds($channels);

        return [
            'channel_count' => count($channels),
            'poll_group_links' =>
                $this->countPollGroupLinks($channelIds),
            'plans_to_update' =>
                count($this->planUpdates()),
            'pending_orders' =>
                $this->countPendingOrders($channelIds),
            'channels' => $channels,
        ];
    }

    /**
     * 在一个 DML 事务中归档并删除旧活动通道。
     *
     * @return array{
     *   archived:int,
     *   poll_group_links_deleted:int,
     *   plans_updated:int,
     *   channels_deleted:int,
     *   remaining:int
     * }
     */
    public function apply(): array
    {
        // DDL 必须在 DML 事务外完成。
        $this->ensureArchiveTable();

        return $this->db->transaction(function (): array {
            $channels = $this->targetChannels(true);

            if ($channels === []) {
                return $this->emptyApplyResult();
            }

            $channelIds = $this->channelIds($channels);
            $pendingOrders =
                $this->countPendingOrders($channelIds);

            if ($pendingOrders > 0) {
                throw new RuntimeException(
                    "仍有 {$pendingOrders} 个待支付订单引用待删除通道"
                );
            }

            $archived = 0;
            $archivedAt = time();

            foreach ($channels as $channel) {
                $archived += $this->db
                    ->table('cx_pay_channel_archive')
                    ->insertOrIgnore([
                        'original_channel_id' =>
                            (int)$channel['id'],
                        'merchant_id' =>
                            $channel['merchant_id'],
                        'pay_category' =>
                            $channel['pay_category'],
                        'title' =>
                            $channel['title'],
                        'c_type' =>
                            $channel['c_type'],
                        'remark' =>
                            $channel['remark'],
                        'weight' =>
                            $channel['weight'],
                        'single_min' =>
                            $channel['single_min'],
                        'single_max' =>
                            $channel['single_max'],
                        'day_max' =>
                            $channel['day_max'],
                        'today_money' =>
                            $channel['today_money'],
                        'today_count' =>
                            $channel['today_count'],
                        'total_money' =>
                            $channel['total_money'],
                        'online_status' =>
                            $channel['online_status'],
                        'status' =>
                            $channel['status'],
                        'archive_reason' =>
                            RemovedPaymentDrivers::archiveReason(
                                (string)$channel['c_type']
                            ),
                        'archived_at' =>
                            $archivedAt,
                    ]);
            }

            $pollLinksDeleted =
                $this->deletePollGroupLinks($channelIds);

            $plansUpdated = 0;
            foreach ($this->planUpdates() as $planId => $cleaned) {
                $plansUpdated += $this->db
                    ->table('cx_plan')
                    ->where('id', $planId)
                    ->update([
                        'allowed_channels' => $cleaned,
                    ]);
            }

            $channelsDeleted = $this->db
                ->table('cx_pay_channel')
                ->whereIn('id', $channelIds)
                ->delete();

            $remaining = $this->db
                ->table('cx_pay_channel')
                ->whereIn(
                    'c_type',
                    RemovedPaymentDrivers::all()
                )
                ->count();

            if ($remaining !== 0) {
                throw new RuntimeException(
                    "旧支付通道清理不完整，仍残留 {$remaining} 条"
                );
            }

            return [
                'archived' => $archived,
                'poll_group_links_deleted' =>
                    $pollLinksDeleted,
                'plans_updated' => $plansUpdated,
                'channels_deleted' => $channelsDeleted,
                'remaining' => $remaining,
            ];
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function targetChannels(bool $lock): array
    {
        $query = $this->db
            ->table('cx_pay_channel')
            ->select(self::CHANNEL_COLUMNS)
            ->whereIn(
                'c_type',
                RemovedPaymentDrivers::all()
            )
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return array_map(
            static fn(object $row): array => (array)$row,
            $query->get()->all()
        );
    }

    /**
     * @param list<array<string,mixed>> $channels
     * @return list<int>
     */
    private function channelIds(array $channels): array
    {
        return array_map(
            static fn(array $channel): int =>
                (int)$channel['id'],
            $channels
        );
    }

    /**
     * @param list<int> $channelIds
     */
    private function countPendingOrders(
        array $channelIds
    ): int {
        if ($channelIds === []) {
            return 0;
        }

        $schema = $this->db->getSchemaBuilder();

        if (!$schema->hasTable('cx_order')) {
            return 0;
        }

        if (!$schema->hasColumns(
            'cx_order',
            ['channel_id', 'status']
        )) {
            throw new RuntimeException('订单表结构不完整');
        }

        return $this->db
            ->table('cx_order')
            ->whereIn('channel_id', $channelIds)
            ->where('status', 0)
            ->count();
    }

    /**
     * @param list<int> $channelIds
     */
    private function countPollGroupLinks(
        array $channelIds
    ): int {
        if ($channelIds === []) {
            return 0;
        }

        $schema = $this->db->getSchemaBuilder();

        if (!$schema->hasTable('cx_poll_group_channel')) {
            return 0;
        }

        if (!$schema->hasColumn(
            'cx_poll_group_channel',
            'channel_id'
        )) {
            throw new RuntimeException(
                '轮询组通道关联表结构不完整'
            );
        }

        return $this->db
            ->table('cx_poll_group_channel')
            ->whereIn('channel_id', $channelIds)
            ->count();
    }

    /**
     * @param list<int> $channelIds
     */
    private function deletePollGroupLinks(
        array $channelIds
    ): int {
        if ($channelIds === []) {
            return 0;
        }

        $schema = $this->db->getSchemaBuilder();

        if (!$schema->hasTable('cx_poll_group_channel')) {
            return 0;
        }

        if (!$schema->hasColumn(
            'cx_poll_group_channel',
            'channel_id'
        )) {
            throw new RuntimeException(
                '轮询组通道关联表结构不完整'
            );
        }

        return $this->db
            ->table('cx_poll_group_channel')
            ->whereIn('channel_id', $channelIds)
            ->delete();
    }

    /**
     * 返回套餐 ID 与清理后的 allowed_channels。
     *
     * @return array<int,string>
     */
    private function planUpdates(): array
    {
        $schema = $this->db->getSchemaBuilder();

        if (!$schema->hasTable('cx_plan')
            || !$schema->hasColumn(
                'cx_plan',
                'allowed_channels'
            )
        ) {
            return [];
        }

        if (!$schema->hasColumn('cx_plan', 'id')) {
            throw new RuntimeException('套餐表结构不完整');
        }

        $updates = [];

        foreach (
            $this->db
                ->table('cx_plan')
                ->select(['id', 'allowed_channels'])
                ->orderBy('id')
                ->get()
            as $plan
        ) {
            $original =
                (string)($plan->allowed_channels ?? '');
            $cleaned =
                RemovedPaymentDrivers::stripCsv($original);

            if ($cleaned !== $original) {
                $updates[(int)$plan->id] = $cleaned;
            }
        }

        return $updates;
    }

    /**
     * @return array{
     *   archived:int,
     *   poll_group_links_deleted:int,
     *   plans_updated:int,
     *   channels_deleted:int,
     *   remaining:int
     * }
     */
    private function emptyApplyResult(): array
    {
        return [
            'archived' => 0,
            'poll_group_links_deleted' => 0,
            'plans_updated' => 0,
            'channels_deleted' => 0,
            'remaining' => 0,
        ];
    }
}
