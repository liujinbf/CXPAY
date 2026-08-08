<?php

declare(strict_types=1);

namespace CloudControl\Shared\Security;

use InvalidArgumentException;

final class Base64UrlKey
{
    private const KEY_BYTES = 32;

    public static function decode(string $encoded, string $environmentName): string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw self::invalid($environmentName);
        }

        $remainder = strlen($encoded) % 4;
        if ($remainder === 1) {
            throw self::invalid($environmentName);
        }

        $padded = strtr($encoded, '-_', '+/') . str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            throw self::invalid($environmentName);
        }

        return $decoded;
    }

    private static function invalid(string $environmentName): InvalidArgumentException
    {
        return new InvalidArgumentException(
            sprintf('%s 必须是无填充 Base64URL 编码的 32 字节密钥', $environmentName)
        );
    }
}
