-- CXPAY patch_v9 - 云端插件商城体系（执行前请完整备份数据库）
-- 包含：
--   1. cx_cloud_plugin     — 官方插件商品目录
--   2. cx_agent_plugin_license — 代理端插件购买授权记录
SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
-- 1. cx_cloud_plugin  官方可售支付插件商品目录
--
-- 每条记录对应一个 .cxpay-plugin 插件包，由官方发布与维护。
-- 代理端在插件商城中看到此表的上架记录，选择购买后写入授权记录表。
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cx_cloud_plugin` (
    `id`            int(11)        NOT NULL AUTO_INCREMENT  COMMENT '主键',
    `plugin_id`     varchar(100)   NOT NULL                 COMMENT '插件唯一标识，如 cxpay.wxpay.clerk',
    `name`          varchar(100)   NOT NULL                 COMMENT '插件显示名称',
    `description`   text                                    COMMENT '插件简介与使用说明',
    `payment_type`  varchar(20)    NOT NULL DEFAULT 'wxpay' COMMENT '支付类型：alipay / wxpay / qqpay',
    `version`       varchar(30)    NOT NULL DEFAULT '1.0.0' COMMENT '当前最新可下载版本',
    `price_month`   decimal(10,2)  NOT NULL DEFAULT '0.00'  COMMENT '月付价格（元），0 = 本期免费',
    `price_quarter` decimal(10,2)  NOT NULL DEFAULT '0.00'  COMMENT '季付价格（元）',
    `price_year`    decimal(10,2)  NOT NULL DEFAULT '0.00'  COMMENT '年付价格（元）',
    `price_forever` decimal(10,2)  NOT NULL DEFAULT '-1.00' COMMENT '买断价格（元），-1 = 不提供买断',
    `status`        tinyint(1)     NOT NULL DEFAULT '1'     COMMENT '1: 上架销售  0: 下架',
    `sort_order`    int(11)        NOT NULL DEFAULT '100'   COMMENT '排序权重，数值越小越靠前',
    `create_time`   int(11)        NOT NULL DEFAULT '0'     COMMENT '创建时间戳',
    `update_time`   int(11)        NOT NULL DEFAULT '0'     COMMENT '最后修改时间戳',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_plugin_id` (`plugin_id`),
    KEY `idx_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='官方可售支付通道插件商品目录';

-- ─────────────────────────────────────────────────────────────────────────
-- 2. cx_agent_plugin_license  代理端插件购买授权记录
--
-- 记录每个代理站点（domain）已购买的支付通道插件及其授权有效期。
-- 当代理端请求下载插件时，由官方云端查此表验证授权后再下发插件包。
--
-- expire_time：
--   -1  = 永久授权（买断）
--    0  = 未授权（防御性，不应存在）
--   >0  = Unix 时间戳，到期时间
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cx_agent_plugin_license` (
    `id`          int(11)       NOT NULL AUTO_INCREMENT  COMMENT '主键',
    `domain`      varchar(255)  NOT NULL                 COMMENT '代理站点域名，如 pay.example.com',
    `plugin_id`   varchar(100)  NOT NULL                 COMMENT '插件标识，如 cxpay.wxpay.clerk',
    `pkg_type`    varchar(20)   NOT NULL DEFAULT 'month' COMMENT '购买套期：month/quarter/year/forever',
    `amount`      decimal(10,2) NOT NULL DEFAULT '0.00'  COMMENT '实付金额（元），0 = 免费领取',
    `expire_time` int(11)       NOT NULL DEFAULT '0'     COMMENT '授权到期时间戳；-1 = 永久',
    `create_time` int(11)       NOT NULL DEFAULT '0'     COMMENT '购买时间戳',
    PRIMARY KEY (`id`),
    -- 每个域名对同一插件只保留一条有效授权（续费时更新此记录）
    UNIQUE KEY `uk_domain_plugin` (`domain`(191), `plugin_id`(100)),
    KEY `idx_domain` (`domain`(191)),
    KEY `idx_plugin_id` (`plugin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='代理端支付插件购买授权记录';

-- ─────────────────────────────────────────────────────────────────────────
-- 3. 预置示例插件数据（官方内置插件商品，供参考）
--    status = 0 表示默认未上架，需官方手动审核启用
-- ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cx_cloud_plugin`
    (`plugin_id`, `name`, `description`, `payment_type`, `version`,
     `price_month`, `price_quarter`, `price_year`, `price_forever`,
     `status`, `sort_order`, `create_time`, `update_time`)
VALUES
    ('cxpay.wxpay.clerk',
     '微信小账本收款插件',
     '通过微信小账本账单监控实现个人码收款，支持挂机助手推送模式。需配合官方微信小账本云端模块使用。',
     'wxpay', '1.0.0',
     29.00, 79.00, 299.00, -1.00,
     0, 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

    ('cxpay.alipay.scan',
     '支付宝扫码收款插件',
     '通过支付宝个人收款码实现扫码收款，基于挂机助手推送账单到账通知。',
     'alipay', '1.0.0',
     29.00, 79.00, 299.00, -1.00,
     0, 20, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),

    ('cxpay.wxpay.receipt',
     '微信收款小账本（收据模式）插件',
     '利用微信收款小账本的收据回执模式，无需挂机，实时到账通知。',
     'wxpay', '1.0.0',
     49.00, 129.00, 499.00, -1.00,
     0, 30, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

SELECT 'CXPAY patch_v9 applied successfully' AS result;
