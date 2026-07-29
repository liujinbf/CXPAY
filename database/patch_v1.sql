-- ============================================================
-- CXPAY 缺陷修复补丁 SQL (patch_v1.sql)
-- 说明：对已部署的数据库执行此脚本，补全缺失字段
-- 新安装请直接使用 install.sql（已含这些字段）
-- ============================================================

-- FIX-6: cx_order 表补增 notify_status 字段
-- (MerchantNotifyService 中使用此字段记录回调是否成功)
ALTER TABLE `cx_order`
    ADD COLUMN `notify_status` tinyint(1) DEFAULT '0' COMMENT '0未通知 1已成功通知 2通知失败重试中'
    AFTER `pay_time`;

-- FIX-7: cx_pay_channel 表补增 last_heartbeat_time 字段
-- (ChannelMonitorService 通道心跳监控依赖此字段判断是否自动下线)
ALTER TABLE `cx_pay_channel`
    ADD COLUMN `last_heartbeat_time` int(11) DEFAULT '0' COMMENT '最后一次心跳上报时间戳'
    AFTER `online_status`;

-- 旧版补丁不能写入公共默认密码，也不能覆盖已存在的管理员凭据。
INSERT IGNORE INTO `cx_config` (`name`, `value`, `title`) VALUES
('admin_account',       'admin',                           '管理员账号'),
('admin_password_hash', '',                                '管理员密码Bcrypt哈希（待初始化）'),
('token_salt',          LOWER(HEX(RANDOM_BYTES(32))),      'Token HMAC签名盐值');

-- 执行补丁后，请生成管理员密码哈希并通过受控数据库连接写入：
-- php -r "echo password_hash('你的新密码', PASSWORD_BCRYPT, ['cost'=>12]);"
-- 然后直接 UPDATE cx_config SET value='新哈希' WHERE name='admin_password_hash';
