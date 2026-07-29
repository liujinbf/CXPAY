<?php

declare(strict_types=1);

namespace WxCollector;

/** 默认安全适配器：未配置合法数据源时不生成二维码、不产生账单。 */
final class UnavailableProviderAdapter implements ProviderAdapterInterface
{
    public function startAuthorization(array $task): ?array
    {
        return null;
    }

    public function pollAuthorization(array $task): ?array
    {
        return null;
    }

    public function pullPaymentEvents(int $limit): array
    {
        return [];
    }

    public function acknowledgePaymentEvent(string $ackToken): void
    {
    }
}
