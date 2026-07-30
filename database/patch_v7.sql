-- CXPAY v7 功能增强补丁（执行前请完整备份数据库）
-- 包含：
--   1. cx_config 新增管理员静态二次验证码与 Token 版本号
--   2. cx_pay_channel 新增备用通道字段
--   3. cx_merchant 新增分支付类型费率配置字段
SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────
-- 1. cx_config 新增管理员登录二次验证相关配置
--    admin_verify_code_enabled : 是否开启二次验证码 (0=关闭 1=开启)
--    admin_verify_code         : 静态验证码（AES-256-GCM 加密存储）
--    admin_token_version       : Token 版本号，密码修改时递增使旧 Token 失效
-- ─────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cx_config` (`name`, `title`, `value`) VALUES
  ('admin_verify_code_enabled', '管理员二次验证码开关(0关/1开)', '0'),
  ('admin_verify_code',         '管理员二次验证码(加密存储)',     ''),
  ('admin_token_version',       'Token版本号（密码修改时递增）',  '1');

-- ─────────────────────────────────────────────────────────────────
-- 2. cx_pay_channel 新增备用通道字段
--    fallback_channel_id : 主通道掉线时自动切换的备用通道ID
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `fallback_channel_id` int(11) NOT NULL DEFAULT '0'
        COMMENT '备用通道ID，主通道掉线时自动切换，0表示不配置备用' AFTER `weight`;

-- ─────────────────────────────────────────────────────────────────
-- 3. cx_merchant 新增分支付类型费率配置
--    rate_config : JSON格式，{"wxpay":0.015,"alipay":0.02}
--                  按 c_type 前缀匹配，未匹配则回退到全局 rate 字段
-- ─────────────────────────────────────────────────────────────────
ALTER TABLE `cx_merchant`
    ADD COLUMN IF NOT EXISTS `rate_config` json DEFAULT NULL
        COMMENT '分支付类型费率配置 JSON，{"wxpay":0.015,"alipay":0.02}，空则使用全局rate' AFTER `rate`;

SELECT 'CXPAY patch_v7 applied successfully' AS result;
