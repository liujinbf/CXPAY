-- CXPAY v8 功能增强补丁（执行前请完整备份数据库）
-- 包含：
--   1. cx_admin 子管理员表（RBAC 多角色权限）
--   2. 沙箱测试通道预置数据
SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
-- 1. cx_admin 子管理员表（RBAC 多角色权限管理）
--
-- 角色说明（role 字段）：
--   root      — 超级管理员（保留，与旧系统 cx_config 中的 admin 账号对应）
--   operator  — 运营人员：可查看商户、订单，可手动补单/关单，不可修改系统配置
--   finance   — 财务人员：可查看报表与资金流水，只读，不可写操作
--   support   — 客服人员：可查看订单和商户基本信息，不可修改数据
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cx_admin` (
    `id`            int(11)      NOT NULL AUTO_INCREMENT COMMENT '主键',
    `username`      varchar(64)  NOT NULL COMMENT '登录账号',
    `password_hash` varchar(255) NOT NULL COMMENT 'Bcrypt 密码哈希',
    `role`          varchar(32)  NOT NULL DEFAULT 'operator' COMMENT '角色：root/operator/finance/support',
    `display_name`  varchar(100) NOT NULL DEFAULT '' COMMENT '显示名称',
    `status`        tinyint(1)   NOT NULL DEFAULT '1' COMMENT '状态：1启用 0禁用',
    `last_login_at` int(11)      NOT NULL DEFAULT '0' COMMENT '最后登录时间',
    `last_login_ip` varchar(64)  NOT NULL DEFAULT '' COMMENT '最后登录IP',
    `create_time`   int(11)      NOT NULL DEFAULT '0' COMMENT '创建时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='子管理员账号表（RBAC 多角色权限）';

-- ─────────────────────────────────────────────────────────────────────────
-- 2. cx_admin_permission 角色权限白名单表
--    定义各角色允许访问的 API 路径前缀（前缀匹配）。
--    root 角色不受此表限制（全权限）。
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cx_admin_permission` (
    `id`          int(11)      NOT NULL AUTO_INCREMENT,
    `role`        varchar(32)  NOT NULL COMMENT '角色名',
    `path_prefix` varchar(255) NOT NULL COMMENT 'API 路径前缀（前缀匹配）',
    `method`      varchar(16)  NOT NULL DEFAULT '*' COMMENT 'HTTP 方法，* 表示全部',
    PRIMARY KEY (`id`),
    KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色-接口权限白名单';

-- 预置权限数据
INSERT IGNORE INTO `cx_admin_permission` (`role`, `path_prefix`, `method`) VALUES
  -- operator（运营）：查看全部、可补单/关单，但不能修改系统与通道配置
  ('operator', '/api/admin/dashboard',           '*'),
  ('operator', '/api/admin/merchant/list',        'GET'),
  ('operator', '/api/admin/order/list',           'GET'),
  ('operator', '/api/admin/order/close',          'POST'),
  ('operator', '/api/admin/order/force_notify',   'POST'),
  ('operator', '/api/admin/order/manual_pay',     'POST'),
  ('operator', '/api/admin/callbill/review_list', 'GET'),
  ('operator', '/api/admin/callbill/review_match','POST'),
  ('operator', '/api/admin/callbill/review_ignore','POST'),
  ('operator', '/api/admin/report/',             'GET'),
  -- finance（财务）：只读报表与流水
  ('finance',  '/api/admin/dashboard',           'GET'),
  ('finance',  '/api/admin/report/',             'GET'),
  ('finance',  '/api/admin/merchant/list',       'GET'),
  -- support（客服）：只读订单与商户
  ('support',  '/api/admin/dashboard',           'GET'),
  ('support',  '/api/admin/merchant/list',       'GET'),
  ('support',  '/api/admin/order/list',          'GET');

-- ─────────────────────────────────────────────────────────────────────────
-- 3. 沙箱测试通道预置数据
--    由于沙箱不在 wxpay/alipay/qqpay 三个 pay_category 范围，
--    不能通过管理员 UI 新建，必须由此补丁预置。
-- ─────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `cx_pay_channel`
    (`merchant_id`, `pay_category`, `title`, `c_type`, `remark`, `config`,
     `weight`, `single_min`, `single_max`, `day_max`, `status`, `online_status`,
     `today_money`, `today_count`, `total_money`, `last_heartbeat_time`)
VALUES
    (0, 'sandbox', '沙箱测试通道', 'sandbox_test', '免真实扣款的开发测试专用通道',
     '{"sandbox_secret":"changeme_please_update_via_admin","auto_pay_delay":"0"}',
     100, 0.01, 9999.00, 0, 0, 1,
     0.00, 0, 0.00, 0);

-- 初始沙箱通道默认 status=0（禁用），需由管理员手动启用并更新 sandbox_secret

SELECT 'CXPAY patch_v8 applied successfully' AS result;
