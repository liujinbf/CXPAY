<?php

declare(strict_types=1);

namespace support;

use RuntimeException;

/**
 * 通道敏感配置加解密。
 * 新数据使用 AES-256-GCM；旧版 Authcode 密文仅保留解密兼容。
 */
class Authcode
{
    private const PREFIX = 'v2:';
    private const LEGACY_SECRET = 'CXPAY_DEFAULT_KEY_2026';

    private ?string $secret;

    public function __construct(?string $secret = null)
    {
        $secret ??= (string)env('APP_KEY', '');
        $this->secret = strlen($secret) >= 32 ? hash('sha256', $secret, true) : null;
    }

    public function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }
        if ($this->secret === null) {
            throw new RuntimeException('APP_KEY 未配置或长度不足32位，无法安全处理通道密钥');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $this->secret,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($cipherText === false) {
            throw new RuntimeException('敏感配置加密失败');
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipherText);
    }

    public function decrypt(string $cipherText): string|false
    {
        if ($cipherText === '') {
            return '';
        }
        if (!str_starts_with($cipherText, self::PREFIX)) {
            return $this->decryptLegacy($cipherText);
        }
        if ($this->secret === null) {
            throw new RuntimeException('APP_KEY 未配置或长度不足32位，无法解密通道密钥');
        }

        $payload = base64_decode(substr($cipherText, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 29) {
            return false;
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $encrypted = substr($payload, 28);
        return openssl_decrypt(
            $encrypted,
            'aes-256-gcm',
            $this->secret,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
    }

    /**
     * 解读数据库中的配置值。旧库可能存在未加密明文，可继续读取；v2 密文一旦
     * 鉴权失败则必须抛错，禁止把损坏的密文误当作真实配置继续调用上游。
     */
    public function decryptStored(string $storedValue): string
    {
        $decrypted = $this->decrypt($storedValue);
        if ($decrypted !== false) {
            return $decrypted;
        }
        if (str_starts_with($storedValue, self::PREFIX)) {
            throw new RuntimeException('敏感配置密文损坏或 APP_KEY 不匹配');
        }
        return $storedValue;
    }

    private function decryptLegacy(string $cipherText): string|false
    {
        $secret = md5(self::LEGACY_SECRET);
        $keyA = md5(substr($secret, 0, 16));
        $keyB = md5(substr($secret, 16, 16));
        $dynamicKey = substr($cipherText, 0, 4);
        $cryptKey = $keyA . md5($keyA . $dynamicKey);
        $encoded = substr($cipherText, 4);
        if ($padding = strlen($encoded) % 4) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $data = base64_decode($encoded, true);
        if ($data === false) {
            return false;
        }

        $box = range(0, 255);
        $randomKey = [];
        $keyLength = strlen($cryptKey);
        for ($i = 0; $i < 256; $i++) {
            $randomKey[$i] = ord($cryptKey[$i % $keyLength]);
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j = ($j + $box[$i] + $randomKey[$i]) % 256;
            [$box[$i], $box[$j]] = [$box[$j], $box[$i]];
        }

        $result = '';
        for ($a = $j = $i = 0, $length = strlen($data); $i < $length; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;
            [$box[$a], $box[$j]] = [$box[$j], $box[$a]];
            $result .= chr(ord($data[$i]) ^ $box[($box[$a] + $box[$j]) % 256]);
        }
        if (strlen($result) < 26) {
            return false;
        }
        $data = substr($result, 26);
        return hash_equals(substr($result, 10, 16), substr(md5($data . $keyB), 0, 16))
            ? $data
            : false;
    }
}
