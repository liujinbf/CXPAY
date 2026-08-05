<?php

declare(strict_types=1);

namespace app\payment;

use InvalidArgumentException;

final class RemovedPaymentDrivers
{
    /** @var list<string> */
    private const CODES = [
        'alipay_official',
        'wxpay_official',
        'alipay_scan_bill',
        'wxpay_protocol_cloud',
        'qqpay_protocol_cloud',
        'qqpay_epay',
    ];

    private const DEFAULT_ARCHIVE_REASON =
        'removed_placeholder_or_shared_token_driver';

    /** @var array<string,string> */
    private const ARCHIVE_REASONS = [
        'qqpay_epay' => 'superseded_by_epay_generic',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::CODES;
    }

    public static function contains(string $cType): bool
    {
        return in_array(trim($cType), self::CODES, true);
    }

    public static function assertAllowed(string $cType): void
    {
        if (self::contains($cType)) {
            throw new InvalidArgumentException(
                "支付驱动已永久移除: {$cType}"
            );
        }
    }

    public static function archiveReason(
        string $cType
    ): string {
        $code = trim($cType);

        if (!self::contains($code)) {
            throw new InvalidArgumentException(
                "支付驱动不在永久移除名单中: {$code}"
            );
        }

        return self::ARCHIVE_REASONS[$code]
            ?? self::DEFAULT_ARCHIVE_REASON;
    }

    public static function stripCsv(string $csv): string
    {
        $kept = [];

        foreach (explode(',', $csv) as $value) {
            $code = trim($value);

            if ($code !== '' && !self::contains($code)) {
                $kept[] = $code;
            }
        }

        return implode(',', $kept);
    }
}
