-- CXPAY v6 修复补丁（执行前请完整备份数据库）
-- 包含：
--   1. cx_pay_channel 加 stats_date 字段，实现跨日自动重置 today_money/today_count
--   2. cx_audit_log 管理员操作审计日志表（新建）
SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────
-- 1. cx_pay_channel 加 stats_date 字段
--    用于存储上次重置 today_money/today_count 时的日期（格式 YYYYMMDD）
--    服务端读取统计时，若 stats_date < 今日，自动将当日统计清零，
--    无需依赖外部定时任务，解决跨日后日限额永久失效问题。
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `stats_date` int(8) NOT NULL DEFAULT '0'
        COMMENT '今日统计日期(YYYYMMDD)，跨日自动重置 today_money/today_count' AFTER `today_count`;

-- 将已有记录的 stats_date 初始化为昨日，触发首次自动重置
UPDATE `cx_pay_channel`
SET `stats_date` = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 DAY), '%Y%m%d')
WHERE `stats_date` = 0;

-- ─────────────────────────────────────────────────────────────────
-- 2. cx_audit_log 管理员操作审计日志表（新建）
--    记录管理员补单、关单、修改商户费率、修改通道配置等敏感操作。
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cx_audit_log` (
  `id`         int(11) unsigned NOT NULL AUTO_INCREMENT,
  `operator`   varchar(64)  NOT NULL DEFAULT '' COMMENT '操作人账号',
  `action`     varchar(64)  NOT NULL DEFAULT '' COMMENT '操作类型(force_pay/close_order/...)',
  `context`    text         NOT NULL              COMMENT '操作上下文 JSON',
  `result`     varchar(16)  NOT NULL DEFAULT 'success' COMMENT 'success | fail',
  `ip`         varchar(64)  NOT NULL DEFAULT '' COMMENT '操作来源 IP',
  `created_at` int(11)      NOT NULL DEFAULT '0' COMMENT '操作时间戳',
  PRIMARY KEY (`id`),
  KEY `idx_operator_action` (`operator`, `action`),
  KEY `idx_created_at`      (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员操作审计日志';

SELECT 'CXPAY patch_v6 applied successfully' AS result;
