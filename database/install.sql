-- CXPAY 商业级高并发聚合支付平台 完整初始化 SQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. 商户/会员表 cx_merchant
CREATE TABLE IF NOT EXISTS `cx_merchant` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '新商户' COMMENT '商户名称',
  `pid` varchar(32) NOT NULL DEFAULT '' COMMENT '商户对接PID',
  `key` varchar(64) NOT NULL DEFAULT '' COMMENT '商户签名MD5密钥',
  `money` decimal(10,2) DEFAULT '100.00' COMMENT '账户余额(元)',
  `rate` decimal(5,4) DEFAULT '0.0200' COMMENT '交易手续费率(例如0.02表示2%)',
  `packvip_id` int(11) DEFAULT '0' COMMENT 'VIP套餐ID',
  `packvip_time` int(11) DEFAULT '0' COMMENT 'VIP到期时间戳',
  `pay_float_min` decimal(6,2) DEFAULT '0.00' COMMENT '金额微浮动下限',
  `pay_float_max` decimal(6,2) DEFAULT '0.09' COMMENT '金额微浮动上限',
  `pay_outtime` int(11) DEFAULT '180' COMMENT '订单超时时间(秒)',
  `ip_white` text COMMENT 'API请求IP白名单',
  `status` tinyint(1) DEFAULT '1' COMMENT '1正常 0停用',
  `create_time` int(11) DEFAULT '0' COMMENT '注册时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pid` (`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COMMENT='商户表';

-- 2. 交易订单表 cx_order
CREATE TABLE IF NOT EXISTS `cx_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) NOT NULL COMMENT '商户ID',
  `out_trade_no` varchar(64) NOT NULL COMMENT '商户系统内部订单号',
  `trade_no` varchar(64) NOT NULL COMMENT 'CXPAY平台流水号',
  `channel_id` int(11) DEFAULT '0' COMMENT '匹配通道ID',
  `pay_type` varchar(32) NOT NULL DEFAULT 'alipay' COMMENT '支付通道类型(alipay/wxpay/qqpay)',
  `amount` decimal(10,2) NOT NULL COMMENT '商户发起原始金额(元)',
  `price` decimal(10,2) NOT NULL COMMENT '浮动去重实际支付金额(元)',
  `subject` varchar(255) DEFAULT '网络支付' COMMENT '商品标题说明',
  `notify_url` text COMMENT '异步回调推送URL',
  `return_url` text COMMENT '支付成功同步跳转URL',
  `channel_trade_no` varchar(128) DEFAULT '' COMMENT '上游或助手上报单号',
  `status` tinyint(1) DEFAULT '0' COMMENT '0待支付 1已支付 2已超时 3已退款',
  `create_time` int(11) DEFAULT '0' COMMENT '下单时间',
  `expire_time` int(11) DEFAULT '0' COMMENT '失效时间',
  `pay_time` int(11) DEFAULT '0' COMMENT '支付完成时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`),
  KEY `idx_out_trade_no` (`out_trade_no`),
  KEY `idx_merchant_id` (`merchant_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='交易订单表';

-- 3. 支付通道表 cx_pay_channel
CREATE TABLE IF NOT EXISTS `cx_pay_channel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) DEFAULT '0' COMMENT '所属商户ID(0为全平台通用)',
  `pay_category` varchar(32) NOT NULL DEFAULT 'wxpay' COMMENT '支付分类(wxpay/alipay/qqpay)',
  `title` varchar(100) NOT NULL COMMENT '通道显示名称',
  `c_type` varchar(50) NOT NULL COMMENT '驱动标识类型',
  `remark` varchar(255) DEFAULT '' COMMENT '商户备注说明',
  `config` text COMMENT 'Authcode加密存储的配置JSON',
  `today_money` decimal(10,2) DEFAULT '0.00' COMMENT '今日收款金额(元)',
  `today_count` int(11) DEFAULT '0' COMMENT '今日收款笔数',
  `total_money` decimal(10,2) DEFAULT '0.00' COMMENT '累计收款金额(元)',
  `weight` int(11) DEFAULT '50' COMMENT '轮询调度权重',
  `single_min` decimal(10,2) DEFAULT '0.00' COMMENT '单笔最小限制',
  `single_max` decimal(10,2) DEFAULT '0.00' COMMENT '单笔最大限制',
  `day_max` decimal(10,2) DEFAULT '0.00' COMMENT '单日额度限制',
  `online_status` tinyint(1) DEFAULT '1' COMMENT '1在线 0离线',
  `status` tinyint(1) DEFAULT '1' COMMENT '1启用 0关闭',
  PRIMARY KEY (`id`),
  KEY `idx_merchant_cat` (`merchant_id`, `pay_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付通道与商户个人收款码配置表';

-- 4. 挂机账单上报表 cx_callbill
CREATE TABLE IF NOT EXISTS `cx_callbill` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(64) NOT NULL COMMENT '挂机设备/APP唯一标识',
  `app_name` varchar(50) NOT NULL COMMENT '应用类型(alipay_asst/wxpay_asst)',
  `money` decimal(10,2) NOT NULL COMMENT '实际到账金额',
  `remark` varchar(255) DEFAULT '' COMMENT '账单备注/订单号',
  `status` tinyint(1) DEFAULT '0' COMMENT '0待匹配 1已自动销账',
  `create_time` int(11) DEFAULT '0' COMMENT '上报时间',
  PRIMARY KEY (`id`),
  KEY `idx_money_status` (`money`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='挂机监控助手流水表';

-- 5. 系统全局配置表 cx_config
CREATE TABLE IF NOT EXISTS `cx_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '配置键名',
  `value` text COMMENT '配置键值',
  `title` varchar(100) DEFAULT '' COMMENT '配置描述',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统全局配置表';

-- 6. 授权站点与模块订阅表 cx_license
CREATE TABLE IF NOT EXISTS `cx_license` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) NOT NULL COMMENT '授权绑定域名',
  `auth_key` varchar(64) NOT NULL COMMENT '授权密钥',
  `agent_id` int(11) DEFAULT '0' COMMENT '代理商ID',
  `modules` text COMMENT '已订阅模块JSON',
  `status` tinyint(1) DEFAULT '1' COMMENT '1有效 0冻结',
  `create_time` int(11) DEFAULT '0',
  `update_time` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权中心数据表';

-- 7. 授权中心模块订阅订单表 cx_license_order
CREATE TABLE IF NOT EXISTS `cx_license_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trade_no` varchar(64) NOT NULL COMMENT '授权订单流水',
  `domain` varchar(255) NOT NULL COMMENT '目标域名',
  `module_key` varchar(50) NOT NULL COMMENT '模块标识',
  `pkg_type` varchar(32) NOT NULL COMMENT '套餐类型',
  `amount` decimal(10,2) NOT NULL COMMENT '价格',
  `pay_type` varchar(32) NOT NULL COMMENT '支付方式',
  `status` tinyint(1) DEFAULT '0' COMMENT '0待支付 1成功',
  `create_time` int(11) DEFAULT '0',
  `pay_time` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权模块订阅订单';

-- 8. VIP 套餐表 cx_packvip
CREATE TABLE IF NOT EXISTS `cx_packvip` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL COMMENT '套餐名称',
  `rate` decimal(5,2) DEFAULT '2.00' COMMENT '套餐扣率百分比',
  `mini_rate` decimal(5,2) DEFAULT '0.01' COMMENT '最低保底单笔扣费',
  `bind_ctype` text COMMENT '绑定的可用支付类型JSON',
  `weigh` int(11) DEFAULT '0' COMMENT '权重排序',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='VIP套餐费率表';

-- 9. 轮询组表 cx_poll_group
CREATE TABLE IF NOT EXISTS `cx_poll_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '轮询组名称',
  `c_type` varchar(50) NOT NULL COMMENT '对应支付类型',
  `status` tinyint(1) DEFAULT '1' COMMENT '1正常 0禁用',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通道轮询组表';

-- 10. 轮询组通道关联表 cx_poll_group_channel
CREATE TABLE IF NOT EXISTS `cx_poll_group_channel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL COMMENT '轮询组ID',
  `channel_id` int(11) NOT NULL COMMENT '通道ID',
  `weight` int(11) DEFAULT '50' COMMENT '轮询权重',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮询组通道明细表';

-- 11. 商户余额变动明细日志表 cx_user_money_log
CREATE TABLE IF NOT EXISTS `cx_user_money_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) NOT NULL COMMENT '商户ID',
  `money` decimal(10,2) NOT NULL COMMENT '变动金额',
  `before` decimal(10,2) NOT NULL COMMENT '变动前余额',
  `after` decimal(10,2) NOT NULL COMMENT '变动后余额',
  `memo` varchar(255) DEFAULT '' COMMENT '备注说明',
  `create_time` int(11) DEFAULT '0' COMMENT '记录时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户余额变动明细日志表';

-- 插入默认体验商户账号 (PID: 1000, KEY: 1234567890abcdef1234567890abcdef)
INSERT INTO `cx_merchant` (`id`, `name`, `pid`, `key`, `money`, `rate`, `status`, `create_time`) 
VALUES (1000, '极客演示体验商户', '1000', '1234567890abcdef1234567890abcdef', 500.00, 0.0150, 1, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 插入默认系统配置
INSERT INTO `cx_config` (`name`, `value`, `title`) VALUES 
('active_home_template', 'default', '当前生效的主页模版'),
('site_name', 'CXPAY 商业级聚合支付平台', '站点名称'),
('admin_password', 'admin123', '管理员初始密码')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 插入默认测试支付通道
INSERT INTO `cx_pay_channel` (`id`, `title`, `c_type`, `config`, `weight`, `status`) VALUES 
(1, '支付宝官方原生扫码 (Demo)', 'alipay_official', '{}', 100, 1),
(2, '微信小账本云端免挂 (Demo)', 'wxpay_protocol_cloud', '{}', 80, 1),
(3, 'QQ钱包 APP 助手挂机', 'qqpay_app_asst', '{}', 50, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

SET FOREIGN_KEY_CHECKS = 1;
