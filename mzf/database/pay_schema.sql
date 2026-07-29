-- =====================================================================
-- PeakPay → xlpay(BuildAdmin) 业务表结构（M0 设计）
-- 库：xlpay   引擎：InnoDB   字符集：utf8mb4
--
-- 设计原则：
--   1. 商户身份/余额/RBAC 复用 BuildAdmin 原生 ba_user + ba_user_money_log；
--      支付专属字段放 ba_merchant（user_id 关联 ba_user）。
--   2. 资金/对账相关字段语义与旧 peakpay_* 1:1 保留：
--      trade_no / price / money / status 数值含义 / rate。
--   3. 其余按 BuildAdmin 规范：ba_pay_* 前缀、create_time/update_time(bigint 秒)、
--      weigh 排序、utf8mb4。补齐旧系统缺失的高频索引。
--   4. 旧 details → ba_user_money_log；旧 safety(文件自删) 废弃。
--
-- 幂等：仅 DROP/CREATE 本文件定义的 ba_pay_* 与 ba_merchant，不触碰 BA 核心表。
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 商户扩展档案（1:1 关联 ba_user）—— 旧 peakpay_user 的支付专属字段
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_merchant`;
CREATE TABLE `ba_merchant` (
  `id`             int unsigned      NOT NULL AUTO_INCREMENT,
  `user_id`        int unsigned      NOT NULL                COMMENT '关联 ba_user.id',
  `pid`            bigint            NOT NULL                COMMENT '商户PID(对外，兼容旧 user.id，迁移时保留原值)',
  `pay_key`        varchar(64)       NOT NULL DEFAULT ''     COMMENT '商户对接密钥(旧 user.key)',
  `asst_key`       varchar(64)       NOT NULL DEFAULT ''     COMMENT '小助手通讯密钥',
  `packvip_id`     int               NOT NULL DEFAULT 0      COMMENT '套餐ID',
  `packvip_time`   bigint                     DEFAULT NULL   COMMENT '套餐到期时间戳',
  `packvip_quota`  text                       DEFAULT NULL   COMMENT '套餐限购数据(JSON)',
  `channel_quota`  int               NOT NULL DEFAULT 0      COMMENT '挂机通道配额',
  `money_edin`     decimal(10,2)     NOT NULL DEFAULT 0.00   COMMENT '余额预警阈值',
  `status_edin`    tinyint           NOT NULL DEFAULT 0      COMMENT '余额预警开关',
  `wxpusher_uid`   varchar(200)      NOT NULL DEFAULT ''     COMMENT 'WxPusher 推送UID',
  `pay_switch`     text                       DEFAULT NULL   COMMENT '开关配置(JSON，旧 switch)',
  `pay_voice`      varchar(300)      NOT NULL DEFAULT ''     COMMENT '支付页语音播报',
  `pay_notice`     varchar(300)      NOT NULL DEFAULT ''     COMMENT '支付页显示信息',
  `pay_mapi_type`  tinyint           NOT NULL DEFAULT 0      COMMENT 'mapi返回支付链接类型',
  `paypage`        varchar(300)      NOT NULL DEFAULT 'default' COMMENT '支付页模板',
  `pay_outtime`    int               NOT NULL DEFAULT 180    COMMENT '支付超时(秒)',
  `last_city`      varchar(64)       NOT NULL DEFAULT ''     COMMENT '上次地区',
  `create_time`    bigint                     DEFAULT NULL   COMMENT '创建时间',
  `update_time`    bigint                     DEFAULT NULL   COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  UNIQUE KEY `uk_pid` (`pid`),
  UNIQUE KEY `uk_pay_key` (`pay_key`),
  KEY `idx_asst_key` (`asst_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商户扩展档案';

-- ---------------------------------------------------------------------
-- 通道类型/插件定义（旧 peakpay_ctype）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_ctype`;
CREATE TABLE `ba_pay_ctype` (
  `id`          int unsigned  NOT NULL AUTO_INCREMENT,
  `type`        varchar(64)   NOT NULL              COMMENT '通道类型 alipay/wxpay/qqpay',
  `c_type`      varchar(64)   NOT NULL              COMMENT '插件名/驱动标识',
  `name`        varchar(256)  NOT NULL DEFAULT ''   COMMENT '通道昵称',
  `notes`       varchar(512)  NOT NULL DEFAULT ''   COMMENT '备注',
  `status`      tinyint       NOT NULL DEFAULT 1    COMMENT '状态 0停用1启用',
  `weigh`       int           NOT NULL DEFAULT 100  COMMENT '排序',
  `create_time` bigint                 DEFAULT NULL,
  `update_time` bigint                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_c_type` (`c_type`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通道类型定义';

-- ---------------------------------------------------------------------
-- 通道实例（旧 peakpay_channel）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_channel`;
CREATE TABLE `ba_pay_channel` (
  `id`              int unsigned   NOT NULL AUTO_INCREMENT,
  `uid`             int unsigned   NOT NULL              COMMENT '所属商户 ba_user.id',
  `type`            varchar(20)    NOT NULL              COMMENT '通道类型',
  `c_type`          varchar(64)    NOT NULL              COMMENT '通道驱动标识',
  `polling`         tinyint        NOT NULL DEFAULT 0    COMMENT '轮询标记',
  `config`          longtext                DEFAULT NULL COMMENT '通道配置(authcode 加密 JSON)',
  `error_data`      varchar(256)   NOT NULL DEFAULT ''   COMMENT '异常提示',
  `notes`           varchar(188)   NOT NULL DEFAULT ''   COMMENT '备注',
  `status`          tinyint        NOT NULL DEFAULT 0    COMMENT '状态',
  `tt_switch`       varchar(10)    NOT NULL DEFAULT 'true' COMMENT '收款开关',
  `all_money_max`   decimal(10,2)  NOT NULL DEFAULT 0.00 COMMENT '总限额(0不限)',
  `all_order_max`   int            NOT NULL DEFAULT 0    COMMENT '总笔数限制(0不限)',
  `today_money_max` decimal(10,2)  NOT NULL DEFAULT 0.00 COMMENT '今日限额(0不限)',
  `today_order_max` int            NOT NULL DEFAULT 0    COMMENT '今日笔数限制(0不限)',
  `check_time`      bigint         NOT NULL DEFAULT 0    COMMENT '检测账单时间戳',
  `order_time`      bigint         NOT NULL DEFAULT 0    COMMENT '有单检测时间戳',
  `endtime`         bigint                  DEFAULT NULL COMMENT '掉线时间戳',
  `create_time`     bigint                  DEFAULT NULL,
  `update_time`     bigint                  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uid_type_status_polling` (`uid`,`type`,`status`,`polling`),
  KEY `idx_ctype_status` (`c_type`,`status`),
  KEY `idx_status_checktime` (`status`,`check_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通道实例';

-- ---------------------------------------------------------------------
-- 订单（旧 peakpay_order）—— 主键沿用字符串 trade_no
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_order`;
CREATE TABLE `ba_pay_order` (
  `trade_no`       varchar(64)    NOT NULL              COMMENT '系统订单号(SnowFlake)',
  `out_trade_no`   varchar(128)   NOT NULL              COMMENT '商户订单号',
  `pid`            bigint         NOT NULL              COMMENT '商户PID',
  `type`           varchar(20)    NOT NULL              COMMENT '支付方式',
  `channel_id`     int unsigned   NOT NULL DEFAULT 0    COMMENT '通道ID',
  `name`           varchar(188)   NOT NULL DEFAULT ''   COMMENT '商品名称',
  `money`          decimal(10,2)  NOT NULL              COMMENT '订单金额',
  `price`          decimal(10,2)  NOT NULL              COMMENT '实付金额(可能递增)',
  `notify_url`     varchar(288)   NOT NULL DEFAULT ''   COMMENT '异步通知地址',
  `return_url`     varchar(288)   NOT NULL DEFAULT ''   COMMENT '同步通知地址',
  `param`          varchar(300)   NOT NULL DEFAULT ''   COMMENT '业务扩展参数',
  `pay_id`         varchar(45)    NOT NULL DEFAULT ''   COMMENT '支付者IP',
  `status`         tinyint        NOT NULL DEFAULT 0    COMMENT '0未付 1已付 2超时',
  `station_status` tinyint        NOT NULL DEFAULT 0    COMMENT '站内订单标记',
  `qr_url`         longtext                DEFAULT NULL COMMENT '支付二维码链接',
  `qr_url_base64`  longtext                DEFAULT NULL COMMENT '二维码 base64',
  `jump_url`       longtext                DEFAULT NULL COMMENT '支付跳转链接',
  `config`         longtext                DEFAULT NULL COMMENT '配置记录',
  `check_time`     bigint         NOT NULL DEFAULT 0    COMMENT '检测时间戳',
  `pay_time`       bigint                  DEFAULT NULL COMMENT '支付成功时间戳(旧 endtime)',
  `create_time`    bigint                  DEFAULT NULL COMMENT '下单时间戳',
  `update_time`    bigint                  DEFAULT NULL,
  PRIMARY KEY (`trade_no`),
  KEY `idx_out_trade_no` (`out_trade_no`),
  KEY `idx_pid_status_ctime` (`pid`,`status`,`create_time`),
  KEY `idx_channel_price_status` (`channel_id`,`price`,`status`),
  KEY `idx_status_checktime` (`status`,`check_time`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单';

-- ---------------------------------------------------------------------
-- 到账账单（旧 peakpay_callbill）—— 对账匹配核心
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_callbill`;
CREATE TABLE `ba_pay_callbill` (
  `id`          int unsigned   NOT NULL AUTO_INCREMENT,
  `uid`         int unsigned   NOT NULL DEFAULT 0    COMMENT '商户 ba_user.id',
  `channel_id`  int unsigned   NOT NULL DEFAULT 0    COMMENT '通道ID',
  `price`       decimal(10,2)  NOT NULL              COMMENT '收入金额',
  `config`      longtext                DEFAULT NULL COMMENT '配置/原始数据',
  `status`      tinyint        NOT NULL DEFAULT 0    COMMENT '被匹配状态 0未匹配',
  `create_time` bigint                  DEFAULT NULL COMMENT '到账时间戳',
  `update_time` bigint                  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_match` (`uid`,`channel_id`,`price`,`status`),
  KEY `idx_status_ctime` (`status`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='到账账单';

-- ---------------------------------------------------------------------
-- 会员套餐（旧 peakpay_packvip）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_packvip`;
CREATE TABLE `ba_pay_packvip` (
  `id`            int unsigned  NOT NULL AUTO_INCREMENT,
  `name`          varchar(255)  NOT NULL DEFAULT ''   COMMENT '套餐名称',
  `days`          int           NOT NULL DEFAULT 30   COMMENT '套餐天数',
  `rate`          decimal(10,2) NOT NULL DEFAULT 3.00 COMMENT '费率(%)',
  `mini_rate`     decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '最低费率',
  `channel_quota` int           NOT NULL DEFAULT 0    COMMENT '通道配额',
  `bind_ctype`    text                   DEFAULT NULL COMMENT '绑定通道(JSON)',
  `price`         decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '套餐价格',
  `notes`         text                   DEFAULT NULL COMMENT '注释',
  `quota`         int           NOT NULL DEFAULT 0    COMMENT '每用户限购数',
  `status`        tinyint       NOT NULL DEFAULT 1    COMMENT '是否启用',
  `weigh`         int           NOT NULL DEFAULT 100  COMMENT '排序',
  `create_time`   bigint                 DEFAULT NULL,
  `update_time`   bigint                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员套餐';

-- ---------------------------------------------------------------------
-- 微信云端URL / 代理（旧 peakpay_cloudurl / peakpay_cloudproxy）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_cloudurl`;
CREATE TABLE `ba_pay_cloudurl` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `type`        varchar(64)  NOT NULL DEFAULT 'ipad' COMMENT '类型',
  `name`        varchar(256) NOT NULL DEFAULT ''     COMMENT '昵称',
  `url`         varchar(256) NOT NULL DEFAULT ''     COMMENT '云端地址',
  `status`      tinyint      NOT NULL DEFAULT 1      COMMENT '状态',
  `weigh`       int          NOT NULL DEFAULT 100    COMMENT '排序',
  `create_time` bigint                DEFAULT NULL,
  `update_time` bigint                DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信云端URL';

DROP TABLE IF EXISTS `ba_pay_cloudproxy`;
CREATE TABLE `ba_pay_cloudproxy` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `type`           varchar(64)  NOT NULL DEFAULT 'socks5' COMMENT '类型',
  `name`           varchar(256) NOT NULL DEFAULT ''       COMMENT '昵称/地区',
  `proxy_ip`       varchar(256) NOT NULL DEFAULT ''       COMMENT '代理IP',
  `proxy_user`     varchar(256) NOT NULL DEFAULT ''       COMMENT '代理用户名',
  `proxy_password` varchar(256) NOT NULL DEFAULT ''       COMMENT '代理密码',
  `status`         tinyint      NOT NULL DEFAULT 1        COMMENT '状态',
  `weigh`          int          NOT NULL DEFAULT 100      COMMENT '排序',
  `create_time`    bigint                DEFAULT NULL,
  `update_time`    bigint                DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信云端代理';

-- ---------------------------------------------------------------------
-- 支付业务操作日志（旧 peakpay_log；后台/商户操作走 BA 原生 admin_log）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `ba_pay_log`;
CREATE TABLE `ba_pay_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `username`    varchar(64)     NOT NULL DEFAULT ''   COMMENT '关联账号/system',
  `data`        longtext                 DEFAULT NULL COMMENT '内容',
  `ip`          varchar(45)     NOT NULL DEFAULT ''   COMMENT 'IP',
  `create_time` bigint                   DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付业务日志';
