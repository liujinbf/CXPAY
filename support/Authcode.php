<?php

declare(strict_types=1);

namespace support;

/**
 * 通道密钥/敏感配置 RC4 Authcode 加解密类 (兼顾兼容性与高安全性)
 */
class Authcode
{
    protected string $secret;

    public function __construct(string $secret = 'CXPAY_DEFAULT_KEY_2026')
    {
        $this->secret = md5($secret);
    }

    public function encrypt(string $string): string
    {
        return $this->authcode($string, 'ENCODE');
    }

    public function decrypt(string $string)
    {
        return $this->authcode($string, 'DECODE');
    }

    protected function authcode(string $string, string $operation = 'DECODE'): string|bool
    {
        $ckey_length = 4;
        $keya = md5(substr($this->secret, 0, 16));
        $keyb = md5(substr($this->secret, 16, 16));
        $keyc = $ckey_length ? ($operation === 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';

        $cryptkey   = $keya . md5($keya . $keyc);
        $key_length = strlen($cryptkey);

        if ($operation === 'DECODE') {
            $base64 = substr($string, $ckey_length);
            if ($pad = strlen($base64) % 4) {
                $base64 .= str_repeat('=', 4 - $pad);
            }
            $decoded = base64_decode($base64);
            $string  = ($decoded === false) ? '' : $decoded;
        } else {
            $string = sprintf('%010d', 0) . substr(md5($string . $keyb), 0, 16) . $string;
        }

        $string_length = strlen($string);
        $result        = '';
        $box           = range(0, 255);
        $rndkey        = [];

        for ($i = 0; $i <= 255; $i++) {
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }

        for ($j = $i = 0; $i < 256; $i++) {
            $j       = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp     = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $a       = ($a + 1) % 256;
            $j       = ($j + $box[$a]) % 256;
            $tmp     = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
        }

        if ($operation === 'DECODE') {
            if (strlen($result) < 26) {
                return false;
            }
            $timestamp = substr($result, 0, 10);
            $signature = substr($result, 10, 16);
            $data      = substr($result, 26);

            if (($timestamp === '0000000000' || (int)$timestamp - time() > 0) && $signature === substr(md5($data . $keyb), 0, 16)) {
                return $data;
            }
            return false;
        }

        return $keyc . str_replace('=', '', base64_encode($result));
    }
}
