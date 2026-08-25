-- patch_v15_rbac_and_agent_guard.sql
-- 完善 RBAC 角色权限节点与代理商资质管控

-- 1. 确保 cx_admin_permission 存在且包含标准角色权限
CREATE TABLE IF NOT EXISTS `cx_admin_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(32) NOT NULL COMMENT '角色标识: operator, finance, support',
  `path_prefix` varchar(255) NOT NULL COMMENT '允许访问的 API 路由前缀',
  `method` varchar(16) NOT NULL DEFAULT '*' COMMENT 'HTTP方法: GET, POST, 或 *',
  PRIMARY KEY (`id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员角色权限白名单表';

-- 清理并初始化标准角色权限白名单（root 角色默认全权限无需入表）
DELETE FROM `cx_admin_permission` WHERE `role` IN ('operator', 'finance', 'support');

-- operator (运营管理员): 订单查询、商户管理、通道配置、监控查询，无权管理子账号与核心安全配置
INSERT INTO `cx_admin_permission` (`role`, `path_prefix`, `method`) VALUES
('operator', '/api/admin/order/', '*'),
('operator', '/api/admin/merchant/', '*'),
('operator', '/api/admin/channel/', '*'),
('operator', '/api/admin/channel_config/', '*'),
('operator', '/api/admin/poll_group/', '*'),
('operator', '/api/admin/callbill/', '*'),
('operator', '/api/admin/report/', '*'),
('operator', '/api/admin/dashboard/', '*'),
('operator', '/api/admin/cloud_monitor/', '*'),
('operator', '/api/admin/plugin/', 'GET');

-- finance (财务管理员): 订单核销、充值记录、资金流水、财务报表、补单
INSERT INTO `cx_admin_permission` (`role`, `path_prefix`, `method`) VALUES
('finance', '/api/admin/order/', '*'),
('finance', '/api/admin/merchant/', 'GET'),
('finance', '/api/admin/report/', '*'),
('finance', '/api/admin/callbill/', '*'),
('finance', '/api/admin/dashboard/', 'GET');

-- support (客服支持人员): 仅订单查询与基础商户查看，无法修改敏感数据
INSERT INTO `cx_admin_permission` (`role`, `path_prefix`, `method`) VALUES
('support', '/api/admin/order/list', 'GET'),
('support', '/api/admin/order/detail', 'GET'),
('support', '/api/admin/merchant/list', 'GET'),
('support', '/api/admin/dashboard/stats', 'GET');

-- 2. 确保配置表中存在代理商资质标识与默认状态
INSERT IGNORE INTO `cx_config` (`name`, `value`, `title`) VALUES
('instance_license_type', 'STANDARD', '当前实例授权等级: STANDARD / AGENT'),
('instance_is_agent', '0', '当前实例是否具备代理商发码资质: 0否 1是');
