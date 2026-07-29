-- CXPAY v5 PC 授权账单源升级脚本（执行前请完整备份数据库）
SET NAMES utf8mb4;

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

SELECT 'CXPAY patch_v5 applied successfully' AS result;
