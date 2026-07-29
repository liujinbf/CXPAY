<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use RuntimeException;

final class SecretVault
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('WXMC_MASTER_KEY 必须是 Base64 编码的 32 字节密钥');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('敏感配置加密失败');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('敏感配置密文损坏');
        }
        $plaintext = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );
        if ($plaintext === false) {
            throw new RuntimeException('敏感配置解密失败');
        }
        return $plaintext;
    }
}
