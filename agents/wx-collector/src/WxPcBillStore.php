<?php

declare(strict_types=1);

namespace WxCollector;

use PDO;
use RuntimeException;

/**
 * 微信 PC Hook 采集器本地账单存储。
 *
 * 使用 SQLite 在本地维护收款账单队列。账单先写入数据库，
 * 云端确认接收（accepted=true）后才推进 acked_at，防止进程崩溃丢账单。
 * raw_data 字段使用 AES-256-GCM 加密，不明文保存微信原始消息内容。
 */
final class WxPcBillStore
{
    private PDO $db;
    private string $key;

    public function __construct(string $dbPath, string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('WXPC_MASTER_KEY 必须是 Base64 编码的 32 字节密钥');
        }
        $this->key = $key;

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建微信账单存储目录');
        }

        $this->db = new PDO('sqlite:' . $dbPath, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->migrate();
    }

    /**
     * 写入一条收款账单。bill_id 相同则幂等忽略（防止重复 Hook 事件）。
     *
     * @param array<string, mixed> $rawData 原始消息（会被加密存储，不上报云端）
     */
    public function insert(
        string $billId,
        string $accountRef,
        string $amount,
        int $occurredAt,
        array $rawData = [],
    ): void {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $billId)) {
            throw new RuntimeException('微信账单号格式不合法');
        }
        if (!preg_match('/^\d+\.\d{2}$/', $amount)) {
            throw new RuntimeException('账单金额必须是两位小数字符串');
        }
        $encrypted = $this->encrypt(json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->db->prepare(
            'INSERT OR IGNORE INTO wx_bills
             (bill_id, account_ref, amount, occurred_at, raw_data_enc, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$billId, $accountRef, $amount, $occurredAt, $encrypted, time()]);
    }

    /**
     * 拉取尚未被云端确认的账单（acked_at IS NULL）。
     *
     * @return list<array{ack_token:string, account_ref:string, bill_id:string, amount:string, occurred_at:int}>
     */
    public function pullPending(int $limit): array
    {
        $rows = $this->db->prepare(
            'SELECT id, bill_id, account_ref, amount, occurred_at
             FROM wx_bills WHERE acked_at IS NULL
             ORDER BY id ASC LIMIT ?'
        );
        $rows->execute([max(1, min(100, $limit))]);
        $result = [];
        foreach ($rows->fetchAll() as $row) {
            $result[] = [
                'ack_token'   => (string)$row['id'],
                'bill_id'     => (string)$row['bill_id'],
                'account_ref' => (string)$row['account_ref'],
                'amount'      => (string)$row['amount'],
                'occurred_at' => (int)$row['occurred_at'],
            ];
        }
        return $result;
    }

    /**
     * 云端确认接收后标记账单已确认（按主键 id）。
     */
    public function ack(string $ackToken): void
    {
        if (!preg_match('/^\d+$/', $ackToken)) {
            throw new RuntimeException('ack_token 不合法');
        }
        $stmt = $this->db->prepare('UPDATE wx_bills SET acked_at = ? WHERE id = ? AND acked_at IS NULL');
        $stmt->execute([time(), (int)$ackToken]);
    }

    /**
     * 查询指定 bill_id 是否已存在（防止重复写入）。
     */
    public function exists(string $billId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM wx_bills WHERE bill_id = ? LIMIT 1');
        $stmt->execute([$billId]);
        return $stmt->fetchColumn() !== false;
    }

    private function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS wx_bills (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                bill_id      TEXT    NOT NULL UNIQUE,
                account_ref  TEXT    NOT NULL,
                amount       TEXT    NOT NULL,
                occurred_at  INTEGER NOT NULL,
                raw_data_enc TEXT,
                acked_at     INTEGER,
                created_at   INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_wx_bills_pending ON wx_bills(acked_at) WHERE acked_at IS NULL;'
        );
    }

    private function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('账单原始数据加密失败');
        }
        return base64_encode($iv . $tag . $ct);
    }
}
