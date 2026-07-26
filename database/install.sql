-- 补全 CXPAY 缺失数据表与扩展字段

-- 1. 扩充商户/会员表 cx_merchant
ALTER TABLE `cx_merchant` 
ADD COLUMN `pid` varchar(32) DEFAULT '' COMMENT '商户对接PID',
ADD COLUMN `money` decimal(10,2) DEFAULT '0.00' COMMENT '商户账户余额(元)',
ADD COLUMN `packvip_id` int(11) DEFAULT '0' COMMENT '套餐ID',
ADD COLUMN `packvip_time` int(11) DEFAULT '0' COMMENT '套餐到期时间戳',
ADD COLUMN `pay_float_min` decimal(6,2) DEFAULT '0.00' COMMENT '金额浮动最小值',
ADD COLUMN `pay_float_max` decimal(6,2) DEFAULT '0.00' COMMENT '金额浮动最大值',
ADD COLUMN `pay_outtime` int(11) DEFAULT '180' COMMENT '订单超时时间(秒)';

-- 2. 新增 VIP 套餐表 cx_packvip
CREATE TABLE IF NOT EXISTS `cx_packvip` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL COMMENT '套餐名称',
  `rate` decimal(5,2) DEFAULT '2.00' COMMENT '套餐扣率百分比',
  `mini_rate` decimal(5,2) DEFAULT '0.01' COMMENT '最低保底单笔扣费',
  `bind_ctype` text COMMENT '绑定的可用支付类型JSON',
  `weigh` int(11) DEFAULT '0' COMMENT '权重排序',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='VIP套餐费率表';

-- 3. 新增轮询组表 cx_poll_group
CREATE TABLE IF NOT EXISTS `cx_poll_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '轮询组名称',
  `c_type` varchar(50) NOT NULL COMMENT '对应支付类型',
  `status` tinyint(1) DEFAULT '1' COMMENT '1正常 0禁用',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通道轮询组表';

-- 4. 新增轮询组通道关联表 cx_poll_group_channel
CREATE TABLE IF NOT EXISTS `cx_poll_group_channel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL COMMENT '轮询组ID',
  `channel_id` int(11) NOT NULL COMMENT '通道ID',
  `weight` int(11) DEFAULT '50' COMMENT '轮询权重',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮询组通道明细表';

-- 5. 新增商户余额变动明细日志表 cx_user_money_log
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
