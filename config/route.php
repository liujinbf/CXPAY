<?php

use Webman\Route;

// ── 生产健康探针 ──────────────────────────────────────────────────────────────
// 不经过任何认证/部署检查中间件，Docker healthcheck 与 K8s probe 直接使用
Route::get('/health',       [app\controller\HealthController::class, 'index']);
Route::get('/health/live',  [app\controller\HealthController::class, 'live']);
Route::get('/health/ready', [app\controller\HealthController::class, 'ready']);

// ── Prometheus 指标抓取端点（仅 127.0.0.1 或带 scrape-token 可访问）──────────
Route::get('/metrics', [app\controller\MetricsController::class, 'scrape']);
// ─────────────────────────────────────────────────────────────────────────────

if ((string)config('deployment.role', 'payment') !== 'payment') {
    throw new RuntimeException('CXPAY 主应用只支持 payment 运行角色，云端控制面必须独立部署');
}

Route::disableDefaultRoute(app\controller\api\CloudLicenseController::class);

// 一键安装向导路由 (自动安装检测与已安装防护)
// 注意：直接用 file_get_contents 输出 HTML，与 /doc 路由保持一致，
// 避免 redirect 到静态文件路径因服务器配置差异导致 PageNotFoundException。
Route::get('/install', function () {
    $lockFile = (string)config('app.install_lock', base_path() . '/install.lock');
    if (file_exists($lockFile)) {
        return response('<div style="background:#0f172a;color:#f3f4f6;padding:40px;text-align:center;font-family:sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;">
            <div style="font-size:48px;margin-bottom:16px;">&#128737;&#65039;</div>
            <h2 style="font-size:24px;font-weight:bold;margin-bottom:8px;">&#31995;&#32479;&#24050;&#34987;&#23433;&#20840;&#38145;&#23450;</h2>
            <p style="color:#94a3b8;font-size:14px;max-width:480px;line-height:1.6;">&#24403;&#21069;&#31995;&#32479;&#24050;&#23433;&#35013;&#23436;&#25104;&#24182;&#29983;&#25104;&#20102; <code>install.lock</code> &#23433;&#20840;&#38145;&#25991;&#20214;&#12290;&#22914;&#38656;&#37325;&#26032;&#23433;&#35013;&#65292;&#35831;&#22312;&#26381;&#21153;&#22120;&#20013;&#21024;&#38500;&#26681;&#30446;&#24405;&#19979;&#30340; <code>install.lock</code> &#38145;&#25991;&#20214;&#12290;</p>
            <a href="/" style="margin-top:24px;display:inline-block;padding:10px 24px;background:#0284c7;color:#fff;border-radius:12px;text-decoration:none;font-weight:bold;font-size:14px;">&#36820;&#22238;&#32593;&#31449;&#39318;&#39029;</a>
        </div>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
    $installHtml = base_path() . '/public/install/index.html';
    $content = file_exists($installHtml) ? file_get_contents($installHtml) : '安装文件缺失，请重新上传。';
    return response($content, file_exists($installHtml) ? 200 : 500, ['Content-Type' => 'text/html; charset=utf-8']);
});
// /install/ 与 /install/index.html 统一收归到 /install 路由，避免静态文件路由 404
Route::get('/install/', function () {
    return redirect('/install');
});
Route::get('/install/index.html', function () {
    return redirect('/install');
});
Route::any('/api/install/check',       [app\controller\api\InstallController::class, 'check']);
Route::any('/api/install/test_db',     [app\controller\api\InstallController::class, 'testDb']);
Route::any('/api/install/test_redis',  [app\controller\api\InstallController::class, 'testRedis']);
Route::any('/api/install/env_info',    [app\controller\api\InstallController::class, 'environmentInfo']);
Route::any('/api/install/execute',     [app\controller\api\InstallController::class, 'execute']);
Route::any('/api/install/check_nginx', [app\controller\api\InstallController::class, 'checkNginx']);

// 商户开放 API 开发对接文档
Route::get('/doc', function () {
    $content = file_get_contents(base_path() . '/public/doc.html');
    return response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});
// 云端授权、购买与下载由独立控制面提供，支付节点只负责跳转。
Route::get('/cloud', static function () {
    return redirect((string)config('cloud.portal_url', 'https://cloud.cxpay.com'));
});

// 系统在线更新管理页面（重定向至后台 Git 更新选项卡）
Route::get('/admin/system_update', function () {
    return redirect('/admin/index.html#system-update');
})->middleware([app\middleware\AdminAuthMiddleware::class]);

// 动态首页路由控制
Route::get('/', [app\controller\IndexController::class, 'index']);

// 商户后台入口别名路由支持
Route::get('/merchant', static fn() => redirect('/merchant_center.html'));
Route::get('/merchant/', static fn() => redirect('/merchant_center.html'));
Route::get('/merchant/index.html', static fn() => redirect('/merchant_center.html'));
Route::get('/user', static fn() => redirect('/merchant_center.html'));
Route::get('/user/', static fn() => redirect('/merchant_center.html'));
Route::get('/user/index.html', static fn() => redirect('/merchant_center.html'));

// 易支付标准下单网关协议 ( Submit.php / mapi.php )
Route::any('/submit.php', [app\controller\gateway\SubmitController::class, 'submit']);
Route::any('/mapi.php', [app\controller\gateway\SubmitController::class, 'submit']);

// 上游异步回调监听路由
Route::any('/notify/{cType}', [app\controller\notify\NotifyController::class, 'index']);

// 微信小账本/收款单 扫码登录授权免挂 API
Route::get('/api/wxprotocol/login_qr', [app\controller\api\WeChatProtocolAdminController::class, 'getLoginQr']);
Route::any('/api/wxprotocol/poll_qr', [app\controller\api\WeChatProtocolAdminController::class, 'pollQr']);
Route::get('/api/wxprotocol/auth_page', [app\controller\api\WeChatProtocolAdminController::class, 'authPage']);
Route::any('/api/wxprotocol/confirm_auth', [app\controller\api\WeChatProtocolAdminController::class, 'confirmAuth']);

// 支付宝 AppAuth 扫码授权免挂 API
Route::get('/api/alipay/login_qr', [app\controller\api\AlipayProtocolAdminController::class, 'getLoginQr']);
Route::any('/api/alipay/poll_qr', [app\controller\api\AlipayProtocolAdminController::class, 'pollQr']);
Route::get('/api/alipay/auth_page', [app\controller\api\AlipayProtocolAdminController::class, 'authPage']);
Route::any('/api/alipay/confirm_auth', [app\controller\api\AlipayProtocolAdminController::class, 'confirmAuth']);
Route::any('/api/alipay/auth/start', [app\controller\api\AlipayProtocolAdminController::class, 'startAuth']);
Route::any('/api/alipay/auth/poll', [app\controller\api\AlipayProtocolAdminController::class, 'pollAuth']);
Route::any('/api/alipay/auth/callback', [app\controller\api\AlipayProtocolAdminController::class, 'callback']);

// QQ 钱包 ptlogin 扫码授权免挂 API
Route::get('/api/qqprotocol/login_qr', [app\controller\api\QQProtocolAdminController::class, 'getLoginQr']);
Route::any('/api/qqprotocol/poll_qr', [app\controller\api\QQProtocolAdminController::class, 'pollQr']);
Route::get('/api/qqprotocol/auth_page', [app\controller\api\QQProtocolAdminController::class, 'authPage']);
Route::any('/api/qqprotocol/confirm_auth', [app\controller\api\QQProtocolAdminController::class, 'confirmAuth']);

// 订单公开查询 API
Route::any('/api/order/query', [app\controller\api\OrderController::class, 'query']);

// 挂机助手 OpenAPI 账单上报推送与版本检查（公开）
Route::any('/api/appasst/push',    [app\controller\api\AppasstController::class, 'push']);
Route::get('/api/appasst/version', [app\controller\api\AppasstController::class, 'version']);

// APK / 客户端安装包下载 — 需携带一次性 dl_token 或已登录管理员 Session
Route::get('/api/appasst/download',              [app\controller\api\AppasstController::class, 'downloadApk'])
    ->middleware([app\middleware\DownloadAuthMiddleware::class]);
Route::get('/download/CXPayAssistant.apk',        [app\controller\api\AppasstController::class, 'downloadApk'])
    ->middleware([app\middleware\DownloadAuthMiddleware::class]);
Route::get('/download/CXPayAssistant_latest.apk', [app\controller\api\AppasstController::class, 'downloadApk'])
    ->middleware([app\middleware\DownloadAuthMiddleware::class]);

// 企业微信自建应用 Webhook 回调通知（支持 GET 验证与 POST 消息）
Route::any('/api/wecom/webhook', [app\controller\api\WecomWebhookController::class, 'handle']);
Route::any('/api/wecom/webhook/{channel_id}', [app\controller\api\WecomWebhookController::class, 'handle']);

// 官方云端授权中心支付异步通知端点（插件购买与配额增购核销）
Route::post('/api/cloud/plugin/order/notify', [app\controller\api\CloudOrderNotifyController::class, 'handlePluginOrderNotify']);
Route::post('/api/agent/quota/notify',        [app\controller\api\CloudOrderNotifyController::class, 'handleQuotaNotify']);


// 授权账单源：采集端写入，PC 监控端按游标拉取
Route::post('/api/bill-source/ingest', [app\controller\api\BillSourceController::class, 'ingest']);
Route::get('/api/bill-source/poll', [app\controller\api\BillSourceController::class, 'poll']);

// 商户注册、登录与注销公开 API
Route::post('/api/merchant/register', [app\controller\api\MerchantApiController::class, 'register']);
Route::post('/api/merchant/login', [app\controller\api\MerchantApiController::class, 'login']);
Route::post('/api/merchant/logout', [app\controller\api\MerchantApiController::class, 'logout']);

// 管理员登录与注销公开 API
Route::post('/api/admin/login',        [app\controller\admin\AdminAuthController::class, 'login']);
Route::post('/api/admin/login/verify', [app\controller\admin\AdminAuthController::class, 'verifyLoginCode']);
Route::post('/api/admin/logout',       [app\controller\admin\AdminAuthController::class, 'logout']);

// 子管理员专用登录（cx_admin 表账号，独立于超级管理员）
Route::post('/api/admin/sub/login',    [app\controller\admin\SubAdminController::class, 'login']);
// 邀请激活端点（被邀请者用 invite_token 设置密码完成注册）
Route::post('/api/admin/sub/activate', [app\controller\admin\SubAdminController::class, 'activate']);

// 商户侧控制台 API
Route::group('/api/merchant', function () {
    Route::get('/dashboard', [app\controller\api\MerchantApiController::class, 'getDashboardData']);
    Route::get('/finance_log', [app\controller\api\MerchantApiController::class, 'getFinanceLogs']);
    Route::get('/profile', [app\controller\api\MerchantApiController::class, 'getProfile']);
    Route::post('/reset_key', [app\controller\api\MerchantApiController::class, 'resetKey']);
    Route::post('/change_password', [app\controller\api\MerchantApiController::class, 'changePassword']);
    Route::post('/buy_vip', [app\controller\api\MerchantApiController::class, 'buyVip']);
    Route::get('/channel/list', [app\controller\api\MerchantChannelController::class, 'list']);
    Route::get('/channel/download_preset', [app\controller\api\MerchantChannelController::class, 'downloadPresetClient']);
    Route::post('/channel/save', [app\controller\api\MerchantChannelController::class, 'save']);
    Route::post('/channel/toggle', [app\controller\api\MerchantChannelController::class, 'toggle']);
    Route::post('/channel/delete', [app\controller\api\MerchantChannelController::class, 'delete']);
    Route::get('/channel/drivers', [app\controller\api\MerchantChannelController::class, 'drivers']);
    Route::post('/channel/test', [app\controller\api\MerchantChannelController::class, 'test']);
    Route::post('/channel/test_status', [app\controller\api\MerchantChannelController::class, 'testStatus']);
    Route::post('/channel/capabilities', [app\controller\api\MerchantChannelController::class, 'capabilities']);
    Route::post('/channel/authorization/start', [app\controller\api\MerchantChannelController::class, 'startAuthorization']);
    Route::post('/channel/start_authorization', [app\controller\api\MerchantChannelController::class, 'startAuthorization']);
    Route::post('/channel/authorization/poll', [app\controller\api\MerchantChannelController::class, 'pollAuthorization']);
    Route::post('/channel/poll_authorization', [app\controller\api\MerchantChannelController::class, 'pollAuthorization']);
    Route::post('/channel/start_driver_auth', [app\controller\api\MerchantChannelController::class, 'startDriverAuth']);
    Route::post('/channel/poll_driver_auth', [app\controller\api\MerchantChannelController::class, 'pollDriverAuth']);
    Route::post('/channel/appasst_sync_pair', [app\controller\api\MerchantChannelController::class, 'appasstSyncPair']);
    Route::get('/bill-source/status', [app\controller\api\BillSourceManageController::class, 'merchantStatus']);
    Route::post('/bill-source/rotate-token', [app\controller\api\BillSourceManageController::class, 'merchantRotate']);
    Route::get('/order/list', [app\controller\api\OrderController::class, 'list']);
    Route::post('/order/manual_pay', [app\controller\api\OrderController::class, 'manualPay']);
    Route::post('/order/delete', [app\controller\api\OrderController::class, 'delete']);
    Route::post('/order/batch_clean', [app\controller\api\OrderController::class, 'batchClean']);
    Route::post('/recharge/create', [app\controller\api\MerchantRechargeController::class, 'create']);
    Route::get('/alert/config', [app\controller\api\MerchantApiController::class, 'getAlertConfig']);
    Route::post('/alert/config/save', [app\controller\api\MerchantApiController::class, 'saveAlertConfig']);
    Route::post('/alert/test', [app\controller\api\MerchantApiController::class, 'testAlert']);
    Route::post('/order/resend_notify', [app\controller\api\MerchantApiController::class, 'resendOrderNotify']);
    // 商户端套餐 API
    Route::get('/plan/list', [app\controller\api\MerchantApiController::class, 'getPlanList']);
    Route::post('/plan/buy', [app\controller\api\MerchantApiController::class, 'buyPlan']);
    // 商户端报表 API
    Route::get('/report/trend',         [app\controller\api\MerchantReportController::class, 'trend']);
    Route::get('/report/pay_type_dist', [app\controller\api\MerchantReportController::class, 'payTypeDist']);
    Route::get('/report/export_csv',    [app\controller\api\MerchantReportController::class, 'exportCsv']);
})->middleware([app\middleware\MerchantAuthMiddleware::class]);


// 管理员后台与插件商城 API
Route::group('/api/admin', function () {
    Route::any('/dashboard', [app\controller\admin\AdminDashboardController::class, 'dashboard']);
    Route::get('/channel/list', [app\controller\admin\AdminChannelConfigController::class, 'listChannels']);
    Route::get('/channel/drivers', [app\controller\admin\AdminChannelConfigController::class, 'drivers']);
    Route::get('/channel/driver_list', [app\controller\admin\AdminChannelConfigController::class, 'getDriverList']);
    Route::get('/channel/download_preset', [app\controller\admin\AdminChannelConfigController::class, 'downloadPresetClient']);
    Route::post('/channel/save', [app\controller\admin\AdminChannelConfigController::class, 'saveChannelConfig']);
    Route::get('/channel/get', [app\controller\admin\AdminChannelConfigController::class, 'getChannelConfig']);
    Route::get('/channel/inputs', [app\controller\admin\ChannelAdminController::class, 'getConfigInputs']);
    Route::post('/channel/start_driver_auth', [app\controller\admin\ChannelAdminController::class, 'startDriverAuth']);
    Route::post('/channel/poll_driver_auth', [app\controller\admin\ChannelAdminController::class, 'pollDriverAuth']);
    Route::get('/channel/clerk_config', [app\controller\admin\AdminChannelConfigController::class, 'getPlatformClerkConfig']);
    Route::post('/channel/clerk_config/save', [app\controller\admin\AdminChannelConfigController::class, 'savePlatformClerkConfig']);
    Route::get('/channel/isv_config', [app\controller\admin\AdminChannelConfigController::class, 'getIsvConfig']);
    Route::post('/channel/isv_config/save', [app\controller\admin\AdminChannelConfigController::class, 'saveIsvConfig']);
    Route::post('/channel/appasst_sync_pair', [app\controller\admin\AdminChannelConfigController::class, 'appasstSyncPair']);
    Route::get('/bill-source/list', [app\controller\api\BillSourceManageController::class, 'adminList']);
    Route::get('/bill-source/status', [app\controller\api\BillSourceManageController::class, 'adminStatus']);
    Route::post('/bill-source/rotate-token', [app\controller\api\BillSourceManageController::class, 'adminRotate']);
    Route::get('/bill-source/events', [app\controller\api\BillSourceManageController::class, 'adminEvents']);
    Route::post('/bill-source/test-ingest', [app\controller\api\BillSourceManageController::class, 'adminTestIngest']);
    Route::post('/bill-source/clear-events', [app\controller\api\BillSourceManageController::class, 'adminClearEvents']);
    Route::get('/merchant/list', [app\controller\admin\AdminMerchantController::class, 'listMerchants']);
    Route::get('/merchant/detail', [app\controller\admin\AdminMerchantController::class, 'getMerchantDetail']);
    Route::post('/merchant/save', [app\controller\admin\AdminMerchantController::class, 'saveMerchant']);
    Route::post('/merchant/delete', [app\controller\admin\AdminMerchantController::class, 'deleteMerchant']);
    Route::post('/merchant/batch_clean_test', [app\controller\admin\AdminMerchantController::class, 'batchCleanTestMerchants']);
    Route::post('/merchant/adjust_balance', [app\controller\admin\AdminMerchantController::class, 'adjustBalance']);
    Route::post('/order/force_notify', [app\controller\admin\OrderAdminController::class, 'forceNotifyOrder']);
    Route::post('/template/save', [app\controller\admin\MerchantTemplateController::class, 'saveTemplate']);
    
    // 订单高级检索与人工补单 API
    Route::get('/order/list', [app\controller\admin\OrderAdminController::class, 'list']);
    Route::post('/order/close', [app\controller\admin\OrderAdminController::class, 'close']);
    Route::post('/order/delete', [app\controller\admin\OrderAdminController::class, 'delete']);
    Route::post('/order/batch_clean', [app\controller\admin\OrderAdminController::class, 'batchClean']);
    Route::post('/order/manual_pay', [app\controller\admin\CallbillAdminController::class, 'manualPay']);
    Route::get('/callbill/review_list', [app\controller\admin\CallbillAdminController::class, 'reviewList']);
    Route::post('/callbill/review_match', [app\controller\admin\CallbillAdminController::class, 'reviewMatch']);
    Route::get('/cloud-monitor/status', [app\controller\admin\CloudMonitorAdminController::class, 'status']);
    Route::post('/cloud-monitor/toggle', [app\controller\admin\CloudMonitorAdminController::class, 'toggle']);

    // 插件商城 API
    Route::get('/plugin/market_list', [app\controller\admin\PluginMarketController::class, 'getMarketList']);
    Route::post('/plugin/install', [app\controller\admin\PluginMarketController::class, 'installPlugin']);
    Route::post('/plugin/set_enabled', [app\controller\admin\PluginMarketController::class, 'setEnabled']);
    Route::post('/plugin/rollback', [app\controller\admin\PluginMarketController::class, 'rollback']);
    Route::post('/plugin/uninstall', [app\controller\admin\PluginMarketController::class, 'uninstall']);
    // 云端插件商城对接
    Route::get('/plugin/cloud_market', [app\controller\admin\CloudPluginMarketController::class, 'getCloudMarket']);
    Route::post('/plugin/cloud_buy', [app\controller\admin\CloudPluginMarketController::class, 'buyFromCloud']);
    Route::post('/plugin/cloud_download', [app\controller\admin\CloudPluginMarketController::class, 'downloadFromCloud']);
    Route::post('/plugin/order/create', [app\controller\admin\CloudPluginMarketController::class, 'createPurchaseOrder']);
    Route::post('/plugin/order/status', [app\controller\admin\CloudPluginMarketController::class, 'checkOrderStatus']);
    Route::post('/plugin/order/confirm', [app\controller\admin\CloudPluginMarketController::class, 'confirmPayment']);
    Route::get('/plugin/instance_status', [app\controller\admin\CloudPluginMarketController::class, 'instanceStatus']);
    Route::post('/plugin/activate_instance', [app\controller\admin\CloudPluginMarketController::class, 'activateInstance']);

    // OEM 代理商加盟中心与授权下发
    Route::get('/agent/profile', [app\controller\admin\AgentHubController::class, 'profile']);
    Route::post('/agent/license/issue', [app\controller\admin\AgentHubController::class, 'issueLicense']);
    Route::get('/agent/sub_instances', [app\controller\admin\AgentHubController::class, 'listSubInstances']);
    Route::post('/agent/license/revoke', [app\controller\admin\AgentHubController::class, 'revokeLicense']);
    Route::post('/agent/license/restore', [app\controller\admin\AgentHubController::class, 'restoreLicense']);
    Route::post('/agent/license/delete', [app\controller\admin\AgentHubController::class, 'deleteLicense']);
    Route::post('/agent/license/rebind', [app\controller\admin\AgentHubController::class, 'rebindLicense']);
    Route::post('/agent/quota/buy', [app\controller\admin\AgentHubController::class, 'buyQuota']);

    // 轮询组智能调度 API
    Route::get('/poll_group/list', [app\controller\admin\PollGroupController::class, 'list']);
    Route::post('/poll_group/save', [app\controller\admin\PollGroupController::class, 'save']);
    Route::post('/poll_group/delete', [app\controller\admin\PollGroupController::class, 'delete']);
    Route::post('/poll_group/toggle', [app\controller\admin\PollGroupController::class, 'toggle']);
    Route::get('/poll_group/available_channels', [app\controller\admin\PollGroupController::class, 'availableChannels']);
    Route::post('/poll_group/bind', [app\controller\admin\PollGroupController::class, 'bindChannels']);
    Route::post('/poll_group/simulate', [app\controller\admin\PollGroupController::class, 'simulate']);



    // VIP 套餐 API
    Route::get('/packvip/list', [app\controller\admin\PackvipAdminController::class, 'list']);
    Route::get('/packvip/drivers', [app\controller\admin\PackvipAdminController::class, 'drivers']);
    Route::post('/packvip/save', [app\controller\admin\PackvipAdminController::class, 'save']);
    Route::post('/packvip/delete', [app\controller\admin\PackvipAdminController::class, 'delete']);

    // 系统在线更新 API
    Route::get('/system/check_update',   [app\controller\admin\SystemUpdateController::class, 'checkUpdate']);
    Route::post('/system/do_update',     [app\controller\admin\SystemUpdateController::class, 'doUpdate']);
    Route::get('/system/poll_progress',  [app\controller\admin\SystemUpdateController::class, 'pollProgress']);
    Route::get('/system/update_log',     [app\controller\admin\SystemUpdateController::class, 'getUpdateLog']);
    Route::get('/system/version_history',[app\controller\admin\SystemUpdateController::class, 'versionHistory']);
    Route::post('/system/do_rollback',   [app\controller\admin\SystemUpdateController::class, 'doRollback']);

    // 系统运营与交易参数配置 API
    Route::get('/system/config',       [app\controller\admin\SystemConfigController::class, 'getConfig']);
    Route::post('/system/config/save', [app\controller\admin\SystemConfigController::class, 'saveConfig']);

    // 告警通知配置 API
    Route::get('/alert/config',        [app\controller\admin\AlertConfigController::class, 'getConfig']);
    Route::post('/alert/config/save',  [app\controller\admin\AlertConfigController::class, 'saveConfig']);
    Route::post('/alert/test',         [app\controller\admin\AlertConfigController::class, 'sendTest']);


    // 管理员安全设置 API（二次验证码配置）
    Route::get('/security/config',       [app\controller\admin\AdminSecurityController::class, 'getSecurityConfig']);
    Route::post('/security/config/save', [app\controller\admin\AdminSecurityController::class, 'saveSecurityConfig']);
    // 管理员报表 API
    Route::get('/report/trend',        [app\controller\admin\ReportController::class, 'trend']);
    Route::get('/report/channel_dist', [app\controller\admin\ReportController::class, 'channelDist']);
    Route::get('/report/merchant_rank',[app\controller\admin\ReportController::class, 'merchantRank']);
    Route::get('/report/export_csv',   [app\controller\admin\ReportController::class, 'exportCsv']);

    // 下载授权 Token 颁发（管理员生成一次性下载链接，300s 有效）
    Route::post('/download/token', [app\controller\admin\AdminAuthController::class, 'issueDownloadToken']);

    // Token 滑动续期（前端在过期前 10 分钟调用，旧 Token 立即加入黑名单）
    Route::get('/token/refresh',   [app\controller\admin\AdminAuthController::class, 'refreshToken']);

    // 子管理员账号 CRUD（RBAC 多角色管理）
    Route::get('/sub_admin/list',    [app\controller\admin\SubAdminController::class, 'list']);
    Route::post('/sub_admin/save',   [app\controller\admin\SubAdminController::class, 'save']);
    Route::post('/sub_admin/delete', [app\controller\admin\SubAdminController::class, 'delete']);
    Route::post('/sub_admin/toggle', [app\controller\admin\SubAdminController::class, 'toggle']);
    Route::post('/sub_admin/invite', [app\controller\admin\SubAdminController::class, 'invite']);

    // 审计日志查询
    Route::get('/audit/list',        [app\controller\admin\AuditLogController::class, 'list']);
    Route::get('/audit/export_csv',  [app\controller\admin\AuditLogController::class, 'exportCsv']);
})->middleware([app\middleware\AdminAuthMiddleware::class]);

// 商户开放 API (带签名验证中间件)
Route::group('/api', function () {
    Route::any('/order/query_signed', [app\controller\api\OrderController::class, 'query']);
})->middleware([app\middleware\ApiAuthMiddleware::class]);

// 沙箱测试端点（内部用 sandbox_secret 密钥保护，无需登录态）
Route::group('/api/sandbox', function () {
    Route::get('/pay',      [app\controller\api\SandboxController::class, 'payPage']);
    Route::post('/complete',[app\controller\api\SandboxController::class, 'complete']);
});
