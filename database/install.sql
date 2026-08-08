-- CXPAY 商业级高并发聚合支付平台 完整初始化 SQL
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. 商户/会员表 cx_merchant
CREATE TABLE IF NOT EXISTS `cx_merchant` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '新商户' COMMENT '商户名称',
  `pid` varchar(32) NOT NULL DEFAULT '' COMMENT '商户对接PID',
  `key` varchar(64) NOT NULL DEFAULT '' COMMENT '商户签名MD5密钥',
  `password_hash` varchar(255) NOT NULL DEFAULT '' COMMENT '商户后台登录密码哈希',
  `money` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '账户余额(元)',
  `plan_fee_discount_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '套餐费用抵扣手续费剩余额度',
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
  `business_type` varchar(20) NOT NULL DEFAULT 'payment' COMMENT '业务类型(payment/recharge)',
  `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '订单手续费金额',
  `fee_reserved_cash` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '从现金余额预留的手续费',
  `fee_reserved_discount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '从套餐优惠额度预留的手续费',
  `fee_reservation_status` varchar(16) NOT NULL DEFAULT 'legacy' COMMENT '手续费预留状态(legacy/reserved/consumed/released)',
  `fee_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '手续费状态(0无预占/旧单 1已预占 2已核销 3已释放)',
  `amount` decimal(10,2) NOT NULL COMMENT '商户发起原始金额(元)',
  `price` decimal(10,2) NOT NULL COMMENT '浮动去重实际支付金额(元)',
  `subject` varchar(255) DEFAULT '网络支付' COMMENT '商品标题说明',
  `notify_url` text COMMENT '异步回调推送URL',
  `return_url` text COMMENT '支付成功同步跳转URL',
  `pay_url` text COMMENT '支付驱动生成的支付地址或二维码内容',
  `pay_mode` varchar(16) NOT NULL DEFAULT 'qrcode' COMMENT '出码类型(url/qrcode)',
  `pay_init_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '出码初始化状态(0未开始 1处理中 2完成 3失败)',
  `pay_init_time` int(11) NOT NULL DEFAULT '0' COMMENT '最近一次出码初始化时间',
  `channel_trade_no` varchar(128) DEFAULT '' COMMENT '上游或助手上报单号',
  `status` tinyint(1) DEFAULT '0' COMMENT '0待支付 1已支付 2已超时 3已退款',
  `notify_status` tinyint(1) DEFAULT '0' COMMENT '0未通知 1成功 2重试中 3最终失败',
  `create_time` int(11) DEFAULT '0' COMMENT '下单时间',
  `expire_time` int(11) DEFAULT '0' COMMENT '失效时间',
  `pay_time` int(11) DEFAULT '0' COMMENT '支付完成时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade_no` (`trade_no`),
  UNIQUE KEY `uk_merchant_out_trade_no` (`merchant_id`, `out_trade_no`),
  KEY `idx_merchant_id` (`merchant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_channel_price_status` (`channel_id`, `price`, `status`),
  KEY `idx_expire_status` (`expire_time`, `status`)
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
  `last_heartbeat_time` int(11) DEFAULT '0' COMMENT '最后一次心跳上报时间戳',
  `status` tinyint(1) DEFAULT '1' COMMENT '1启用 0关闭',
  PRIMARY KEY (`id`),
  KEY `idx_merchant_cat` (`merchant_id`, `pay_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付通道与商户个人收款码配置表';

-- 3.1 已移除支付通道非敏感元数据归档表
CREATE TABLE IF NOT EXISTS `cx_pay_channel_archive` (
  `archive_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `original_channel_id` int(11) NOT NULL COMMENT '原活动通道ID',
  `merchant_id` int(11) DEFAULT NULL COMMENT '原所属商户ID',
  `pay_category` varchar(32) DEFAULT NULL COMMENT '原支付分类',
  `title` varchar(100) DEFAULT NULL COMMENT '原通道显示名称',
  `c_type` varchar(64) NOT NULL COMMENT '原驱动标识',
  `remark` varchar(255) DEFAULT NULL COMMENT '原通道备注',
  `weight` int(11) DEFAULT NULL COMMENT '原轮询权重',
  `single_min` decimal(12,2) DEFAULT NULL COMMENT '原单笔最小限制',
  `single_max` decimal(12,2) DEFAULT NULL COMMENT '原单笔最大限制',
  `day_max` decimal(12,2) DEFAULT NULL COMMENT '原单日额度限制',
  `today_money` decimal(12,2) DEFAULT NULL COMMENT '归档时今日金额',
  `today_count` int(11) DEFAULT NULL COMMENT '归档时今日笔数',
  `total_money` decimal(14,2) DEFAULT NULL COMMENT '归档时累计金额',
  `online_status` tinyint(1) DEFAULT NULL COMMENT '归档时在线状态',
  `status` tinyint(1) DEFAULT NULL COMMENT '归档时启用状态',
  `archive_reason` varchar(80) NOT NULL COMMENT '归档原因',
  `archived_at` bigint(20) unsigned NOT NULL COMMENT '归档时间戳',
  PRIMARY KEY (`archive_id`),
  UNIQUE KEY `uk_original_channel_id` (`original_channel_id`),
  KEY `idx_archive_ctype` (`c_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='已移除支付通道非敏感元数据归档表';

-- 4. 挂机账单上报表 cx_callbill
CREATE TABLE IF NOT EXISTS `cx_callbill` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(64) NOT NULL COMMENT '挂机设备/APP唯一标识',
  `source_bill_id` varchar(128) NOT NULL COMMENT '客户端生成的稳定来源账单唯一标识',
  `app_name` varchar(50) NOT NULL COMMENT '应用类型(alipay_asst/wxpay_asst)',
  `money` decimal(10,2) NOT NULL COMMENT '实际到账金额',
  `remark` varchar(255) DEFAULT '' COMMENT '账单备注/订单号',
  `channel_id` int(11) NOT NULL DEFAULT '0' COMMENT '来源支付通道ID',
  `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '匹配到的平台流水号',
  `order_id` int(11) NOT NULL DEFAULT '0' COMMENT '匹配到的订单ID',
  `occurred_at` int(11) NOT NULL DEFAULT '0' COMMENT '账单在收款端发生的时间戳',
  `raw_hash` char(64) NOT NULL DEFAULT '' COMMENT '原始通知内容SHA256摘要',
  `client_version` varchar(32) NOT NULL DEFAULT '' COMMENT '助手客户端版本',
  `review_note` varchar(255) NOT NULL DEFAULT '' COMMENT '人工复核备注',
  `status` tinyint(1) DEFAULT '0' COMMENT '0待匹配 1已销账 2无匹配 3待复核 4处理中 5已忽略',
  `create_time` int(11) DEFAULT '0' COMMENT '上报时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_channel_source_bill` (`channel_id`, `source_bill_id`),
  KEY `idx_channel_raw_time` (`channel_id`, `raw_hash`, `occurred_at`),
  KEY `idx_money_status` (`money`, `status`),
  KEY `idx_channel_money_status` (`channel_id`, `money`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='挂机监控助手流水表';

-- 4.1 授权账单源暂存表（采集端写入、PC按游标拉取）
CREATE TABLE IF NOT EXISTS `cx_bill_source_event` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(11) NOT NULL COMMENT '绑定的支付通道ID',
  `source_bill_id` varchar(128) NOT NULL COMMENT '账单源稳定唯一标识',
  `pay_type` varchar(16) NOT NULL COMMENT '支付类型(wxpay/alipay/qqpay)',
  `money` decimal(10,2) NOT NULL COMMENT '真实到账金额',
  `occurred_at` int(11) NOT NULL COMMENT '账单实际发生时间',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '到账备注',
  `collector_id` varchar(64) NOT NULL COMMENT '授权采集端ID',
  `create_time` int(11) NOT NULL COMMENT '服务端接收时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bill_source_channel_bill` (`channel_id`, `source_bill_id`),
  KEY `idx_bill_source_channel_cursor` (`channel_id`, `id`),
  KEY `idx_bill_source_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PC监控端授权账单源队列';

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

-- 插入一个禁用的初始化商户占位记录，密钥由数据库随机生成，不能用于登录或下单。
INSERT INTO `cx_merchant` (`id`, `name`, `pid`, `key`, `password_hash`, `money`, `rate`, `status`, `create_time`)
VALUES (1000, '初始化占位商户（已禁用）', '1000', SHA2(CONCAT(UUID(), RAND()), 256), '', 0.00, 0.0150, 0, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 插入默认系统配置
INSERT INTO `cx_config` (`name`, `value`, `title`) VALUES 
('active_home_template', 'default',                                                              '当前生效的主页模版'),
('site_name',            'CXPAY 聚合支付网关',                                               '站点名称'),
('register_grant_balance', '10.00',                                                           '新商户注册赠送体验服务费余额(元)'),
('system_recharge_pid',    '1000',                                                            '平台统一收单与充值系统商户PID'),
('admin_account',        'admin',                                                              '管理员账号'),
('admin_password_hash',  '',                                                                  '管理员密码Bcrypt哈希(由安装器写入)'),
('token_salt',           '',                                                                  'Token HMAC签名盐值(由安装器写入)')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 插入禁用的通道配置示例；管理员必须填写真实配置并主动启用。
INSERT INTO `cx_pay_channel` (`id`, `pay_category`, `title`, `c_type`, `config`, `weight`, `online_status`, `status`) VALUES
(3, 'qqpay', 'QQ 钱包 App 助手（待配置）', 'qqpay_app_asst', '{}', 50, 0, 0)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- 12. 套餐管理表 cx_plan
CREATE TABLE IF NOT EXISTS `cx_plan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '套餐名称',
  `days` int(11) DEFAULT '30' COMMENT '有效天数(0为永久)',
  `rate` decimal(5,2) DEFAULT '2.50' COMMENT '扣除费率百分比',
  `min_rate` decimal(5,2) DEFAULT '0.00' COMMENT '最低费率百分比',
  `channel_quota` int(11) DEFAULT '0' COMMENT '通道配额(0不限)',
  `allowed_channels` varchar(255) DEFAULT '' COMMENT '绑定支持通道',
  `price` decimal(10,2) DEFAULT '0.00' COMMENT '套餐价格(0为试用)',
  `limit_count` int(11) DEFAULT '0' COMMENT '限购次数(0不限)',
  `memo` varchar(255) DEFAULT '' COMMENT '套餐注释/说明',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) DEFAULT '1' COMMENT '1启用 0禁用',
  `create_time` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='套餐设置表';

-- 13. 商户套餐购买日志表 cx_merchant_plan_log
CREATE TABLE IF NOT EXISTS `cx_merchant_plan_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) NOT NULL COMMENT '商户ID',
  `plan_id` int(11) NOT NULL COMMENT '套餐ID',
  `plan_name` varchar(100) NOT NULL COMMENT '套餐名称',
  `price` decimal(10,2) DEFAULT '0.00' COMMENT '实付金额',
  `days` int(11) DEFAULT '0' COMMENT '购买天数',
  `rate` decimal(5,2) DEFAULT '0.00' COMMENT '享受费率',
  `create_time` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_merchant` (`merchant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户套餐购买日志表';

-- 插入默认免费体验/零元试用套餐与VIP标准套餐
INSERT INTO `cx_plan` (`id`, `name`, `days`, `rate`, `min_rate`, `channel_quota`, `allowed_channels`, `price`, `limit_count`, `memo`, `sort_order`, `status`, `create_time`) VALUES
(1, '0元免费体验套餐', 7, 2.50, 0.00, 3, 'alipay,wxpay', 0.00, 1, '新商户零元免费试用，体验全部聚合出码功能', 0, 1, UNIX_TIMESTAMP()),
(2, 'VIP黄金月卡套餐', 30, 1.80, 0.00, 10, 'alipay,wxpay,qqpay', 99.00, 0, '交易扣率低至 1.8%，多通道轮询与专属告警通知', 1, 1, UNIX_TIMESTAMP()),
(3, 'VIP钻石年卡套餐', 365, 1.20, 0.00, 0, 'alipay,wxpay,qqpay,usdt', 888.00, 0, '尊享最高权重优先级通道调度，无限通道配额与专属人工服务', 2, 1, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

SET FOREIGN_KEY_CHECKS = 1;
