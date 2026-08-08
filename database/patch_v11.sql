-- CXPAY patch_v11 - 记录手续费预留的现金与套餐抵扣金来源
-- 兼容 MySQL 5.7，不依赖 ADD COLUMN IF NOT EXISTS。

SET NAMES utf8mb4;

DELIMITER //
CREATE PROCEDURE `cxpay_patch_v11`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'cx_order' AND column_name = 'fee_reserved_cash'
    ) THEN
        ALTER TABLE `cx_order`
            ADD COLUMN `fee_reserved_cash` decimal(10,2) NOT NULL DEFAULT '0.00'
            COMMENT '从现金余额预留的手续费' AFTER `fee_amount`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'cx_order' AND column_name = 'fee_reserved_discount'
    ) THEN
        ALTER TABLE `cx_order`
            ADD COLUMN `fee_reserved_discount` decimal(10,2) NOT NULL DEFAULT '0.00'
            COMMENT '从套餐优惠额度预留的手续费' AFTER `fee_reserved_cash`;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'cx_order' AND column_name = 'fee_reservation_status'
    ) THEN
        ALTER TABLE `cx_order`
            ADD COLUMN `fee_reservation_status` varchar(16) NOT NULL DEFAULT 'legacy'
            COMMENT '手续费预留状态(legacy/reserved/consumed/released)' AFTER `fee_reserved_discount`;
    END IF;
END//
DELIMITER ;

CALL `cxpay_patch_v11`();
DROP PROCEDURE `cxpay_patch_v11`;

SELECT 'CXPAY patch_v11 applied successfully' AS result;
