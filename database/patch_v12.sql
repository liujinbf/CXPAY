-- patch_v12.sql
-- 功能：
--   1. 增强通道在线时长与掉线前运行时长统计功能
--   2. 新增 offline_since 与 last_online_duration 字段
--   3. 修复并初始化历史所有通道的 online_since 字段

ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `offline_since` int(11) NOT NULL DEFAULT 0
        COMMENT '通道最后一次掉线时间戳'
        AFTER `online_since`;

ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `last_online_duration` int(11) NOT NULL DEFAULT 0
        COMMENT '通道掉线前最后一次连续在线运行总秒数'
        AFTER `offline_since`;

-- 针对所有当前在线的通道，若 online_since 为 0，立即初始化为当前时间戳
UPDATE `cx_pay_channel`
SET `online_since` = UNIX_TIMESTAMP()
WHERE `online_status` = 1 AND (`online_since` IS NULL OR `online_since` = 0);

-- 针对当前离线的通道，初始化 offline_since
UPDATE `cx_pay_channel`
SET `offline_since` = UNIX_TIMESTAMP()
WHERE `online_status` = 0 AND (`offline_since` IS NULL OR `offline_since` = 0);
