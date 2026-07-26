<?php

use Webman\Route;

// 一键安装向导路由
Route::get('/install', function () {
    $content = file_get_contents(base_path() . '/public/install/index.html');
    return response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});
Route::post('/api/install/execute', [app\controller\api\InstallController::class, 'execute']);

// 商户开放 API 开发对接文档
Route::get('/doc', function () {
    $content = file_get_contents(base_path() . '/public/doc.html');
    return response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});

// 云端·授权中心 专属路由与 API
Route::get('/cloud', function () {
    $content = file_get_contents(base_path() . '/public/cloud_auth.html');
    return response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});

Route::group('/api/cloud', function () {
    Route::get('/site_info', [app\controller\api\CloudLicenseController::class, 'getSiteInfo']);
    Route::post('/renew_module', [app\controller\api\CloudLicenseController::class, 'renewModule']);
    Route::post('/reset_key', [app\controller\api\CloudLicenseController::class, 'resetKey']);
    Route::post('/change_domain', [app\controller\api\CloudLicenseController::class, 'changeDomain']);
    Route::get('/download_package', [app\controller\api\CloudLicenseController::class, 'downloadPackage']);
    Route::post('/trace_leaked', [app\controller\api\CloudLicenseController::class, 'traceLeaked']);
    
    // QQ 扫码/快捷登录与邮箱验证码绑定 API
    Route::get('/qq_login_qr', [app\controller\api\CloudLicenseController::class, 'getQqLoginQr']);
    Route::any('/poll_qq_login', [app\controller\api\CloudLicenseController::class, 'pollQqLogin']);
    Route::post('/send_email_code', [app\controller\api\CloudLicenseController::class, 'sendEmailCode']);
    Route::post('/bind_qq', [app\controller\api\CloudLicenseController::class, 'bindQq']);

    // 微信扫码授权与一键登录 API
    Route::get('/wx_login_qr', [app\controller\api\CloudLicenseController::class, 'getWxLoginQr']);
    Route::any('/poll_wx_login', [app\controller\api\CloudLicenseController::class, 'pollWxLogin']);
});

// 动态首页路由控制
Route::get('/', [app\controller\IndexController::class, 'index']);

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

// 个人收款码上传自动解码 API
Route::post('/api/qr/upload', [app\controller\api\QrUploadController::class, 'upload']);

// 支付宝/通道防掉线心跳检测 API
Route::any('/api/channel/keepalive', [app\controller\api\ChannelKeepAliveController::class, 'keepalive']);

// 挂机助手 OpenAPI 账单上报推送
Route::any('/api/appasst/push', [app\controller\api\AppasstController::class, 'push']);

// 商户登录与注销公开 API
Route::post('/api/merchant/login', [app\controller\api\MerchantApiController::class, 'login']);
Route::post('/api/merchant/logout', [app\controller\api\MerchantApiController::class, 'logout']);

// 管理员登录与注销公开 API
Route::post('/api/admin/login', [app\controller\admin\AdminController::class, 'login']);
Route::post('/api/admin/logout', [app\controller\admin\AdminController::class, 'logout']);

// 商户侧控制台 API
Route::group('/api/merchant', function () {
    Route::get('/profile', [app\controller\api\MerchantApiController::class, 'getProfile']);
    Route::post('/reset_key', [app\controller\api\MerchantApiController::class, 'resetKey']);
    Route::post('/buy_vip', [app\controller\api\MerchantApiController::class, 'buyVip']);
    Route::get('/channel/list', [app\controller\api\MerchantChannelController::class, 'list']);
    Route::post('/channel/save', [app\controller\api\MerchantChannelController::class, 'save']);
    Route::post('/channel/toggle', [app\controller\api\MerchantChannelController::class, 'toggle']);
    Route::post('/channel/delete', [app\controller\api\MerchantChannelController::class, 'delete']);
    Route::get('/channel/drivers', [app\controller\api\MerchantChannelController::class, 'drivers']);
    Route::post('/recharge/create', [app\controller\api\MerchantRechargeController::class, 'create']);
})->middleware([app\middleware\MerchantAuthMiddleware::class]);

// 管理员后台与插件商城 API
Route::group('/api/admin', function () {
    Route::any('/dashboard', [app\controller\admin\AdminController::class, 'dashboard']);
    Route::get('/channel/get', [app\controller\admin\AdminController::class, 'getChannelConfig']);
    Route::get('/channel/inputs', [app\controller\admin\ChannelAdminController::class, 'getConfigInputs']);
    Route::post('/channel/config/save', [app\controller\admin\AdminController::class, 'saveChannelConfig']);
    Route::post('/merchant/save', [app\controller\admin\AdminController::class, 'saveMerchant']);
    Route::post('/order/force_notify', [app\controller\admin\AdminController::class, 'forceNotifyOrder']);
    Route::post('/template/save', [app\controller\admin\AdminController::class, 'saveTemplate']);
    
    // 订单高级检索与人工补单 API
    Route::get('/order/list', [app\controller\admin\OrderAdminController::class, 'list']);
    Route::post('/order/close', [app\controller\admin\OrderAdminController::class, 'close']);
    Route::post('/order/manual_pay', [app\controller\admin\CallbillAdminController::class, 'manualPay']);

    // 插件商城 API
    Route::get('/plugin/market_list', [app\controller\admin\PluginMarketController::class, 'getMarketList']);
    Route::post('/plugin/install', [app\controller\admin\PluginMarketController::class, 'installPlugin']);

    // 轮询组 API
    Route::get('/poll_group/list', [app\controller\admin\PollGroupController::class, 'list']);
    Route::post('/poll_group/save', [app\controller\admin\PollGroupController::class, 'save']);
    Route::post('/poll_group/bind', [app\controller\admin\PollGroupController::class, 'bindChannel']);

    // VIP 套餐 API
    Route::get('/packvip/list', [app\controller\admin\PackvipAdminController::class, 'list']);
    Route::post('/packvip/save', [app\controller\admin\PackvipAdminController::class, 'save']);
})->middleware([app\middleware\AdminAuthMiddleware::class]);

// 商户开放 API (带签名验证中间件)
Route::group('/api', function () {
    Route::any('/order/query', [app\controller\api\OrderController::class, 'query']);
})->middleware([app\middleware\ApiAuthMiddleware::class]);
