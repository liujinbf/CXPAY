-- CXPAY 数据库升级补丁 v7
-- 增加商户套餐手续费抵扣金字段，插入默认系统运营配置

SET NAMES utf8mb4;

-- 1. cx_merchant 表增加套餐手续费剩余抵扣金字段
ALTER TABLE `cx_merchant` 
ADD COLUMN `plan_fee_discount_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '套餐费用抵扣手续费剩余额度' AFTER `money`;

-- 2. 插入/初始化默认系统运营配置
INSERT INTO `cx_config` (`name`, `value`, `title`) VALUES 
('register_grant_balance', '10.00', '新商户注册赠送体验服务费余额(元)'),
('system_recharge_pid', '1000', '平台统一收单与充值系统商户PID')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);
