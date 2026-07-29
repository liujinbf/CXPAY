<?php

declare(strict_types=1);

namespace WxCollector;

use RuntimeException;

/** 支付账号会话的本地加密存储，明文 Cookie 不进入云端协调服务。 */
final class EncryptedFileStateStore
{
    private string $key;

    public function __construct(private readonly string $directory, string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('ALIC_MASTER_KEY 必须是 Base64 编码的 32 字节密钥');
        }
        $this->key = $key;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建支付宝会话存储目录');
        }
    }

    /** @param array<string, mixed> $state */
    public function put(string $id, array $state): void
    {
        $path = $this->path($id);
        $plaintext = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('支付宝会话加密失败');
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, base64_encode($iv . $tag . $ciphertext), LOCK_EX) === false
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('支付宝会话保存失败');
        }
        @chmod($path, 0600);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return null;
        }
        $encoded = file_get_contents($path);
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('支付宝会话密文损坏');
        }
        $plaintext = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );
        $state = $plaintext === false ? null : json_decode($plaintext, true);
        if (!is_array($state)) {
            throw new RuntimeException('支付宝会话无法解密');
        }
        return $state;
    }

    public function bindAccount(string $sessionId, string $accountId): void
    {
        $session = $this->get($sessionId);
        $account = $this->get($accountId);
        if ($session === null) {
            if ($account !== null
                && hash_equals((string)($account['authorization_session_id'] ?? ''), $sessionId)) {
                return;
            }
            throw new RuntimeException('待绑定的支付宝授权会话不存在');
        }
        if ($account !== null
            && !hash_equals((string)($account['authorization_session_id'] ?? ''), $sessionId)) {
            throw new RuntimeException('云账号已经绑定其他支付宝授权会话');
        }
        $session['authorization_session_id'] = $sessionId;
        $session['cloud_account_id'] = $accountId;
        $session['bound_at'] = time();
        $this->put($accountId, $session);
        $this->delete($sessionId);
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('支付宝会话清理失败');
        }
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id)) {
            throw new RuntimeException('支付宝授权会话 ID 不合法');
        }
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $id . '.state';
    }
}
