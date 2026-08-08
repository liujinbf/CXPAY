-- CXPAY patch_v10 - 主备通道自动故障转移 + 商户分类型差异化费率
-- 执行前请完整备份数据库
-- 包含：
--   1. cx_pay_channel  增加 fallback_channel_id — 主备通道自动切换
--   2. cx_merchant     增加 rate_config         — 按支付类型差异化费率

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
-- 1. cx_pay_channel 增加 fallback_channel_id
--
-- 当主通道 online_status=0 或心跳超时时，OrderService 自动切换至此字段
-- 指向的备用通道。0 表示无备用通道。
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `fallback_channel_id` int(11) NOT NULL DEFAULT 0
        COMMENT '备用通道 ID（主通道离线时自动切换），0 = 无备用通道'
        AFTER `last_heartbeat_time`;

-- ─────────────────────────────────────────────────────────────────────────
-- 2. cx_merchant 增加 rate_config
--
-- JSON 格式，存储按支付类型的差异化费率，示例：
--   {"wxpay": 1.5, "alipay": 2.0, "qqpay": 1.8}
-- 计算手续费时优先读取该字段对应 pay_category 的费率，
-- 未配置时回退至 cx_merchant.rate 全局费率。
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE `cx_merchant`
    ADD COLUMN IF NOT EXISTS `rate_config` json NULL
        COMMENT '分支付类型差异化费率 JSON，如 {"wxpay":1.5,"alipay":2.0}；NULL 表示统一使用 rate 字段'
        AFTER `rate`;

SELECT 'CXPAY patch_v10 applied successfully' AS result;
