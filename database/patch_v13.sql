-- patch_v13.sql
-- 功能：
--   1. cx_order 表增加 fee_discount_amount 字段，记录订单实际使用套餐抵扣金金额
--   2. 避免订单超时释放时将抵扣金错退为通用现金余额

ALTER TABLE `cx_order`
    ADD COLUMN IF NOT EXISTS `fee_discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00'
        COMMENT '该订单从套餐抵扣金中抵扣的手续费金额'
        AFTER `fee_amount`;
