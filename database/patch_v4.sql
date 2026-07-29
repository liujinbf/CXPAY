-- CXPAY v4 个人码账单幂等与防串单升级脚本（执行前请完整备份数据库）
SET NAMES utf8mb4;

ALTER TABLE `cx_callbill`
    ADD COLUMN `source_bill_id` varchar(128) NOT NULL DEFAULT ''
        COMMENT '客户端生成的稳定来源账单唯一标识' AFTER `device_id`,
    ADD COLUMN `occurred_at` int(11) NOT NULL DEFAULT '0'
        COMMENT '账单在收款端发生的时间戳' AFTER `order_id`,
    ADD COLUMN `raw_hash` char(64) NOT NULL DEFAULT ''
        COMMENT '原始通知内容SHA256摘要' AFTER `occurred_at`,
    ADD COLUMN `client_version` varchar(32) NOT NULL DEFAULT ''
        COMMENT '助手客户端版本' AFTER `raw_hash`,
    ADD COLUMN `review_note` varchar(255) NOT NULL DEFAULT ''
        COMMENT '人工复核备注' AFTER `client_version`;

ALTER TABLE `cx_callbill`
    MODIFY COLUMN `status` tinyint(1) DEFAULT '0'
        COMMENT '0待匹配 1已销账 2无匹配 3待复核 4处理中 5已忽略';

-- 历史账单没有来源ID，先按主键生成不可冲突的迁移标识，再添加唯一索引。
UPDATE `cx_callbill`
SET `source_bill_id` = CONCAT('legacy-', `id`)
WHERE `source_bill_id` = '';

ALTER TABLE `cx_callbill`
    ADD UNIQUE INDEX `uk_channel_source_bill` (`channel_id`, `source_bill_id`),
    ADD INDEX `idx_channel_raw_time` (`channel_id`, `raw_hash`, `occurred_at`);

SELECT 'CXPAY patch_v4 applied successfully' AS result;
