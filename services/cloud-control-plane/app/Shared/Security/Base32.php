<?php

declare(strict_types=1);

namespace CloudControl\Shared\Security;

final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encodeUnpadded(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        return $encoded;
    }

    public static function decode(string $encoded): string
    {
        $encoded = strtoupper($encoded);
        if ($encoded === '' || preg_match('/^[A-Z2-7]+$/D', $encoded) !== 1) {
            throw new \InvalidArgumentException('Base32 数据无效');
        }
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        foreach (str_split($encoded) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new \InvalidArgumentException('Base32 数据无效');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
            }
        }
        return $decoded;
    }
}
