-- xlpay 安装种子（结构全表 + 框架/配置种子；不含业务/流水数据；敏感值已清空）
-- 生成时间基于当前库；schema/菜单变动后需重新生成
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ba_admin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_admin` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `username` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户名',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '头像',
  `email` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '邮箱',
  `mobile` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机',
  `login_failure` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '登录失败次数',
  `last_login_time` bigint(16) unsigned DEFAULT NULL COMMENT '上次登录时间',
  `last_login_ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '上次登录IP',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码',
  `salt` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码盐（废弃待删）',
  `motto` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '签名',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '状态:enable=启用,disable=禁用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='管理员表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_admin_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_admin_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上级分组',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组名',
  `rules` text COLLATE utf8mb4_unicode_ci COMMENT '权限规则ID',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='管理分组表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_admin_group_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_admin_group_access` (
  `uid` int(11) unsigned NOT NULL COMMENT '管理员ID',
  `group_id` int(11) unsigned NOT NULL COMMENT '分组ID',
  KEY `uid` (`uid`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='管理分组映射表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_admin_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_admin_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '管理员ID',
  `username` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员用户名',
  `url` varchar(1500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作Url',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '日志标题',
  `data` longtext COLLATE utf8mb4_unicode_ci COMMENT '请求数据',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'IP',
  `useragent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='管理员日志表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_admin_rule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_admin_rule` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上级菜单',
  `type` enum('menu_dir','menu','button') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menu' COMMENT '类型:menu_dir=菜单目录,menu=菜单项,button=页面按钮',
  `title` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `path` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '路由路径',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '图标',
  `menu_type` enum('tab','link','iframe') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '菜单类型:tab=选项卡,link=链接,iframe=Iframe',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Url',
  `component` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组件路径',
  `keepalive` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '缓存:0=关闭,1=开启',
  `extend` enum('none','add_rules_only','add_menu_only') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT '扩展属性:none=无,add_rules_only=只添加为路由,add_menu_only=只添加为菜单',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '权重',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='菜单和权限规则表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_area`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_area` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) unsigned DEFAULT NULL COMMENT '父id',
  `shortname` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '简称',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '名称',
  `mergename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '全称',
  `level` tinyint(4) unsigned DEFAULT NULL COMMENT '层级:1=省,2=市,3=区/县',
  `pinyin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '拼音',
  `code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '长途区号',
  `zip` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '邮编',
  `first` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '首字母',
  `lng` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '经度',
  `lat` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '纬度',
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='省份地区表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_attachment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_attachment` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `topic` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '细目',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上传管理员ID',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上传用户ID',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '物理路径',
  `width` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '宽度',
  `height` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '高度',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '原始名称',
  `size` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '大小',
  `mimetype` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'mime类型',
  `quote` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上传(引用)次数',
  `storage` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '存储方式',
  `sha1` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'sha1编码',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  `last_upload_time` bigint(16) unsigned DEFAULT NULL COMMENT '最后上传时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='附件表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_captcha`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_captcha` (
  `key` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证码Key',
  `code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证码(加密后)',
  `captcha` text COLLATE utf8mb4_unicode_ci COMMENT '验证码数据',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  `expire_time` bigint(16) unsigned DEFAULT NULL COMMENT '过期时间',
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='验证码表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_cloud_setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_cloud_setting` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '键',
  `value` text COMMENT '值(可JSON)',
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COMMENT='云端客户端设置/授权缓存';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量名',
  `group` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '分组',
  `title` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量标题',
  `tip` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量描述',
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '变量输入组件类型',
  `value` longtext COLLATE utf8mb4_unicode_ci COMMENT '变量值',
  `content` longtext COLLATE utf8mb4_unicode_ci COMMENT '字典数据',
  `rule` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '验证规则',
  `extend` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '扩展属性',
  `allow_del` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '允许删除:0=否,1=是',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '权重',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='系统配置';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_crud_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_crud_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `table_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表名',
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '注释',
  `table` text COLLATE utf8mb4_unicode_ci COMMENT '数据表数据',
  `fields` text COLLATE utf8mb4_unicode_ci COMMENT '字段数据',
  `sync` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '同步记录',
  `status` enum('delete','success','error','start') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'start' COMMENT '状态:delete=已删除,success=成功,error=失败,start=生成中',
  `connection` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `create_time` bigint(20) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='CRUD记录表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_migrations` (
  `version` bigint(20) NOT NULL,
  `migration_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `breakpoint` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_notify_template`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_notify_template` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL DEFAULT '' COMMENT '模板标识',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '名称',
  `subject` varchar(191) NOT NULL DEFAULT '' COMMENT '标题',
  `content` text COMMENT 'HTML正文(含占位符)',
  `tokens` varchar(255) NOT NULL DEFAULT '' COMMENT '可用占位符提示',
  `email_enable` tinyint(4) NOT NULL DEFAULT '1' COMMENT '邮件渠道开关',
  `wxpush_enable` tinyint(4) NOT NULL DEFAULT '1' COMMENT 'WxPush渠道开关',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态',
  `weigh` int(11) NOT NULL DEFAULT '0',
  `update_time` bigint(20) DEFAULT NULL,
  `create_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COMMENT='通知模板';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_callbill`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_callbill` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商户 ba_user.id',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '通道ID',
  `price` decimal(10,2) NOT NULL COMMENT '收入金额',
  `config` longtext COMMENT '配置/原始数据',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '被匹配状态 0未匹配',
  `create_time` bigint(20) DEFAULT NULL COMMENT '到账时间戳',
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_match` (`uid`,`channel_id`,`price`,`status`),
  KEY `idx_status_ctime` (`status`,`create_time`)
) ENGINE=InnoDB AUTO_INCREMENT=242 DEFAULT CHARSET=utf8mb4 COMMENT='到账账单';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_channel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_channel` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL COMMENT '所属商户 ba_user.id',
  `type` varchar(20) NOT NULL COMMENT '通道类型',
  `c_type` varchar(64) NOT NULL COMMENT '通道驱动标识',
  `polling` tinyint(4) NOT NULL DEFAULT '0' COMMENT '轮询标记',
  `config` longtext COMMENT '通道配置(authcode 加密 JSON)',
  `error_data` varchar(256) NOT NULL DEFAULT '' COMMENT '异常提示',
  `notes` varchar(188) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `tt_switch` varchar(10) NOT NULL DEFAULT 'true' COMMENT '收款开关',
  `all_money_max` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '总限额(0不限)',
  `all_order_max` int(11) NOT NULL DEFAULT '0' COMMENT '总笔数限制(0不限)',
  `today_money_max` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '今日限额(0不限)',
  `today_order_max` int(11) NOT NULL DEFAULT '0' COMMENT '今日笔数限制(0不限)',
  `check_time` bigint(20) NOT NULL DEFAULT '0' COMMENT '检测账单时间戳',
  `order_time` bigint(20) NOT NULL DEFAULT '0' COMMENT '有单检测时间戳',
  `endtime` bigint(20) DEFAULT NULL COMMENT '掉线时间戳',
  `online_time` bigint(20) NOT NULL DEFAULT '0' COMMENT '本次上线时间戳',
  `online_total` bigint(20) NOT NULL DEFAULT '0' COMMENT '累计在线秒',
  `create_time` bigint(20) DEFAULT NULL,
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uid_type_status_polling` (`uid`,`type`,`status`,`polling`),
  KEY `idx_ctype_status` (`c_type`,`status`),
  KEY `idx_status_checktime` (`status`,`check_time`)
) ENGINE=InnoDB AUTO_INCREMENT=669 DEFAULT CHARSET=utf8mb4 COMMENT='通道实例';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_cloudproxy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_cloudproxy` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(64) NOT NULL DEFAULT 'socks5' COMMENT '类型',
  `name` varchar(256) NOT NULL DEFAULT '' COMMENT '昵称/地区',
  `proxy_ip` varchar(256) NOT NULL DEFAULT '' COMMENT '代理IP',
  `proxy_user` varchar(256) NOT NULL DEFAULT '' COMMENT '代理用户名',
  `proxy_password` varchar(256) NOT NULL DEFAULT '' COMMENT '代理密码',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态',
  `weigh` int(11) NOT NULL DEFAULT '100' COMMENT '排序',
  `create_time` bigint(20) DEFAULT NULL,
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信云端代理';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_cloudurl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_cloudurl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(64) NOT NULL DEFAULT 'ipad' COMMENT '类型',
  `name` varchar(256) NOT NULL DEFAULT '' COMMENT '昵称',
  `url` varchar(256) NOT NULL DEFAULT '' COMMENT '云端地址',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态',
  `weigh` int(11) NOT NULL DEFAULT '100' COMMENT '排序',
  `create_time` bigint(20) DEFAULT NULL,
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信云端URL';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_ctype`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_ctype` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(64) NOT NULL COMMENT '通道类型 alipay/wxpay/qqpay',
  `c_type` varchar(64) NOT NULL COMMENT '插件名/驱动标识',
  `name` varchar(256) NOT NULL DEFAULT '' COMMENT '通道昵称',
  `notes` varchar(512) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态 0停用1启用',
  `weigh` int(11) NOT NULL DEFAULT '100' COMMENT '排序',
  `create_time` bigint(20) DEFAULT NULL,
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_c_type` (`c_type`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COMMENT='通道类型定义';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '' COMMENT '关联账号/system',
  `data` longtext COMMENT '内容',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT 'IP',
  `create_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付业务日志';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_order` (
  `trade_no` varchar(64) NOT NULL COMMENT '系统订单号(SnowFlake)',
  `out_trade_no` varchar(128) NOT NULL COMMENT '商户订单号',
  `pid` bigint(20) NOT NULL COMMENT '商户PID',
  `type` varchar(20) NOT NULL COMMENT '支付方式',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '通道ID',
  `name` varchar(188) NOT NULL DEFAULT '' COMMENT '商品名称',
  `money` decimal(10,2) NOT NULL COMMENT '订单金额',
  `price` decimal(10,2) NOT NULL COMMENT '实付金额(可能递增)',
  `notify_url` varchar(288) NOT NULL DEFAULT '' COMMENT '异步通知地址',
  `return_url` varchar(288) NOT NULL DEFAULT '' COMMENT '同步通知地址',
  `param` varchar(300) NOT NULL DEFAULT '' COMMENT '业务扩展参数',
  `pay_id` varchar(45) NOT NULL DEFAULT '' COMMENT '支付者IP',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0未付 1已付 2超时',
  `station_status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '站内订单标记',
  `qr_url` longtext COMMENT '支付二维码链接',
  `qr_url_base64` longtext COMMENT '二维码 base64',
  `jump_url` longtext COMMENT '支付跳转链接',
  `config` longtext COMMENT '配置记录',
  `check_time` bigint(20) NOT NULL DEFAULT '0' COMMENT '检测时间戳',
  `expire_time` int(11) NOT NULL DEFAULT '0' COMMENT '订单到期时间戳',
  `pay_time` bigint(20) DEFAULT NULL COMMENT '支付成功时间戳(旧 endtime)',
  `create_time` bigint(20) DEFAULT NULL COMMENT '下单时间戳',
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`trade_no`),
  KEY `idx_out_trade_no` (`out_trade_no`),
  KEY `idx_pid_status_ctime` (`pid`,`status`,`create_time`),
  KEY `idx_channel_price_status` (`channel_id`,`price`,`status`),
  KEY `idx_status_checktime` (`status`,`check_time`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status_expire` (`status`,`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_packvip`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_packvip` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `days` int(11) NOT NULL DEFAULT '30' COMMENT '套餐天数',
  `rate` decimal(10,2) NOT NULL DEFAULT '3.00' COMMENT '费率(%)',
  `mini_rate` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '最低费率',
  `channel_quota` int(11) NOT NULL DEFAULT '0' COMMENT '通道配额',
  `bind_ctype` text COMMENT '绑定通道(JSON)',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '套餐价格',
  `notes` text COMMENT '注释',
  `quota` int(11) NOT NULL DEFAULT '0' COMMENT '每用户限购数',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `weigh` int(11) NOT NULL DEFAULT '100' COMMENT '排序',
  `create_time` bigint(20) DEFAULT NULL,
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_weigh` (`status`,`weigh`)
) ENGINE=InnoDB AUTO_INCREMENT=543 DEFAULT CHARSET=utf8mb4 COMMENT='会员套餐';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_pollgroup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_pollgroup` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '商户ID',
  `name` varchar(60) NOT NULL DEFAULT '' COMMENT '规则名称',
  `type` varchar(20) NOT NULL DEFAULT '' COMMENT '支付方式 alipay/wxpay/qqpay',
  `mode` varchar(20) NOT NULL DEFAULT 'random' COMMENT '轮询模式 random/weight/priority',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '启用 1/0',
  `notes` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '排序/择优',
  `create_time` bigint(20) DEFAULT NULL,
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uid_type_status` (`uid`,`type`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COMMENT='轮询规则组';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_pay_pollgroup_channel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_pay_pollgroup_channel` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(10) unsigned NOT NULL DEFAULT '0',
  `weight` int(11) NOT NULL DEFAULT '1' COMMENT '权重',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_channel` (`group_id`,`channel_id`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COMMENT='轮询规则-通道关联';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_security_data_recycle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_security_data_recycle` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `controller` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器',
  `controller_as` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器别名',
  `data_table` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '对应数据表',
  `connection` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='回收规则表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_security_data_recycle_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_security_data_recycle_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员',
  `recycle_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '回收规则ID',
  `data` text COLLATE utf8mb4_unicode_ci COMMENT '回收的数据',
  `data_table` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表',
  `connection` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `is_restore` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '是否已还原:0=否,1=是',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作者IP',
  `useragent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='数据回收记录表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_security_sensitive_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_security_sensitive_data` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `controller` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器',
  `controller_as` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '控制器别名',
  `data_table` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '对应数据表',
  `connection` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `data_fields` text COLLATE utf8mb4_unicode_ci COMMENT '敏感数据字段',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='敏感数据规则表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_security_sensitive_data_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_security_sensitive_data_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员',
  `sensitive_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '敏感数据规则ID',
  `data_table` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表',
  `connection` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据库连接配置标识',
  `primary_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '数据表主键',
  `data_field` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '被修改字段',
  `data_comment` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '被修改项',
  `id_value` int(11) NOT NULL DEFAULT '0' COMMENT '被修改项主键值',
  `before` text COLLATE utf8mb4_unicode_ci COMMENT '修改前',
  `after` text COLLATE utf8mb4_unicode_ci COMMENT '修改后',
  `ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作者IP',
  `useragent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `is_rollback` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '是否已回滚:0=否,1=是',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='敏感数据修改记录';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_test_build`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_test_build` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `keyword_rows` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '关键词',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '内容',
  `views` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '浏览量',
  `likes` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '有帮助数',
  `dislikes` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '无帮助数',
  `note_textarea` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '权重',
  `update_time` bigint(20) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(20) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='知识库表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_theme_setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_theme_setting` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '设置键',
  `value` text COMMENT '值(可JSON)',
  `update_time` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COMMENT='主题设置';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_token`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_token` (
  `token` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Token',
  `type` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '类型',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  `expire_time` bigint(16) unsigned DEFAULT NULL COMMENT '过期时间',
  PRIMARY KEY (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='用户Token表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '对接商户号(M+10位)',
  `group_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '分组ID',
  `username` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '用户名',
  `nickname` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '昵称',
  `email` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '邮箱',
  `mobile` varchar(11) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '手机',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '头像',
  `gender` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '性别:0=未知,1=男,2=女',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `money` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '余额',
  `score` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '积分',
  `last_login_time` bigint(16) unsigned DEFAULT NULL COMMENT '上次登录时间',
  `last_login_ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '上次登录IP',
  `login_failure` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '登录失败次数',
  `join_ip` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '加入IP',
  `join_time` bigint(16) unsigned DEFAULT NULL COMMENT '加入时间',
  `motto` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '签名',
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码',
  `salt` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '密码盐（废弃待删）',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '状态:enable=启用,disable=禁用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  `pay_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '商户对接密钥',
  `google_secret` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '谷歌验证密钥',
  `google_enable` tinyint(4) NOT NULL DEFAULT '0' COMMENT '谷歌验证开关',
  `asst_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '小助手通讯密钥',
  `packvip_id` int(11) NOT NULL DEFAULT '0' COMMENT '套餐ID',
  `packvip_time` bigint(20) NOT NULL DEFAULT '0' COMMENT '套餐到期时间戳',
  `channel_quota` int(11) NOT NULL DEFAULT '0' COMMENT '挂机通道配额',
  `money_edin` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额预警阈值',
  `status_edin` tinyint(4) NOT NULL DEFAULT '0' COMMENT '余额预警开关',
  `wxpusher_uid` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'WxPusher UID',
  `notify_switch` text COLLATE utf8mb4_unicode_ci COMMENT '通知开关(JSON)',
  `pay_notice` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '支付页公告',
  `paypage` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' COMMENT '支付页模板',
  `mapi_return_mode` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'payurl' COMMENT 'mapi默认返回:payurl页面/qrcode二维码链接',
  `pay_outtime` int(11) NOT NULL DEFAULT '180' COMMENT '支付超时(秒)',
  `pay_jump_type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '超时跳转:0源站1自定义',
  `pay_jump_url` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '自定义跳转地址',
  `pay_float_min` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '最小上浮(元)',
  `pay_float_max` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '最大上浮(元)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uk_pid` (`pid`),
  KEY `idx_pay_key` (`pay_key`),
  KEY `idx_asst_key` (`asst_key`)
) ENGINE=InnoDB AUTO_INCREMENT=621 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='会员表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_user_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_user_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组名',
  `rules` text COLLATE utf8mb4_unicode_ci COMMENT '权限节点',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='会员组表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_user_money_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_user_money_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `money` int(11) NOT NULL DEFAULT '0' COMMENT '变更余额',
  `before` int(11) NOT NULL DEFAULT '0' COMMENT '变更前余额',
  `after` int(11) NOT NULL DEFAULT '0' COMMENT '变更后余额',
  `memo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=406 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='会员余额变动表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_user_rule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_user_rule` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `pid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上级菜单',
  `type` enum('route','menu_dir','menu','nav_user_menu','nav','button') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'menu' COMMENT '类型:route=路由,menu_dir=菜单目录,menu=菜单项,nav_user_menu=顶栏会员菜单下拉项,nav=顶栏菜单项,button=页面按钮',
  `title` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '标题',
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '规则名称',
  `path` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '路由路径',
  `icon` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '图标',
  `menu_type` enum('tab','link','iframe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tab' COMMENT '菜单类型:tab=选项卡,link=链接,iframe=Iframe',
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Url',
  `component` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '组件路径',
  `no_login_valid` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '未登录有效:0=否,1=是',
  `extend` enum('none','add_rules_only','add_menu_only') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none' COMMENT '扩展属性:none=无,add_rules_only=只添加为路由,add_menu_only=只添加为菜单',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `weigh` int(11) NOT NULL DEFAULT '0' COMMENT '权重',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态:0=禁用,1=启用',
  `update_time` bigint(16) unsigned DEFAULT NULL COMMENT '更新时间',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `pid` (`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=143 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='会员菜单权限规则表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ba_user_score_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ba_user_score_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `score` int(11) NOT NULL DEFAULT '0' COMMENT '变更积分',
  `before` int(11) NOT NULL DEFAULT '0' COMMENT '变更前积分',
  `after` int(11) NOT NULL DEFAULT '0' COMMENT '变更后积分',
  `memo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` bigint(16) unsigned DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='会员积分变动表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- ===== 种子数据 =====

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

LOCK TABLES `ba_admin_group` WRITE;
/*!40000 ALTER TABLE `ba_admin_group` DISABLE KEYS */;
INSERT INTO `ba_admin_group` (`id`, `pid`, `name`, `rules`, `status`, `update_time`, `create_time`) VALUES (1,0,'超级管理组','*',1,1782885651,1782885651),(2,1,'一级管理员','1,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,77,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,76,89',1,1782885651,1782885651),(3,2,'二级管理员','21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43',1,1782885651,1782885651),(4,3,'三级管理员','55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75',1,1782885651,1782885651);
/*!40000 ALTER TABLE `ba_admin_group` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_admin_rule` WRITE;
/*!40000 ALTER TABLE `ba_admin_rule` DISABLE KEYS */;
INSERT INTO `ba_admin_rule` (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `menu_type`, `url`, `component`, `keepalive`, `extend`, `remark`, `weigh`, `status`, `update_time`, `create_time`) VALUES (1,0,'menu','控制台','dashboard','dashboard','fa fa-dashboard','tab','','/src/views/backend/dashboard.vue',1,'none','Remark lang',999,1,1782885651,1782885651),(2,0,'menu_dir','权限管理','auth','auth','fa fa-group',NULL,'','',0,'none','',100,1,1782885651,1782885651),(3,2,'menu','角色组管理','auth/group','auth/group','fa fa-group','tab','','/src/views/backend/auth/group/index.vue',1,'none','Remark lang',99,1,1782885651,1782885651),(4,3,'button','查看','auth/group/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(5,3,'button','添加','auth/group/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(6,3,'button','编辑','auth/group/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(7,3,'button','删除','auth/group/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(8,2,'menu','管理员管理','auth/admin','auth/admin','el-icon-UserFilled','tab','','/src/views/backend/auth/admin/index.vue',1,'none','',98,1,1782885651,1782885651),(9,8,'button','查看','auth/admin/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(10,8,'button','添加','auth/admin/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(11,8,'button','编辑','auth/admin/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(12,8,'button','删除','auth/admin/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(13,2,'menu','菜单规则管理','auth/rule','auth/rule','el-icon-Grid','tab','','/src/views/backend/auth/rule/index.vue',1,'none','',97,1,1782885651,1782885651),(14,13,'button','查看','auth/rule/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(15,13,'button','添加','auth/rule/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(16,13,'button','编辑','auth/rule/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(17,13,'button','删除','auth/rule/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(18,13,'button','快速排序','auth/rule/sortable','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(19,2,'menu','管理员日志管理','auth/adminLog','auth/adminLog','el-icon-List','tab','','/src/views/backend/auth/adminLog/index.vue',1,'none','',96,1,1782885651,1782885651),(20,19,'button','查看','auth/adminLog/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(21,0,'menu_dir','会员管理','user','user','fa fa-drivers-license',NULL,'','',0,'none','',95,1,1782885651,1782885651),(22,21,'menu','会员管理','user/user','user/user','fa fa-user','tab','','/src/views/backend/user/user/index.vue',1,'none','',94,1,1782885651,1782885651),(23,22,'button','查看','user/user/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(24,22,'button','添加','user/user/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(25,22,'button','编辑','user/user/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(26,22,'button','删除','user/user/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(27,21,'menu','会员分组管理','user/group','user/group','fa fa-group','tab','','/src/views/backend/user/group/index.vue',1,'none','',93,1,1782885651,1782885651),(28,27,'button','查看','user/group/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(29,27,'button','添加','user/group/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(30,27,'button','编辑','user/group/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(31,27,'button','删除','user/group/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(32,21,'menu','会员规则管理','user/rule','user/rule','fa fa-th-list','tab','','/src/views/backend/user/rule/index.vue',1,'none','',92,1,1782885651,1782885651),(33,32,'button','查看','user/rule/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(34,32,'button','添加','user/rule/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(35,32,'button','编辑','user/rule/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(36,32,'button','删除','user/rule/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(37,32,'button','快速排序','user/rule/sortable','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(38,21,'menu','会员余额管理','user/moneyLog','user/moneyLog','el-icon-Money','tab','','/src/views/backend/user/moneyLog/index.vue',1,'none','',91,1,1782885651,1782885651),(39,38,'button','查看','user/moneyLog/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(40,38,'button','添加','user/moneyLog/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(44,0,'menu_dir','常规管理','routine','routine','fa fa-cogs',NULL,'','',0,'none','',89,1,1782885651,1782885651),(45,44,'menu','系统配置','routine/config','routine/config','el-icon-Tools','tab','','/src/views/backend/routine/config/index.vue',1,'none','',88,1,1782885651,1782885651),(46,45,'button','查看','routine/config/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(47,45,'button','编辑','routine/config/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(48,44,'menu','附件管理','routine/attachment','routine/attachment','fa fa-folder','tab','','/src/views/backend/routine/attachment/index.vue',1,'none','Remark lang',87,1,1782885651,1782885651),(49,48,'button','查看','routine/attachment/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(50,48,'button','编辑','routine/attachment/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(51,48,'button','删除','routine/attachment/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(52,44,'menu','个人资料','routine/adminInfo','routine/adminInfo','fa fa-user','tab','','/src/views/backend/routine/adminInfo.vue',1,'none','',86,1,1782885651,1782885651),(53,52,'button','查看','routine/adminInfo/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(54,52,'button','编辑','routine/adminInfo/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(55,0,'menu_dir','数据安全管理','security','security','fa fa-shield',NULL,'','',0,'none','',85,1,1782885651,1782885651),(56,55,'menu','数据回收站','security/dataRecycleLog','security/dataRecycleLog','fa fa-database','tab','','/src/views/backend/security/dataRecycleLog/index.vue',1,'none','',84,1,1782885651,1782885651),(57,56,'button','查看','security/dataRecycleLog/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(58,56,'button','删除','security/dataRecycleLog/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(59,56,'button','还原','security/dataRecycleLog/restore','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(60,56,'button','查看详情','security/dataRecycleLog/info','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(61,55,'menu','敏感数据修改记录','security/sensitiveDataLog','security/sensitiveDataLog','fa fa-expeditedssl','tab','','/src/views/backend/security/sensitiveDataLog/index.vue',1,'none','',83,1,1782885651,1782885651),(62,61,'button','查看','security/sensitiveDataLog/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(63,61,'button','删除','security/sensitiveDataLog/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(64,61,'button','回滚','security/sensitiveDataLog/rollback','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(65,61,'button','查看详情','security/sensitiveDataLog/info','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(66,55,'menu','数据回收规则管理','security/dataRecycle','security/dataRecycle','fa fa-database','tab','','/src/views/backend/security/dataRecycle/index.vue',1,'none','Remark lang',82,1,1782885651,1782885651),(67,66,'button','查看','security/dataRecycle/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(68,66,'button','添加','security/dataRecycle/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(69,66,'button','编辑','security/dataRecycle/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(70,66,'button','删除','security/dataRecycle/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(71,55,'menu','敏感字段规则管理','security/sensitiveData','security/sensitiveData','fa fa-expeditedssl','tab','','/src/views/backend/security/sensitiveData/index.vue',1,'none','Remark lang',81,1,1782885651,1782885651),(72,71,'button','查看','security/sensitiveData/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(73,71,'button','添加','security/sensitiveData/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(74,71,'button','编辑','security/sensitiveData/edit','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(75,71,'button','删除','security/sensitiveData/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(77,45,'button','添加','routine/config/add','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(88,45,'button','删除','routine/config/del','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(89,1,'button','查看','dashboard/index','','',NULL,'','',0,'none','',0,1,1782885651,1782885651),(91,139,'menu','通道类型定义','pay/ctype','pay/ctype','','tab','','/src/views/backend/pay/ctype/index.vue',1,'none','',80,1,1782891246,1782891246),(92,91,'button','查看','pay/ctype/index','','',NULL,'','',0,'none','',0,1,1782891246,1782891246),(93,91,'button','添加','pay/ctype/add','','',NULL,'','',0,'none','',0,1,1782891246,1782891246),(94,91,'button','编辑','pay/ctype/edit','','',NULL,'','',0,'none','',0,1,1782891246,1782891246),(95,91,'button','删除','pay/ctype/del','','',NULL,'','',0,'none','',0,1,1782891246,1782891246),(96,91,'button','快速排序','pay/ctype/sortable','','',NULL,'','',0,'none','',0,1,1782891246,1782891246),(97,139,'menu','通道管理','pay/channel','pay/channel','fa fa-th-large','tab','','/src/views/backend/pay/channel/index.vue',1,'none','',90,1,1782892343,1782892343),(98,97,'button','查看','pay/channel/index','','fa fa-circle-o','tab','','',0,'none','',0,1,1782892343,1782892343),(99,97,'button','添加','pay/channel/add','','fa fa-circle-o','tab','','',0,'none','',0,1,1782892343,1782892343),(100,97,'button','编辑','pay/channel/edit','','fa fa-circle-o','tab','','',0,'none','',0,1,1782892343,1782892343),(101,97,'button','删除','pay/channel/del','','fa fa-circle-o','tab','','',0,'none','',0,1,1782892343,1782892343),(102,97,'button','配置字段','pay/channel/getConfig','','fa fa-circle-o','tab','','',0,'none','',0,1,1782892343,1782892343),(108,138,'menu','会员套餐','pay/packvip','pay/packvip','fa fa-diamond','tab','','/src/views/backend/pay/packvip/index.vue',1,'none','',90,1,1782895232,1782895232),(109,108,'button','查看','pay/packvip/index','','','tab','','',0,'none','',0,1,1782895232,1782895232),(110,108,'button','添加','pay/packvip/add','','','tab','','',0,'none','',0,1,1782895232,1782895232),(111,108,'button','编辑','pay/packvip/edit','','','tab','','',0,'none','',0,1,1782895232,1782895232),(112,108,'button','删除','pay/packvip/del','','','tab','','',0,'none','',0,1,1782895232,1782895232),(113,108,'button','排序','pay/packvip/sortable','','','tab','','',0,'none','',0,1,1782895232,1782895232),(114,138,'menu','订单管理','pay/order','pay/order','fa fa-list-alt','tab','','/src/views/backend/pay/order/index.vue',1,'none','',80,1,1782895232,1782895232),(115,114,'button','查看','pay/order/index','','','tab','','',0,'none','',0,1,1782895232,1782895232),(116,114,'button','删除','pay/order/del','','','tab','','',0,'none','',0,1,1782895232,1782895232),(117,138,'menu','账单管理','pay/callbill','pay/callbill','fa fa-money','tab','','/src/views/backend/pay/callbill/index.vue',1,'none','',60,1,1782895232,1782895232),(118,117,'button','查看','pay/callbill/index','','','tab','','',0,'none','',0,1,1782895232,1782895232),(119,117,'button','删除','pay/callbill/del','','','tab','','',0,'none','',0,1,1782895232,1782895232),(120,140,'menu','云端地址','pay/cloudurl','pay/cloudurl','fa fa-cloud','tab','','/src/views/backend/pay/cloudurl/index.vue',1,'none','',90,1,1782895232,1782895232),(121,120,'button','查看','pay/cloudurl/index','','','tab','','',0,'none','',0,1,1782895232,1782895232),(122,120,'button','添加','pay/cloudurl/add','','','tab','','',0,'none','',0,1,1782895232,1782895232),(123,120,'button','编辑','pay/cloudurl/edit','','','tab','','',0,'none','',0,1,1782895232,1782895232),(124,120,'button','删除','pay/cloudurl/del','','','tab','','',0,'none','',0,1,1782895232,1782895232),(125,120,'button','排序','pay/cloudurl/sortable','','','tab','','',0,'none','',0,1,1782895232,1782895232),(126,140,'menu','云端代理','pay/cloudproxy','pay/cloudproxy','fa fa-server','tab','','/src/views/backend/pay/cloudproxy/index.vue',1,'none','',80,1,1782895232,1782895232),(127,126,'button','查看','pay/cloudproxy/index','','','tab','','',0,'none','',0,1,1782895232,1782895232),(128,126,'button','添加','pay/cloudproxy/add','','','tab','','',0,'none','',0,1,1782895232,1782895232),(129,126,'button','编辑','pay/cloudproxy/edit','','','tab','','',0,'none','',0,1,1782895232,1782895232),(130,126,'button','删除','pay/cloudproxy/del','','','tab','','',0,'none','',0,1,1782895232,1782895232),(131,126,'button','排序','pay/cloudproxy/sortable','','','tab','','',0,'none','',0,1,1782895232,1782895232),(132,137,'menu','支付插件','pay/plugin','pay/plugin','fa fa-puzzle-piece','tab','','/src/views/backend/pay/plugin/index.vue',1,'none','',90,1,1782900361,1782900361),(133,132,'button','查看','pay/plugin/index','','','tab','','',0,'none','',0,1,1782900361,1782900361),(134,114,'button','补单/回调','pay/order/callback','','','tab','','',0,'none','',0,1,1782982173,1782982173),(135,114,'button','详情','pay/order/detail','','','tab','','',0,'none','',0,1,1782982173,1782982173),(136,132,'button','安装','pay/plugin/install','','','tab','','',0,'none','',90,1,1783091775,1783091775),(137,0,'menu_dir','插件管理','pluginmgr','pluginmgr','fa fa-puzzle-piece',NULL,'','none',0,'none','',95,1,1783244511,1783244511),(138,0,'menu_dir','财务管理','financemgr','financemgr','fa fa-money',NULL,'','none',0,'none','',94,1,1783244511,1783244511),(139,0,'menu_dir','通道设置','channelset','channelset','fa fa-random',NULL,'','none',0,'none','',93,1,1783244511,1783244511),(140,0,'menu_dir','云端设置','cloudset','cloudset','fa fa-cloud',NULL,'','none',0,'none','',92,1,1783244511,1783244511),(141,138,'menu','充值订单','pay/recharge','pay/recharge','fa fa-credit-card','tab','','/src/views/backend/pay/recharge/index.vue',1,'none','',70,1,1783244511,1783244511),(142,141,'button','查看','pay/recharge/index','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783245545,1783245545),(143,0,'menu_dir','主题设置','themeset','themeset','fa fa-paint-brush',NULL,'','none',0,'none','',96,1,1783274259,1783274259),(144,143,'menu','主页模板','theme/home','theme/home','fa fa-home','tab','','/src/views/backend/theme/home/index.vue',1,'none','',90,1,1783274259,1783274259),(145,143,'menu','支付模板','theme/cashier','theme/cashier','fa fa-credit-card','tab','','/src/views/backend/theme/cashier/index.vue',1,'none','',89,1,1783274259,1783274259),(146,144,'button','查看','theme/home/index','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783274259,1783274259),(147,144,'button','保存','theme/home/save','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783274259,1783274259),(148,145,'button','查看','theme/cashier/index','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783274259,1783274259),(149,145,'button','保存','theme/cashier/save','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783274259,1783274259),(150,141,'button','补单','pay/recharge/recover','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783334111,1783334111),(151,141,'button','删除','pay/recharge/del','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783334111,1783334111),(152,140,'menu','云端授权','cloud/auth','cloud/auth','fa fa-shield','tab','','/src/views/backend/cloud/auth/index.vue',1,'none','',95,1,1783350614,1783350614),(153,0,'menu','系统更新','cloud/update','cloud/update','fa fa-rocket','tab','','/src/views/backend/cloud/update/index.vue',1,'none','',84,1,1783398023,1783350614),(154,152,'button','查看','cloud/auth/index','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(155,152,'button','保存','cloud/auth/save','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(156,152,'button','校验','cloud/auth/verify','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(157,153,'button','检查','cloud/update/check','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(158,153,'button','更新','cloud/update/apply','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(175,0,'menu','系统监控','cloud/monitor','cloud/monitor','fa fa-heartbeat','tab','','/src/views/backend/cloud/monitor/index.vue',1,'none','',85,1,1783398023,1783398023),(176,175,'button','查看','cloud/monitor/index','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783398023,1783398023),(177,175,'button','日志','cloud/monitor/logs','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783398023,1783398023),(159,132,'button','云端商城','pay/plugin/cloud','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(160,132,'button','下载','pay/plugin/download','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783350614,1783350614),(161,132,'button','卸载','pay/plugin/uninstall','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783351913,1783351913),(162,132,'button','插件配置','pay/plugin/cloudStatus','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783354409,1783354409),(163,132,'button','APP配置读取','pay/plugin/appConfig','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783355126,1783355126),(164,132,'button','APP配置保存','pay/plugin/saveAppConfig','','fa fa-circle-o',NULL,'','',0,'none','',0,1,1783355126,1783355126),(166,0,'menu_dir','通知管理','notifymgr','notifymgr','fa fa-bell',NULL,'','none',0,'none','',91,1,1783370479,1783370479),(167,166,'menu','通知设置','notify/setting','notify/setting','fa fa-envelope-o','tab','','/src/views/backend/notify/setting/index.vue',1,'none','',90,1,1783370479,1783370479),(168,167,'button','查看','notify/setting/index','','','tab','','',0,'none','',0,1,1783370479,1783370479),(169,167,'button','保存','notify/setting/save','','','tab','','',0,'none','',0,1,1783370479,1783370479),(170,167,'button','测试邮件','notify/setting/testMail','','','tab','','',0,'none','',0,1,1783370479,1783370479),(171,166,'menu','通知模板','notify/template','notify/template','fa fa-file-text-o','tab','','/src/views/backend/notify/template/index.vue',1,'none','',80,1,1783370479,1783370479),(172,171,'button','查看','notify/template/index','','','tab','','',0,'none','',0,1,1783370479,1783370479),(173,171,'button','编辑','notify/template/edit','','','tab','','',0,'none','',0,1,1783370479,1783370479),(174,171,'button','排序','notify/template/sortable','','','tab','','',0,'none','',0,1,1783370479,1783370479);
/*!40000 ALTER TABLE `ba_admin_rule` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_config` WRITE;
/*!40000 ALTER TABLE `ba_config` DISABLE KEYS */;
INSERT INTO `ba_config` (`id`, `name`, `group`, `title`, `tip`, `type`, `value`, `content`, `rule`, `extend`, `allow_del`, `weigh`) VALUES (1,'config_group','basics','Config group','','array','[{\"key\":\"basics\",\"value\":\"Basics\"},{\"key\":\"config_quick_entrance\",\"value\":\"Config Quick entrance\"},{\"key\":\"payment\",\"value\":\"支付配置\"},{\"key\":\"register\",\"value\":\"注册配置\"}]',NULL,'required','',0,-1),(2,'site_name','basics','Site Name','','string','XLPAY',NULL,'required','',0,99),(3,'record_number','basics','Record number','域名备案号','string','渝ICP备8888888号-1',NULL,'','',0,0),(4,'version','basics','系统版本号','当前系统版本号，用于云端更新比对；可手动修改','string','v1.0.0',NULL,'required','',0,0),(5,'time_zone','basics','time zone','','string','Asia/Shanghai',NULL,'required','',0,0),(6,'no_access_ip','basics','No access ip','禁止访问站点的ip列表,一行一个','textarea','',NULL,'','',0,0),(7,'smtp_server','mail','smtp server','','string','smtp.qq.com',NULL,'','',0,9),(8,'smtp_port','mail','smtp port','','string','465',NULL,'','',0,8),(9,'smtp_user','mail','smtp user','','string','',NULL,'','',0,7),(10,'smtp_pass','mail','smtp pass','','string','',NULL,'','',0,6),(11,'smtp_verification','mail','smtp verification','','select','SSL','{\"SSL\":\"SSL\",\"TLS\":\"TLS\"}','','',0,5),(12,'smtp_sender_mail','mail','smtp sender mail','','string','',NULL,'email','',0,4),(13,'config_quick_entrance','config_quick_entrance','Config Quick entrance','','array','[{\"key\":\"\\u6570\\u636e\\u56de\\u6536\\u89c4\\u5219\\u914d\\u7f6e\",\"value\":\"security\\/dataRecycle\"},{\"key\":\"\\u654f\\u611f\\u6570\\u636e\\u89c4\\u5219\\u914d\\u7f6e\",\"value\":\"security\\/sensitiveData\"}]',NULL,'','',0,0),(14,'backend_entrance','basics','Backend entrance','','string','/admin',NULL,'required','',0,1),(15,'recharge_uid','payment','充值收款商户号','商户在线充值时向该收款方付款；填其商户号(M开头)或用户ID；须先为其配好收款通道；留空/0=关闭在线充值','string','0',NULL,'',' ',0,30),(16,'recharge_min','payment','单笔最小充值(元)','','number','1',NULL,'',' ',0,20),(17,'recharge_max','payment','单笔最大充值(元)','0=不限','number','10000',NULL,'',' ',0,10),(18,'cloud_url','payment','云端配置服务器','免CK自动配置/云挂机的授权服务器地址，默认 http://peak.h364.cn/','string','https://cloud.iosle.com/',NULL,'',' ',0,5),(19,'user_register_enable','register','是否开启注册','关闭后前台不显示注册入口','switch','1',NULL,'','',1,100),(20,'user_register_verify','register','注册验证','开启后注册需邮箱验证码(需先配置系统邮件)','switch','0',NULL,'','',1,90),(21,'user_register_gift_money','register','注册赠送余额(元)','新用户注册即赠送的余额，0=不赠送','number','0',NULL,'','',1,80),(22,'user_register_gift_packvip','register','注册赠送套餐','新用户注册赠送的会员套餐，按套餐自带天数计到期','select','0','{\"0\":\"不赠送\",\"35\":\"ceshi\"}','','',1,70),(23,'site_logo','basics','站点LOGO','前台页头显示的LOGO','image','/storage/default/20260707/69a302236e99a76fd184bbef4e5316e39d56151e0fb512fdd9b1a.png',NULL,'','',1,60),(24,'site_favicon','basics','Favicon','浏览器标签图标','image','/storage/default/20260707/69a302236e99a76fd184bbef4e5316e39d56151e0fb512fdd9b1a.png',NULL,'','',1,59),(25,'home_enable','basics','是否启用首页','关闭后访问首页直接跳到登录/会员中心','switch','1',NULL,'','',1,58),(26,'webmaster_qq','basics','站长QQ','站长联系QQ','string','',NULL,'','',1,57),(27,'wxpusher_apptoken','notify','WxPusher AppToken','','string','',NULL,'','',1,0),(28,'notify_admin_email','notify','站长接收邮箱','','string','',NULL,'','',1,0),(29,'notify_admin_wxpush_uid','notify','站长WxPusher UID','','string','',NULL,'','',1,0),(30,'notify_admin_register_email','notify','新用户注册-邮件提醒','','switch','0',NULL,'','',1,0),(31,'notify_admin_register_wxpush','notify','新用户注册-微信提醒','','switch','0',NULL,'','',1,0);
/*!40000 ALTER TABLE `ba_config` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_user_rule` WRITE;
/*!40000 ALTER TABLE `ba_user_rule` DISABLE KEYS */;
INSERT INTO `ba_user_rule` (`id`, `pid`, `type`, `title`, `name`, `path`, `icon`, `menu_type`, `url`, `component`, `no_login_valid`, `extend`, `remark`, `weigh`, `status`, `update_time`, `create_time`) VALUES (100,0,'menu_dir','首页总览','home','home','fa fa-home','tab','','',0,'none','',100,1,1783089129,1783089129),(101,100,'menu','仪表盘','merchant/overview','merchant/overview','fa fa-dashboard','tab','','/src/views/frontend/user/merchant/overview.vue',0,'none','',10,1,1783089129,1783089129),(110,0,'menu_dir','账户设置','account','account','fa fa-user-circle','tab','','',0,'none','',90,1,1783089129,1783089129),(111,110,'menu','账户概览','account/overview','account/overview','fa fa-id-card','tab','','/src/views/frontend/user/account/overview.vue',0,'none','',30,1,1783089129,1783089129),(112,110,'menu','个人资料','account/profile','account/profile','fa fa-user','tab','','/src/views/frontend/user/account/profile.vue',0,'none','',20,1,1783089129,1783089129),(113,110,'menu','修改密码','account/changePassword','account/changePassword','fa fa-shield','tab','','/src/views/frontend/user/account/changePassword.vue',0,'none','',10,1,1783089129,1783089129),(120,0,'menu_dir','通道管理','channel','channel','fa fa-th-large','tab','','',0,'none','',80,1,1783089129,1783089129),(121,120,'menu','通道管理','merchant/channels','merchant/channels','fa fa-plug','tab','','/src/views/frontend/user/merchant/channels.vue',0,'none','',30,1,1783089129,1783089129),(122,120,'menu','通道配置','merchant/channelConfig','merchant/channelConfig','fa fa-cogs','tab','','/src/views/frontend/user/merchant/channelConfig.vue',0,'none','',20,1,1783089129,1783089129),(123,120,'menu','轮询池','merchant/pollPool','merchant/pollPool','fa fa-random','tab','','/src/views/frontend/user/merchant/pollPool.vue',0,'none','',10,1,1783089129,1783089129),(130,0,'menu_dir','财务管理','finance','finance','fa fa-rmb','tab','','',0,'none','',70,1,1783089129,1783089129),(131,130,'menu','在线充值','merchant/recharge','merchant/recharge','fa fa-credit-card','tab','','/src/views/frontend/user/merchant/recharge.vue',0,'none','',40,1,1783089129,1783089129),(132,130,'menu','套餐管理','merchant/packages','merchant/packages','fa fa-diamond','tab','','/src/views/frontend/user/merchant/packages.vue',0,'none','',30,1,1783089129,1783089129),(133,130,'menu','订单记录','merchant/orders','merchant/orders','fa fa-list-alt','tab','','/src/views/frontend/user/merchant/orders.vue',0,'none','',20,1,1783089129,1783089129),(134,130,'menu','余额记录','account/balance','account/balance','fa fa-money','tab','','/src/views/frontend/user/account/balance.vue',0,'none','',10,1,1783089129,1783089129),(140,0,'menu','API配置','merchant/apiConfig','merchant/apiConfig','local-terminal','tab','','/src/views/frontend/user/merchant/apiConfig.vue',0,'none','',60,1,1783207267,1783089129),(141,110,'menu','安全设置','account/security','account/security','fa fa-lock','tab','','/src/views/frontend/user/account/security.vue',0,'none','',15,1,1783366740,1783366740),(142,110,'menu','通知设置','account/notify','account/notify','fa fa-bell-o','tab','','/src/views/frontend/user/account/notify.vue',0,'none','',13,1,1783370699,1783370699);
/*!40000 ALTER TABLE `ba_user_rule` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_user_group` WRITE;
/*!40000 ALTER TABLE `ba_user_group` DISABLE KEYS */;
INSERT INTO `ba_user_group` (`id`, `name`, `rules`, `status`, `update_time`, `create_time`) VALUES (1,'默认分组','*',1,1782885651,1782885651);
/*!40000 ALTER TABLE `ba_user_group` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_pay_ctype` WRITE;
/*!40000 ALTER TABLE `ba_pay_ctype` DISABLE KEYS */;
INSERT INTO `ba_pay_ctype` (`id`, `type`, `c_type`, `name`, `notes`, `status`, `weigh`, `create_time`, `update_time`) VALUES (11,'wxpay','wxpay_app_asst','安卓APP监控','监控手机微信到账通知,支持个人码/商户码,不掉线',1,60,1782899817,1783337454),(12,'wxpay','wxpay_book_afk_pc','PC小账本','',1,100,1783090220,1783091892),(13,'alipay','alipay_scan_bill','扫码免挂','',1,100,1783090492,1783337514),(14,'alipay','alipay_app_asst','Peak助手-支付宝APP监控','原理：监控手机顶部的收款到帐通知详细★支持：个人码 商家码 店员小号监听安全稳定不封号、不异常、不掉线',1,50,1783091659,1783091659),(15,'alipay','alipay_epay','易支付-支付宝','对接易支付接口实现支付回调',1,50,1783091659,1783091659),(16,'alipay','alipay_nock_bill','商家账单-免CK','需要去https://open.alipay.com/申请应用自行填写使用官方公开的账单接口,不存在掉线情况',1,50,1783091659,1783091659),(18,'alipay','alipay_official','支付宝官方支付','使用说明：① 请根据实际情况开启3种支付方式。② 请勿开启您未在支付宝官方签约的支付方式。③ 手机端访问优先调用手机网站支付（如已启用），否则调用当面付。电脑网站支付在手机端无法调用！④ 电脑端访问优先调用电脑网站支付（如已启用），否则调用当面付。手机网站支付在电脑端无法调用！',1,50,1783091659,1783091659),(19,'qqpay','qqpay_epay','易支付-QQ钱包','对接易支付接口实现支付回调',1,50,1783091659,1783091659),(20,'wxpay','wxpay_app_daidai','微信安卓APP挂机','原理：监控手机顶部的收款到帐通知详细★支持：个人码 商户码 店员小号监听安全稳定不封号、不异常、不掉线',1,50,1783091659,1783091659),(21,'wxpay','wxpay_book_agt_ipad','Ipad免挂-小账本-[个人动态码]','无需开通任何资质,有微信就能用',1,50,1783091659,1783091659),(22,'wxpay','wxpay_epay','易支付-微信','对接易支付接口实现支付回调',1,50,1783091659,1783091659),(23,'wxpay','wxpay_input_cloud_ipad','官方iPad免挂','无需开通任何资质,有微信就能用',1,50,1783091659,1783091659),(24,'wxpay','wxpay_ios_asst','Peak助手APP监控-苹果版','原理：监听收款到帐通知详细★支持：个人码 商户码 赞赏码 店员小号监听安全稳定不封号、不异常、不掉线',1,50,1783091659,1783091659),(25,'wxpay','wxpay_lkljyf_cloud_ipad','官方Ipad免挂-拉卡拉即易付','需要申请拉卡拉商户->进入微信小程序搜索->拉卡拉即易付->看看是否已绑定商户不懂就下载个拉卡拉APP申请一下商户->微信小程序中的拉卡拉即易付必须能生成收款码才能使用',1,50,1783091659,1783091659),(26,'wxpay','wxpay_message_botting','免挂机终端-全能码-[站长代挂]','支持所有微信收款码,无需挂机,扫码登录即可安全稳定不封号、不异常、不掉线',1,50,1783091659,1783091659),(27,'wxpay','wxpay_msg_afk_pc','Win自挂-全能码-[WIN系统自挂]','无需开通任何资质,有微信就能用,需要有win系统的电脑，非普通PC挂机',1,50,1783091659,1783091659),(28,'wxpay','wxpay_official','微信官方支付','使用说明：① V2 版本：只需填写商户号、APPID、Key、AppSecret，配置简单，适合 Native 支付。② V3 版本：需要额外填写证书序列号和商户私钥，支持 Native 和 H5 支付。③ 请根据实际情况选择 API 版本和支付方式。④ 手机端访问优先调用 H5 支付（如已启用），否则调用 Native 支付。⑤ 电脑端只能调用 Native 支付，H5 支付在电脑端无法使用。',1,50,1783091659,1783091659),(29,'wxpay','wxpay_recpt_afk_pc','PC挂机-收款单-[免输金额]','需要微信开通经营码或者商业码才能使用哦',1,50,1783091659,1783091659),(30,'wxpay','wxpay_recpt_agt_ipad','Ipad免挂-收款单-[免输金额]','需要微信开通经营码或者商业码才能使用哦',1,50,1783091659,1783091659),(31,'wxpay','wxpay_recpt_cloud_ipad','官方iPad免挂-收款单-[免输]','需要微信开通经营码或者商业码才能使用哦',1,50,1783091659,1783091659);
/*!40000 ALTER TABLE `ba_pay_ctype` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_notify_template` WRITE;
/*!40000 ALTER TABLE `ba_notify_template` DISABLE KEYS */;
INSERT INTO `ba_notify_template` (`id`, `key`, `name`, `subject`, `content`, `tokens`, `email_enable`, `wxpush_enable`, `status`, `weigh`, `update_time`, `create_time`) VALUES (1,'user_login','登录提醒','登录提醒-[sitename]','<h2>[sitename] 登录提醒</h2><p>账号：[username]</p><p>登录IP：[ip]</p><p>时间：[date]</p><p style=\"color:#999\">若非本人操作，请尽快修改密码。</p>','[sitename] [username] [ip] [date]',1,1,1,100,1783369605,1783369605),(2,'user_register','注册欢迎','欢迎注册-[sitename]','<h2>欢迎加入 [sitename]</h2><p>账号 <b>[username]</b> 注册成功！</p><p>时间：[date]</p>','[sitename] [username] [date]',1,1,1,90,1783369605,1783369605),(3,'pwd_reset','密码重置提醒','密码重置提醒-[sitename]','<h2>[sitename] 密码重置</h2><p>账号：[username]</p><p>操作IP：[ip]</p><p>时间：[date]</p><p style=\"color:#c00\">若非本人操作，请尽快联系站长。</p>','[sitename] [username] [ip] [date]',1,1,1,80,1783369605,1783369605),(4,'low_balance','余额不足提醒','余额不足提醒-[sitename]','<h2>[sitename] 余额不足</h2><p>账号：[username]</p><p>当前余额：<b>[money]</b> 元</p><p>请尽快登录商户中心充值，以免影响使用。</p>','[sitename] [username] [money] [date]',1,1,1,70,1783369605,1783369605),(5,'order_paid','订单支付成功提醒','订单支付成功-[sitename]','<h2>[sitename] 订单支付成功</h2><p>商户订单号：[out_trade_no]</p><p>商品：[name]</p><p>到账金额：<b>[amount]</b> 元</p><p>通道：[channel]</p><p>时间：[date]</p>','[sitename] [username] [out_trade_no] [name] [amount] [channel] [date]',1,1,1,60,1783369605,1783369605),(6,'order_callback','订单回调提醒','订单回调-[sitename]','<h2>[sitename] 订单回调</h2><p>商户订单号：[out_trade_no]</p><p>金额：[amount] 元</p><p>回调地址：[notes]</p><p>时间：[date]</p>','[sitename] [out_trade_no] [amount] [notes] [date]',1,1,1,50,1783369605,1783369605),(7,'channel_create','创建通道提醒','创建通道-[sitename]','<h2>[sitename] 新建通道</h2><p>账号：[username]</p><p>通道：[channel]</p><p>时间：[date]</p>','[sitename] [username] [channel] [date]',1,1,1,40,1783369605,1783369605),(8,'channel_update','更新通道提醒','更新通道-[sitename]','<h2>[sitename] 通道更新</h2><p>账号：[username]</p><p>通道：[channel]</p><p>时间：[date]</p>','[sitename] [username] [channel] [date]',1,1,1,30,1783369605,1783369605),(9,'channel_offline','通道掉线提醒','通道掉线提醒-[sitename]','<h2>[sitename] 通道掉线</h2><p>账号：[username]</p><p>通道：[channel]（[notes]）掉线了</p><p>时间：[date]</p>','[sitename] [username] [channel] [notes] [date]',1,1,1,20,1783369605,1783369605);
/*!40000 ALTER TABLE `ba_notify_template` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `ba_pay_packvip` WRITE;
/*!40000 ALTER TABLE `ba_pay_packvip` DISABLE KEYS */;
INSERT INTO `ba_pay_packvip` (`id`, `name`, `days`, `rate`, `mini_rate`, `channel_quota`, `bind_ctype`, `price`, `notes`, `quota`, `status`, `weigh`, `create_time`, `update_time`) VALUES (35,'ceshi',30,3.00,0.00,999,'[\"alipay_scan_bill\",\"wxpay_app_asst\",\"alipay_official\",\"alipay_nock_auto\",\"wxpay_book_afk_pc\",\"wxpay_recpt_afk_pc\"]',0.00,NULL,0,1,100,1782907346,1782907346);
/*!40000 ALTER TABLE `ba_pay_packvip` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


SET FOREIGN_KEY_CHECKS=1;
