-- patch_v11.sql
-- 功能：
--   1. cx_pay_channel 增加 online_since 字段，记录通道最后一次上线时间戳
--      用于前端展示「已在线 X 小时 X 分钟」在线时长

-- 1. cx_pay_channel 增加 online_since 字段
ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `online_since` int(11) NOT NULL DEFAULT 0
        COMMENT '通道最后一次切换为在线状态的时间戳（0=从未上线）'
        AFTER `online_status`;

-- 初始化：对已在线的通道，将 online_since 设为当前时间（避免显示异常）
UPDATE `cx_pay_channel`
SET `online_since` = UNIX_TIMESTAMP()
WHERE `online_status` = 1 AND `online_since` = 0;
