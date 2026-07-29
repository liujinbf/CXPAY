<?php

declare(strict_types=1);

namespace support;

/**
 * 挂机助手 v2 通讯协议。
 *
 * 所有参与签名的字段必须先按这里的规则规范化，避免客户端与服务端因
 * 金额精度、字段顺序不同而产生验签歧义。
 */
final class AppasstProtocol
{
    public const VERSION = '2';

    /**
     * 签名原文：
     * version|channel_id|device_id|event|pay_type|money|source_bill_id|occurred_at|timestamp|nonce|client_version
     */
    public static function canonicalize(array $params): string
    {
        return implode('|', [
            (string)($params['version'] ?? self::VERSION),
            (string)(int)($params['channel_id'] ?? 0),
            trim((string)($params['device_id'] ?? '')),
            trim((string)($params['event'] ?? 'bill')),
            trim((string)($params['pay_type'] ?? '')),
            self::normalizeMoney($params['money'] ?? 0),
            trim((string)($params['source_bill_id'] ?? '')),
            (string)(int)($params['occurred_at'] ?? 0),
            (string)(int)($params['timestamp'] ?? 0),
            trim((string)($params['nonce'] ?? '')),
            trim((string)($params['client_version'] ?? '')),
        ]);
    }

    public static function sign(array $params, string $secret): string
    {
        return hash_hmac('sha256', self::canonicalize($params), $secret);
    }

    public static function verify(array $params, string $secret, string $signature): bool
    {
        if ($secret === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }
        return hash_equals(self::sign($params, $secret), strtolower($signature));
    }

    public static function normalizeMoney(mixed $money): string
    {
        return is_numeric($money) ? number_format((float)$money, 2, '.', '') : '0.00';
    }
}
