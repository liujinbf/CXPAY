-- 1. 添加字段（检测存在则跳过）
SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ba_user'
      AND COLUMN_NAME = 'mapi_return_mode'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `ba_user` ADD COLUMN `mapi_return_mode` varchar(10) NOT NULL DEFAULT ''payurl'' COMMENT ''mapi默认返回:payurl页面/qrcode二维码链接'' AFTER `paypage`',
    'SELECT ''Column mapi_return_mode already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. 清理历史残留账单（幂等操作，可重复执行）
UPDATE `ba_pay_callbill`
SET `status` = 2
WHERE `status` = 0
  AND (`create_time` IS NULL OR `create_time` < UNIX_TIMESTAMP() - 60);

-- 3. 插入菜单（检测存在则跳过）
INSERT IGNORE INTO `ba_admin_rule`
(`pid`,`type`,`title`,`name`,`path`,`icon`,`menu_type`,`url`,`component`,`keepalive`,`extend`,`remark`,`weigh`,`status`,`update_time`,`create_time`)
SELECT 0,'menu','系统监控','cloud/monitor','cloud/monitor','fa fa-heartbeat','tab','','/src/views/backend/cloud/monitor/index.vue',1,'none','',85,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `ba_admin_rule` WHERE `name` = 'cloud/monitor' AND `type` = 'menu'
);

-- 4. 插入子菜单（检测父菜单存在且子菜单不存在才执行）
SET @menu_pid = (SELECT `id` FROM `ba_admin_rule` WHERE `name` = 'cloud/monitor' AND `type` = 'menu' LIMIT 1);

INSERT IGNORE INTO `ba_admin_rule`
(`pid`,`type`,`title`,`name`,`path`,`icon`,`menu_type`,`url`,`component`,`keepalive`,`extend`,`remark`,`weigh`,`status`,`update_time`,`create_time`)
SELECT @menu_pid,'button','查看','cloud/monitor/index','','fa fa-circle-o',NULL,'','',0,'none','',0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM DUAL
WHERE @menu_pid IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `ba_admin_rule` WHERE `name` = 'cloud/monitor/index' AND `type` = 'button'
  );

INSERT IGNORE INTO `ba_admin_rule`
(`pid`,`type`,`title`,`name`,`path`,`icon`,`menu_type`,`url`,`component`,`keepalive`,`extend`,`remark`,`weigh`,`status`,`update_time`,`create_time`)
SELECT @menu_pid,'button','日志','cloud/monitor/logs','','fa fa-circle-o',NULL,'','',0,'none','',0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM DUAL
WHERE @menu_pid IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `ba_admin_rule` WHERE `name` = 'cloud/monitor/logs' AND `type` = 'button'
  );
