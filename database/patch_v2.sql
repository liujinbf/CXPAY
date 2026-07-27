-- CXPAY v2 补丁 SQL —— 与代码优化配套的数据库结构更新
-- 执行前请备份数据库！

SET NAMES utf8mb4;

-- ============================================================
-- 补丁 1: cx_callbill 增加 channel_id 列
-- 修复 CallbillService 挂机助手匹配缺少通道隔离的问题
-- ============================================================
ALTER TABLE `cx_callbill`
    ADD COLUMN `channel_id` int(11) NOT NULL DEFAULT 0 COMMENT '来源通道ID (0=未知，与cx_pay_channel.id关联)' AFTER `remark`;

-- ============================================================
-- 补丁 2: cx_callbill 增加 trade_no 列（原代码引用但表未定义）
-- ============================================================
ALTER TABLE `cx_callbill`
    ADD COLUMN `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '匹配到的CXPAY平台流水号' AFTER `channel_id`;

-- ============================================================
-- 补丁 3: cx_order 金额去重唯一约束（可选，评估后启用）
-- 在 (channel_id, price) 组合上建立普通索引提升匹配查询性能
-- 注：不做唯一约束，因为历史订单到期后 status 变化，允许重用金额
-- ============================================================
ALTER TABLE `cx_order`
    ADD INDEX `idx_channel_price_status` (`channel_id`, `price`, `status`),
    ADD INDEX `idx_expire_status` (`expire_time`, `status`);

-- ============================================================
-- 补丁 4: cx_pay_channel 增加通道 notify_token 和 notify_secret 字段
-- 已在各驱动 getMeta() inputs 定义，config JSON 字段中存储，无需单独列
-- （此补丁仅供参考，实际由 config JSON 存储，无需 ALTER）
-- ============================================================

-- 执行完成确认
SELECT 'CXPAY patch_v2 applied successfully' AS result;
