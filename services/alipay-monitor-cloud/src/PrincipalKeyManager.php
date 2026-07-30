<?php

declare(strict_types=1);

namespace AlipayMonitorCloud;

use PDO;
use RuntimeException;

final class PrincipalKeyManager
{
    private PDO $db;
    private string $masterKey;

    public function __construct(PDO $db, string $masterKey)
    {
        $this->db = $db;
        $this->masterKey = $masterKey;
    }

    public function getActiveRequestSecret(string $principalId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT secret_enc FROM amc_principal_keys
             WHERE principal_id = ? AND key_role = ? AND status = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$principalId, 'request', 'active']);
        $enc = $stmt->fetchColumn();
        return is_string($enc) ? $this->decrypt($enc) : null;
    }

    public function getActiveResponseSecret(string $principalId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT secret_enc FROM amc_principal_keys
             WHERE principal_id = ? AND key_role = ? AND status = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$principalId, 'response', 'active']);
        $enc = $stmt->fetchColumn();
        return is_string($enc) ? $this->decrypt($enc) : null;
    }

    public function setKey(string $principalId, string $keyRole, string $secret): void
    {
        if (strlen($secret) < 32 || strlen($secret) > 128) {
            throw new RuntimeException('密钥长度必须在 32 到 128 字符之间');
        }
        $enc = $this->encrypt($secret);
        $this->db->prepare(
            'INSERT INTO amc_principal_keys (principal_id, key_role, secret_enc, status, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$principalId, $keyRole, $enc, 'active', time()]);
    }

    private function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $this->masterKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('密钥加密失败');
        }
        return base64_encode($iv . $tag . $ct);
    }

    private function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('密钥密文格式无效');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $this->masterKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new RuntimeException('密钥解密失败');
        }
        return $pt;
    }
}
