<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use PDO;
use RuntimeException;
use Throwable;

final class PrincipalKeyManager
{
    public function __construct(private readonly PDO $pdo, private readonly SecretVault $vault)
    {
    }

    /** @return list<string> */
    public function verificationSecrets(array $principal, string $type = 'request'): array
    {
        $now = time();
        $statement = $this->pdo->prepare(
            "SELECT encrypted_secret FROM principal_keys
             WHERE principal_id = ? AND key_type = ? AND not_before <= ?
               AND ((status = 'ACTIVE' AND (expires_at = 0 OR expires_at > ?))
                    OR (status = 'GRACE' AND expires_at > ?))
             ORDER BY CASE status WHEN 'ACTIVE' THEN 0 ELSE 1 END, created_at DESC"
        );
        $statement->execute([(string)$principal['id'], $type, $now, $now, $now]);
        $encrypted = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($encrypted === []) {
            if ($this->hasManagedKeys((string)$principal['id'], $type)) {
                return [];
            }
            $legacy = (string)($principal[$type . '_secret'] ?? '');
            return $legacy === '' ? [] : [$this->vault->decrypt($legacy)];
        }
        return array_map(fn (string $value): string => $this->vault->decrypt($value), $encrypted);
    }

    public function activeSecret(array $principal, string $type): string
    {
        $now = time();
        $statement = $this->pdo->prepare(
            "SELECT encrypted_secret FROM principal_keys
             WHERE principal_id = ? AND key_type = ? AND status = 'ACTIVE' AND not_before <= ?
               AND (expires_at = 0 OR expires_at > ?)
             ORDER BY CASE WHEN expires_at = 0 THEN 0 ELSE 1 END, not_before DESC, created_at DESC LIMIT 1"
        );
        $statement->execute([(string)$principal['id'], $type, $now, $now]);
        $encrypted = (string)($statement->fetchColumn() ?: '');
        if ($encrypted === '' && !$this->hasManagedKeys((string)$principal['id'], $type)) {
            $encrypted = (string)($principal[$type . '_secret'] ?? '');
        }
        if ($encrypted === '') {
            throw new RuntimeException('当前有效密钥不存在');
        }
        return $this->vault->decrypt($encrypted);
    }

    /** @return array{id:string,secret:string,type:string,activates_at:int,grace_until:int} */
    public function rotate(
        string $principalId,
        string $type,
        int $graceSeconds,
        ?string $secret = null,
        int $activateAfterSeconds = 0
    ): array
    {
        $this->validateType($type);
        if ($graceSeconds < 0 || $graceSeconds > 86400) {
            throw new RuntimeException('密钥宽限期必须在 0 到 86400 秒之间');
        }
        if ($activateAfterSeconds < 0 || $activateAfterSeconds > 86400) {
            throw new RuntimeException('密钥延迟生效时间必须在 0 到 86400 秒之间');
        }
        $secret ??= bin2hex(random_bytes(32));
        if (strlen($secret) < 32 || strlen($secret) > 128) {
            throw new RuntimeException('密钥长度必须为 32 至 128 位');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $query = $this->pdo->prepare('SELECT * FROM principals WHERE id = ? AND status = 1' . $lock);
            $query->execute([$principalId]);
            $principal = $query->fetch();
            if (!$principal) {
                throw new RuntimeException('调用方身份不存在或已停用');
            }
            if ($type === 'response' && $principal['role'] !== 'client') {
                throw new RuntimeException('采集器没有响应签名密钥');
            }
            $now = time();
            $pending = $this->pdo->prepare(
                "SELECT 1 FROM principal_keys
                 WHERE principal_id = ? AND key_type = ? AND status = 'ACTIVE' AND not_before > ? LIMIT 1"
            );
            $pending->execute([$principalId, $type, $now]);
            if ($pending->fetchColumn() !== false) {
                throw new RuntimeException('该类型已有待生效密钥，请先等待生效或吊销后再轮换');
            }
            $activatesAt = $now + $activateAfterSeconds;
            $oldExpiresAt = $activatesAt + $graceSeconds;
            if (!$this->hasManagedKeys($principalId, $type)) {
                $legacy = (string)$principal[$type . '_secret'];
                if ($legacy !== '' && ($graceSeconds > 0 || $activateAfterSeconds > 0)) {
                    $this->insertKey($principalId, $type, $legacy, 'ACTIVE', $now, $oldExpiresAt);
                }
            } else {
                $this->pdo->prepare(
                    "UPDATE principal_keys SET expires_at = ?
                     WHERE principal_id = ? AND key_type = ? AND status = 'ACTIVE' AND not_before <= ?"
                )->execute([$oldExpiresAt, $principalId, $type, $now]);
            }
            $encrypted = $this->vault->encrypt($secret);
            $id = $this->insertKey($principalId, $type, $encrypted, 'ACTIVE', $activatesAt, 0);
            $column = $type === 'request' ? 'request_secret' : 'response_secret';
            $this->pdo->prepare("UPDATE principals SET {$column} = ? WHERE id = ?")
                ->execute([$encrypted, $principalId]);
            $this->pdo->commit();
            return [
                'id' => $id,
                'secret' => $secret,
                'type' => $type,
                'activates_at' => $activatesAt,
                'grace_until' => $oldExpiresAt,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function revoke(string $principalId, string $keyId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $query = $this->pdo->prepare(
                "SELECT key_type, status FROM principal_keys WHERE id = ? AND principal_id = ?" . $lock
            );
            $query->execute([$keyId, $principalId]);
            $key = $query->fetch();
            if (!$key || $key['status'] === 'REVOKED') {
                $this->pdo->commit();
                return false;
            }
            if ($key['key_type'] === 'response' && $key['status'] === 'ACTIVE') {
                $now = time();
                $replacement = $this->pdo->prepare(
                    "SELECT 1 FROM principal_keys
                     WHERE principal_id = ? AND key_type = 'response' AND status = 'ACTIVE'
                       AND id <> ? AND not_before <= ? AND (expires_at = 0 OR expires_at > ?)
                     LIMIT 1"
                );
                $replacement->execute([$principalId, $keyId, $now, $now]);
                if ($replacement->fetchColumn() === false) {
                    throw new RuntimeException('不能吊销唯一的当前响应密钥，请先完成轮换并等待新密钥生效');
                }
            }
            $statement = $this->pdo->prepare(
                "UPDATE principal_keys SET status = 'REVOKED', expires_at = ? WHERE id = ? AND principal_id = ?"
            );
            $statement->execute([time(), $keyId, $principalId]);
            $this->pdo->commit();
            return $statement->rowCount() === 1;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(string $principalId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, key_type, status, not_before, expires_at, created_at
             FROM principal_keys WHERE principal_id = ? ORDER BY created_at DESC, id DESC'
        );
        $statement->execute([$principalId]);
        return $statement->fetchAll();
    }

    public function register(string $principalId, string $type, string $encryptedSecret): string
    {
        $this->validateType($type);
        return $this->insertKey($principalId, $type, $encryptedSecret, 'ACTIVE', time(), 0);
    }

    private function hasManagedKeys(string $principalId, string $type): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM principal_keys WHERE principal_id = ? AND key_type = ? LIMIT 1');
        $statement->execute([$principalId, $type]);
        return $statement->fetchColumn() !== false;
    }

    private function insertKey(
        string $principalId,
        string $type,
        string $encrypted,
        string $status,
        int $notBefore,
        int $expiresAt
    ): string {
        $id = 'key_' . bin2hex(random_bytes(12));
        $this->pdo->prepare(
            'INSERT INTO principal_keys(
                id, principal_id, key_type, encrypted_secret, status, not_before, expires_at, created_at
             ) VALUES(?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $principalId, $type, $encrypted, $status, $notBefore, $expiresAt, time()]);
        return $id;
    }

    private function validateType(string $type): void
    {
        if (!in_array($type, ['request', 'response'], true)) {
            throw new RuntimeException('密钥类型只能是 request 或 response');
        }
    }
}
