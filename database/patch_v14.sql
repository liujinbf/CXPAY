-- CXPAY v14 补丁 — P0/P1 差距修复配套数据库变更
-- 包含：
--   1. cx_audit_log 补充索引（加速按操作人/时间查询）
--   2. cx_admin 补充 invite_code 邀请码追踪字段（可选，用于审计谁邀请了谁）
SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. cx_audit_log 补充复合索引（加速后台审计日志分页查询）
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `cx_audit_log`
    ADD INDEX IF NOT EXISTS `idx_operator`   (`operator`),
    ADD INDEX IF NOT EXISTS `idx_action`     (`action`),
    ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`),
    ADD INDEX IF NOT EXISTS `idx_result`     (`result`);

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. cx_admin 补充邀请来源追踪字段
-- ─────────────────────────────────────────────────────────────────────────────
ALTER TABLE `cx_admin`
    ADD COLUMN IF NOT EXISTS `invited_by` varchar(64) NOT NULL DEFAULT '' COMMENT '邀请人账号' AFTER `create_time`,
    ADD COLUMN IF NOT EXISTS `last_token_at` int(11) NOT NULL DEFAULT 0 COMMENT '最后颁发Token时间' AFTER `invited_by`;

SELECT 'CXPAY patch_v14 applied successfully' AS result;
