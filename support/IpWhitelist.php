<?php

declare(strict_types=1);

namespace support;

/**
 * 商户 API 来源 IP 白名单规范化与匹配工具。
 */
final class IpWhitelist
{
    public static function normalize(string $raw, int $maxItems = 50): ?string
    {
        if (trim($raw) === '') {
            return '';
        }
        $items = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($items) > $maxItems) {
            return null;
        }
        $normalized = [];
        foreach ($items as $item) {
            if (filter_var($item, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            $packed = inet_pton($item);
            if ($packed === false) {
                return null;
            }
            $normalized[bin2hex($packed)] = inet_ntop($packed);
        }
        return implode(',', array_values($normalized));
    }

    public static function allows(string $remoteIp, string $whitelist): bool
    {
        if (trim($whitelist) === '') {
            return true;
        }
        $remote = inet_pton($remoteIp);
        if ($remote === false) {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', trim($whitelist), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $allowedIp) {
            $allowed = inet_pton($allowedIp);
            if ($allowed !== false && hash_equals($allowed, $remote)) {
                return true;
            }
        }
        return false;
    }
}
