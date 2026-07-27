-- ============================================================
-- CXPAY 缺陷修复补丁 SQL (patch_v1.sql)
-- 说明：对已部署的数据库执行此脚本，补全缺失字段
-- 新安装请直接使用 install.sql（已含这些字段）
-- ============================================================

-- FIX-6: cx_order 表补增 notify_status 字段
-- (MerchantNotifyService 中使用此字段记录回调是否成功)
ALTER TABLE `cx_order`
    ADD COLUMN IF NOT EXISTS `notify_status` tinyint(1) DEFAULT '0' COMMENT '0未通知 1已成功通知 2通知失败重试中'
    AFTER `pay_time`;

-- FIX-7: cx_pay_channel 表补增 last_heartbeat_time 字段
-- (ChannelMonitorService 通道心跳监控依赖此字段判断是否自动下线)
ALTER TABLE `cx_pay_channel`
    ADD COLUMN IF NOT EXISTS `last_heartbeat_time` int(11) DEFAULT '0' COMMENT '最后一次心跳上报时间戳'
    AFTER `online_status`;

-- 初始化管理员账号和 bcrypt 密码哈希配置
-- 默认密码 admin123，生产上线后请立即通过后台修改！
-- (password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]) 的结果)
INSERT INTO `cx_config` (`name`, `value`, `title`) VALUES
('admin_account',       'admin',                                                              '管理员账号'),
('admin_password_hash', '$2y$12$eImiTXuWVxfM37uY4JANjOe5XM.oFBkSPvHKxU3sXuCz5BKs8kFGy', '管理员密码Bcrypt哈希(默认admin123)'),
('token_salt',          'CXPAY_TOKEN_SALT_CHANGE_ME_IN_PRODUCTION',                          'Token HMAC签名盐值')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `title` = VALUES(`title`);

-- 说明：admin_password_hash 值对应 password_hash('admin123', PASSWORD_BCRYPT, ['cost'=>12])
-- 请在生产环境中通过以下 PHP 命令生成新密码哈希：
-- php -r "echo password_hash('你的新密码', PASSWORD_BCRYPT, ['cost'=>12]);"
-- 然后直接 UPDATE cx_config SET value='新哈希' WHERE name='admin_password_hash';
