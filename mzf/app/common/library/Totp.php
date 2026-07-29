<?php

namespace app\common\library;

/**
 * TOTP（RFC 6238）谷歌验证工具 —— 自包含，不依赖第三方。
 * 30 秒时间步、6 位、HMAC-SHA1、Base32 密钥。
 */
class Totp
{
    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    protected const PERIOD    = 30;
    protected const DIGITS    = 6;

    /** 生成 Base32 密钥（默认 16 位） */
    public static function genSecret(int $length = 16): string
    {
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= self::ALPHABET[random_int(0, 31)];
        }
        return $s;
    }

    /** otpauth:// URI（供验证器 App 扫码） */
    public static function keyUri(string $secret, string $label, string $issuer = 'xlpay'): string
    {
        $label  = rawurlencode($issuer . ':' . $label);
        $query  = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /** 校验动态码（window=允许的前后时间步容差） */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return false;
        }
        $counter = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::hotp($key, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** 指定计数器的 HOTP 值 */
    protected static function hotp(string $key, int $counter): string
    {
        $bin  = pack('N*', 0) . pack('N*', $counter); // 8 字节大端
        $hash = hash_hmac('sha1', $bin, $key, true);
        $off  = ord($hash[strlen($hash) - 1]) & 0x0f;
        $val  = ((ord($hash[$off]) & 0x7f) << 24)
            | ((ord($hash[$off + 1]) & 0xff) << 16)
            | ((ord($hash[$off + 2]) & 0xff) << 8)
            | (ord($hash[$off + 3]) & 0xff);
        return str_pad((string) ($val % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Base32 解码 */
    protected static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}
