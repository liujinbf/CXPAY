<?php

declare(strict_types=1);

namespace WxCollector;

/**
 * 合法数据源适配器契约。
 *
 * 实现方必须确保二维码、账号能力和账单都来自用户明确授权的数据源。
 */
interface ProviderAdapterInterface
{
    /**
     * 首次领取授权任务。没有准备好二维码时返回 null，下个周期会重试。
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>|null
     */
    public function startAuthorization(array $task): ?array;

    /**
     * 轮询已经由本采集器接管的授权任务。状态无变化时返回 null。
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>|null
     */
    public function pollAuthorization(array $task): ?array;

    /**
     * 拉取尚未确认提交的真实账单。每项必须包含本地稳定 ack_token。
     *
     * @return list<array<string, mixed>>
     */
    public function pullPaymentEvents(int $limit): array;

    /** 云端明确接收后才确认本地游标。 */
    public function acknowledgePaymentEvent(string $ackToken): void;
}
