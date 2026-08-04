<?php

declare(strict_types=1);

namespace app\service;

/**
 * Normalizes persisted platform channels for the administrator API.
 */
final class AdminChannelPresenter
{
    /**
     * @param array<int, array<string, mixed>> $channels
     * @return array<int, array<string, mixed>>
     */
    public static function format(array $channels): array
    {
        return array_map(static function (array $channel): array {
            $title = trim((string)($channel['title'] ?? ''));
            $type = trim((string)($channel['c_type'] ?? ''));
            $payType = trim((string)($channel['pay_category'] ?? ''));

            return [
                'id' => (int)($channel['id'] ?? 0),
                'code' => $type !== '' ? $type : 'unknown',
                'name' => $title !== '' ? $title : ($type !== '' ? $type : '未命名通道'),
                'pay_type' => $payType !== '' ? $payType : 'alipay',
                'c_type' => $type,
                'remark' => (string)($channel['remark'] ?? ''),
                'online_status' => (int)($channel['online_status'] ?? 0),
                'enabled' => (int)($channel['status'] ?? 0) === 1,
                'weight' => (int)($channel['weight'] ?? 100),
                'configured' => true,
            ];
        }, $channels);
    }
}
