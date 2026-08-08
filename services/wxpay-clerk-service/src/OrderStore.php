<?php

declare(strict_types=1);

namespace WxpayClerk;

use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite 数据访问层。
 *
 * 管理四张表：
 *   orders        — CXPAY 登记的待匹配订单
 *   review_events — 无法自动匹配、进入人工审核的到账记录
 *   auth_sessions — 微信登录会话（绑定流程）
 *   accounts      — 店员账号在线状态
 */
final class OrderStore
{
    private PDO $db;

    public function __construct(string $sqlitePath)
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("SQLite 存储目录创建失败: {$dir}");
        }

        $this->db = new PDO('sqlite:' . $sqlitePath, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->db->exec('PRAGMA journal_mode=WAL;');
        $this->db->exec('PRAGMA foreign_keys=ON;');
        $this->migrate();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 订单
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 登记待匹配订单。若 out_trade_no 已存在则更新 expires_at。
     */
    public function upsertOrder(
        string $accountId,
        string $channelId,
        string $outTradeNo,
        string $amount,
        int    $expiresAt
    ): void {
        $now = time();
        $this->db->prepare(
            'INSERT INTO orders (account_id, channel_id, out_trade_no, amount, expires_at, created_at, status)
             VALUES (:aid, :cid, :otn, :amt, :exp, :now, \'PENDING\')
             ON CONFLICT(out_trade_no) DO UPDATE SET
               expires_at = excluded.expires_at,
               status     = CASE WHEN status = \'PENDING\' THEN \'PENDING\' ELSE status END'
        )->execute([
            ':aid' => $accountId, ':cid' => $channelId, ':otn' => $outTradeNo,
            ':amt' => $amount,    ':exp' => $expiresAt,  ':now' => $now,
        ]);
    }

    /**
     * 查询指定账号下指定金额、在有效期内的所有 PENDING 订单，按创建时间升序返回。
     *
     * @return list<array<string, mixed>>
     */
    public function findPendingByAmount(string $accountId, string $amount, int $createdBefore): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM orders
              WHERE account_id = :aid
                AND amount = :amt
                AND status = \'PENDING\'
                AND expires_at >= :now
                AND created_at <= :before
              ORDER BY created_at ASC'
        );
        $stmt->execute([':aid' => $accountId, ':amt' => $amount, ':now' => time(), ':before' => $createdBefore]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * 将订单标记为已匹配，记录来源账单号。
     */
    public function confirmOrder(string $outTradeNo, string $sourceBillId, int $matchedAt): bool
    {
        $rows = $this->db->prepare(
            'UPDATE orders SET status = \'CONFIRMED\', source_bill_id = :sid, matched_at = :mat
              WHERE out_trade_no = :otn AND status = \'PENDING\''
        );
        $rows->execute([':sid' => $sourceBillId, ':mat' => $matchedAt, ':otn' => $outTradeNo]);
        return $rows->rowCount() > 0;
    }

    /**
     * 清理过期的 PENDING 订单。
     */
    public function purgeExpiredOrders(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET status = 'EXPIRED' WHERE status = 'PENDING' AND expires_at < :now"
        );
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 人工审核事件
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 新增待审核到账事件。
     */
    public function createReviewEvent(
        string $accountId,
        string $amount,
        string $payerName,
        string $remark,
        int    $occurredAt,
        string $sourceBillId
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO review_events
               (account_id, amount, payer_name, remark, occurred_at, received_at, status, source_bill_id)
             VALUES (:aid, :amt, :payer, :remark, :occ, :now, \'PENDING\', :sbid)'
        );
        $stmt->execute([
            ':aid'   => $accountId, ':amt'    => $amount,
            ':payer' => $payerName, ':remark' => $remark,
            ':occ'   => $occurredAt, ':now'   => time(),
            ':sbid'  => $sourceBillId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * 获取指定账号下的所有待审核事件。
     *
     * @return list<array<string, mixed>>
     */
    public function getPendingReviewEvents(string $accountId = ''): array
    {
        if ($accountId !== '') {
            $stmt = $this->db->prepare(
                "SELECT * FROM review_events WHERE status = 'PENDING' AND account_id = :aid ORDER BY occurred_at DESC"
            );
            $stmt->execute([':aid' => $accountId]);
        } else {
            $stmt = $this->db->query(
                "SELECT * FROM review_events WHERE status = 'PENDING' ORDER BY occurred_at DESC"
            );
        }
        return $stmt->fetchAll() ?: [];
    }

    /**
     * 解决审核事件（手动关联或忽略）。
     */
    public function resolveReviewEvent(int $id, string $status, string $outTradeNo, string $operator, string $note): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE review_events SET status = :st, out_trade_no = :otn, operator = :op,
              note = :note, resolved_at = :now WHERE id = :id AND status = 'PENDING'"
        );
        $stmt->execute([
            ':st'  => $status, ':otn' => $outTradeNo, ':op'  => $operator,
            ':note'=> $note,   ':now' => time(),       ':id'  => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * 根据 ID 获取审核事件详情。
     *
     * @return array<string, mixed>|null
     */
    public function getReviewEvent(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM review_events WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 授权会话
    // ─────────────────────────────────────────────────────────────────────────

    public function createAuthSession(string $sessionId, string $reference, int $ttl): void
    {
        $now = time();
        $this->db->prepare(
            'INSERT OR REPLACE INTO auth_sessions (id, reference, status, created_at, expires_at)
             VALUES (:id, :ref, \'PENDING\', :now, :exp)'
        )->execute([':id' => $sessionId, ':ref' => $reference, ':now' => $now, ':exp' => $now + $ttl]);
    }

    public function updateAuthSession(string $sessionId, string $status, string $qrUrl = '', string $accountId = ''): void
    {
        $this->db->prepare(
            'UPDATE auth_sessions SET status = :st, qr_url = :qr, account_id = :aid WHERE id = :id'
        )->execute([':st' => $status, ':qr' => $qrUrl, ':aid' => $accountId, ':id' => $sessionId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAuthSession(string $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_sessions WHERE id = :id AND expires_at >= :now');
        $stmt->execute([':id' => $sessionId, ':now' => time()]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 账号管理
    // ─────────────────────────────────────────────────────────────────────────

    public function upsertAccount(string $accountId, string $nickname, string $geweAppId, string $status): void
    {
        $this->db->prepare(
            'INSERT INTO accounts (id, nickname, gewe_app_id, status, last_seen_at, created_at)
             VALUES (:id, :nick, :appid, :st, :now, :now)
             ON CONFLICT(id) DO UPDATE SET
               nickname = excluded.nickname, gewe_app_id = excluded.gewe_app_id,
               status = excluded.status, last_seen_at = excluded.last_seen_at'
        )->execute([
            ':id'    => $accountId, ':nick'  => $nickname,
            ':appid' => $geweAppId, ':st'    => $status,
            ':now'   => time(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllAccounts(): array
    {
        return $this->db->query('SELECT * FROM accounts ORDER BY last_seen_at DESC')->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccount(string $accountId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM accounts WHERE id = :id');
        $stmt->execute([':id' => $accountId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * 以 gewe_app_id 直接查询账号记录（O(1) 索引查询，取代全表遍历）。
     *
     * @return array<string, mixed>|null
     */
    public function getAccountByGeweAppId(string $geweAppId): ?array
    {
        if ($geweAppId === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM accounts WHERE gewe_app_id = :appid LIMIT 1');
        $stmt->execute([':appid' => $geweAppId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 数据库迁移
    // ─────────────────────────────────────────────────────────────────────────

    private function migrate(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS orders (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id     TEXT    NOT NULL,
                channel_id     TEXT    NOT NULL,
                out_trade_no   TEXT    NOT NULL UNIQUE,
                amount         TEXT    NOT NULL,
                expires_at     INTEGER NOT NULL,
                created_at     INTEGER NOT NULL,
                status         TEXT    NOT NULL DEFAULT 'PENDING',
                matched_at     INTEGER,
                source_bill_id TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_orders_lookup
              ON orders (account_id, amount, status, expires_at);

            CREATE TABLE IF NOT EXISTS review_events (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id     TEXT    NOT NULL,
                amount         TEXT    NOT NULL,
                payer_name     TEXT    NOT NULL DEFAULT '',
                remark         TEXT    NOT NULL DEFAULT '',
                occurred_at    INTEGER NOT NULL,
                received_at    INTEGER NOT NULL,
                status         TEXT    NOT NULL DEFAULT 'PENDING',
                source_bill_id TEXT    NOT NULL DEFAULT '',
                out_trade_no   TEXT,
                operator       TEXT,
                note           TEXT,
                resolved_at    INTEGER
            );

            CREATE TABLE IF NOT EXISTS auth_sessions (
                id          TEXT    PRIMARY KEY,
                reference   TEXT    NOT NULL,
                mode        TEXT    NOT NULL DEFAULT 'clerk',
                qr_url      TEXT    NOT NULL DEFAULT '',
                account_id  TEXT    NOT NULL DEFAULT '',
                status      TEXT    NOT NULL DEFAULT 'PENDING',
                created_at  INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS accounts (
                id           TEXT    PRIMARY KEY,
                nickname     TEXT    NOT NULL DEFAULT '',
                gewe_app_id  TEXT    NOT NULL DEFAULT '',
                status       TEXT    NOT NULL DEFAULT 'OFFLINE',
                last_seen_at INTEGER,
                created_at   INTEGER NOT NULL
            );
            -- gewe_app_id 索引：支持 O(1) 按 gewe_app_id 定位账号，避免每次 Webhook 全表遍历
            CREATE INDEX IF NOT EXISTS idx_accounts_gewe_app_id
              ON accounts (gewe_app_id);
        SQL);
    }
}
