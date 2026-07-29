-- 通道类型种子（迁移自旧 peakpay_ctype，datetime→时间戳，sort→weigh）
SET NAMES utf8mb4;
INSERT INTO `ba_pay_ctype` (`id`,`type`,`c_type`,`name`,`notes`,`status`,`weigh`,`create_time`,`update_time`) VALUES
(1,'alipay','alipay_nock_bill','支付宝免CK账单','官方公开账单接口,不掉线;扫码登录自动申请接口/私钥',1,50,UNIX_TIMESTAMP('2024-04-14 05:13:16'),UNIX_TIMESTAMP('2024-04-14 05:13:16')),
(2,'alipay','alipay_scan_bill','支付宝扫码免挂','扫码登录获取CK检测账单到账,支持大并发',1,49,UNIX_TIMESTAMP('2024-03-27 09:23:23'),UNIX_TIMESTAMP('2024-03-27 09:23:23')),
(3,'wxpay','wxpay_uos_clouds','UOS微信云端','扫码登录检测账单到账,支持大并发',1,48,UNIX_TIMESTAMP('2024-04-14 05:13:16'),UNIX_TIMESTAMP('2024-04-14 05:13:16')),
(4,'qqpay','qqpay_scan_no','QQ钱包扫码免挂','扫码登录QQ财付通获取CK检测账单,支持大并发',1,47,UNIX_TIMESTAMP('2024-03-27 09:23:23'),UNIX_TIMESTAMP('2024-03-27 09:23:23')),
(5,'alipay','alipay_upck_clouds','支付宝自更新CK云端免挂','云端协助更新CK,模拟人工延长登录',1,50,UNIX_TIMESTAMP('2024-05-10 05:13:16'),UNIX_TIMESTAMP('2024-05-10 05:13:16')),
(6,'alipay','alipay_scan_no_docking','支付宝同系统对接云端免挂','同系统互为云端,自由选择登录地址',1,50,UNIX_TIMESTAMP('2024-05-10 09:23:23'),UNIX_TIMESTAMP('2024-05-10 09:23:23')),
(7,'wxpay','wxpay_scan_no_docking','UOS微信同系统对接云端免挂','同系统互为云端,自由选择登录地址',1,48,UNIX_TIMESTAMP('2024-05-10 09:23:23'),UNIX_TIMESTAMP('2024-05-10 09:23:23')),
(8,'qqpay','qqpay_scan_no_docking','QQ钱包同系统对接云端免挂','同系统互为云端,自由选择登录地址',1,46,UNIX_TIMESTAMP('2024-05-10 09:23:23'),UNIX_TIMESTAMP('2024-05-10 09:23:23')),
(9,'alipay','alipay_face_to_face','支付宝当面付','官方签约当面付,无需上传收款码',1,50,UNIX_TIMESTAMP('2024-05-10 05:13:16'),UNIX_TIMESTAMP('2024-05-10 05:13:16')),
(10,'alipay','alipay_nock_auto','免CK[自动配置]-需执照秒通过','官方公开账单接口,不掉线;有执照秒过',1,50,UNIX_TIMESTAMP('2024-04-14 05:13:16'),UNIX_TIMESTAMP('2024-04-14 05:13:16'));
