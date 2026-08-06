<?php

declare(strict_types=1);

namespace AlipayAutoConfig;

use RuntimeException;

/**
 * 文件系统会话存储。
 * 每个会话以 JSON 文件形式保存在 session_dir 目录下。
 * 文件名为 session_id（只含字母数字），避免路径穿越。
 */
final class SessionStore
{
    public function __construct(
        private readonly string $sessionDir,
        private readonly int    $ttl = 1200
    ) {
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0750, true) && !is_dir($sessionDir)) {
            throw new RuntimeException("会话存储目录无法创建: {$sessionDir}");
        }
    }

    /**
     * 创建新会话，返回会话 ID。
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $sessionId = bin2hex(random_bytes(20)); // 40 字符 hex
        $data['_created_at'] = time();
        $data['_expires_at'] = time() + $this->ttl;
        $data['_status']     = $data['_status'] ?? 'PENDING';
        $this->write($sessionId, $data);
        return $sessionId;
    }

    /**
     * 读取会话数据，会话不存在或已过期返回 null。
     *
     * @return array<string, mixed>|null
     */
    public function get(string $sessionId): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $sessionId)) {
            return null;
        }
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return null;
        }
        $raw = json_decode((string)file_get_contents($path), true);
        if (!is_array($raw)) {
            return null;
        }
        if ((int)($raw['_expires_at'] ?? 0) < time()) {
            @unlink($path);
            return null;
        }
        return $raw;
    }

    /**
     * 更新会话数据（部分字段合并）。
     *
     * @param array<string, mixed> $patch
     */
    public function update(string $sessionId, array $patch): bool
    {
        $data = $this->get($sessionId);
        if ($data === null) {
            return false;
        }
        $data = array_merge($data, $patch);
        $this->write($sessionId, $data);
        return true;
    }

    /**
     * 将会话状态更新为 CONFIRMED，并存储配置结果。
     *
     * @param array<string, mixed> $result
     */
    public function confirm(string $sessionId, array $result): bool
    {
        return $this->update($sessionId, [
            '_status'    => 'CONFIRMED',
            '_confirmed' => $result,
        ]);
    }

    /**
     * 将会话状态更新为 FAILED。
     */
    public function fail(string $sessionId, string $message): bool
    {
        return $this->update($sessionId, [
            '_status'        => 'FAILED',
            '_fail_message'  => $message,
        ]);
    }

    /**
     * 删除过期的会话文件（清理任务）。
     */
    public function gc(): int
    {
        $count = 0;
        foreach (glob($this->sessionDir . '/*.json') ?: [] as $file) {
            $raw = json_decode((string)file_get_contents($file), true);
            if (!is_array($raw) || (int)($raw['_expires_at'] ?? 0) < time()) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string, mixed> $data */
    private function write(string $sessionId, array $data): void
    {
        file_put_contents(
            $this->path($sessionId),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private function path(string $sessionId): string
    {
        return $this->sessionDir . '/' . $sessionId . '.json';
    }
}
