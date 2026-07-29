-- CXPAY v3 订单一致性升级脚本（执行前请备份数据库）
SET NAMES utf8mb4;

ALTER TABLE `cx_merchant`
    ADD COLUMN `password_hash` varchar(255) NOT NULL DEFAULT ''
        COMMENT '商户后台登录密码哈希' AFTER `key`;

-- 新商户余额默认必须为 0，避免开户时凭空产生可用余额。
ALTER TABLE `cx_merchant`
    MODIFY COLUMN `money` decimal(10,2) NOT NULL DEFAULT '0.00'
        COMMENT '账户余额(元)';

ALTER TABLE `cx_order`
    ADD COLUMN `business_type` varchar(20) NOT NULL DEFAULT 'payment'
        COMMENT '业务类型(payment/recharge)' AFTER `pay_type`;

ALTER TABLE `cx_order`
    ADD COLUMN `fee_amount` decimal(10,2) NOT NULL DEFAULT '0.00'
        COMMENT '订单手续费金额' AFTER `business_type`,
    ADD COLUMN `fee_status` tinyint(1) NOT NULL DEFAULT '0'
        COMMENT '手续费状态(0无预占/旧单 1已预占 2已核销 3已释放)' AFTER `fee_amount`;

ALTER TABLE `cx_order`
    ADD COLUMN `pay_url` text COMMENT '支付驱动生成的支付地址或二维码内容' AFTER `return_url`,
    ADD COLUMN `pay_mode` varchar(16) NOT NULL DEFAULT 'qrcode'
        COMMENT '出码类型(url/qrcode)' AFTER `pay_url`,
    ADD COLUMN `pay_init_status` tinyint(1) NOT NULL DEFAULT '0'
        COMMENT '出码初始化状态(0未开始 1处理中 2完成 3失败)' AFTER `pay_mode`,
    ADD COLUMN `pay_init_time` int(11) NOT NULL DEFAULT '0'
        COMMENT '最近一次出码初始化时间' AFTER `pay_init_status`;

ALTER TABLE `cx_order`
    ADD UNIQUE INDEX `uk_merchant_out_trade_no` (`merchant_id`, `out_trade_no`);

ALTER TABLE `cx_callbill`
    ADD COLUMN `order_id` int(11) NOT NULL DEFAULT 0
        COMMENT '匹配到的订单ID' AFTER `trade_no`;

SELECT 'CXPAY patch_v3 applied successfully' AS result;
