<?php

declare(strict_types=1);

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/api/merchant/')) {
    header('Content-Type: application/json; charset=utf-8');

    $data = match ($path) {
        '/api/merchant/profile' => [
            'pid' => 10001,
            'name' => '浏览器测试商户',
            'money' => '100.00',
            'rate' => 0.02,
            'key' => 'fixture-secret-key',
            'gateway_url' => 'http://127.0.0.1/submit.php',
            'mapi_url' => 'http://127.0.0.1/mapi.php',
            'site_url' => 'http://127.0.0.1/',
        ],
        '/api/merchant/dashboard' => [
            'today_amount' => '0.00',
            'today_count' => 0,
            'running_channel_count' => 0,
            'today_success_rate' => 100,
            'money' => '100.00',
            'rate' => 0.02,
            'plan_name' => '默认基础套餐',
            'plan_expire_format' => '无到期限制',
        ],
        '/api/merchant/plan/list' => [
            'merchant_money' => '100.00',
            'plan_fee_discount_balance' => '0.00',
            'current_plan_id' => 1,
            'current_plan_name' => '默认基础套餐',
            'plan_expire_time' => time() + 86400,
            'plan_expire_format' => '无到期限制',
            'list' => [[
                'id' => 2,
                'name' => '浏览器测试套餐',
                'memo' => '用于验证购买请求契约',
                'price' => '9.90',
                'days' => 30,
                'rate' => '0.20',
                'channel_quota' => 5,
                'limit_count' => 2,
                'bought_count' => 0,
                'is_current' => false,
                'can_buy' => true,
            ]],
        ],
        '/api/merchant/order/list' => [[
            'trade_no' => 'CX202608090001',
            'out_trade_no' => 'MERCHANT-001',
            'subject' => '浏览器测试订单',
            'pay_type' => 'wxpay',
            'amount' => '10.00',
            'price' => '10.01',
            'status' => 1,
            'create_time' => '2026-08-09 18:00:00',
            'pay_time' => '2026-08-09 18:00:05',
        ]],
        '/api/merchant/finance_log' => [[
            'id' => 9,
            'create_time' => '2026-08-09 18:00:05',
            'money' => '-0.02',
            'before' => '100.00',
            'after' => '99.98',
            'memo' => '测试订单服务费',
        ]],
        '/api/merchant/alert/config' => [
            'enabled' => true,
            'low_balance_threshold' => 20,
            'events' => [
                'merchant_login' => true,
                'order_paid' => true,
                'channel_offline' => true,
                'low_balance' => true,
            ],
            'email_config' => [
                'enabled' => true,
                'to_addrs' => ['fixture@example.test'],
            ],
            'wxwork_config' => ['enabled' => false, 'webhook_url' => ''],
            'webhook_config' => ['enabled' => false, 'url' => ''],
        ],
        '/api/merchant/channel/list' => [[
            'id' => 7,
            'title' => '微信店员测试通道',
            'pay_category' => 'wxpay',
            'c_type' => 'wxpay_recpt_afk_pc',
            'remark' => 'Playwright 冒烟测试',
            'status' => 1,
            'online_status' => 1,
            'today_money' => '12.34',
            'today_count' => 2,
            'total_money' => '123.45',
            'config' => ['qr_url' => 'wxp://fixture'],
            'configured' => ['account_cookie' => true],
            'supports_account_authorization' => true,
            'authorization_label' => '微信扫码授权',
            'supports_account_capability_detection' => true,
        ]],
        '/api/merchant/channel/drivers' => [
            'wxpay' => [[
                'c_type' => 'wxpay_recpt_afk_pc',
                'name' => '微信店员模式（PC）',
                'inputs' => [
                    ['name' => 'account_cookie', 'title' => '授权 Cookie', 'type' => 'textarea', 'required' => true],
                    ['name' => 'qr_url', 'title' => '收款码地址', 'type' => 'text', 'required' => false],
                ],
            ]],
            'alipay' => [[
                'c_type' => 'alipay_personal',
                'name' => '支付宝个人码',
                'inputs' => [[
                    'name' => 'qr_url', 'title' => '收款码地址', 'type' => 'text', 'required' => true,
                ]],
            ]],
            'qqpay' => [],
        ],
        '/api/merchant/channel/capabilities' => [
            'status' => 'RECEIPT_AVAILABLE',
            'message' => '测试账号已开通收款单功能',
        ],
        '/api/merchant/channel/authorization/start' => [
            'session_id' => 'fixture-auth-session',
        ],
        '/api/merchant/channel/authorization/poll' => [
            'status' => 'WAITING',
            'qr_url' => 'https://fixture.example/authorize',
            'expires_at' => time() + 300,
        ],
        '/api/merchant/bill-source/status' => [
            'configured' => true,
            'collector_id' => 'ANDROID_PHONE_01',
            'ingest_ip_white' => '',
        ],
        '/api/merchant/bill-source/rotate-token' => [
            'token' => 'fixture-' . ($_POST['scope'] ?? 'unknown') . '-token',
        ],
        '/api/merchant/channel/save',
        '/api/merchant/channel/toggle',
        '/api/merchant/channel/delete',
        '/api/merchant/plan/buy',
        '/api/merchant/alert/config/save',
        '/api/merchant/alert/test' => [],
        default => [],
    };

    echo json_encode([
        'code' => 1,
        'msg' => '浏览器冒烟测试模拟响应',
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return true;
}

$public = dirname(__DIR__, 2) . '/public';
$publicRoot = realpath($public);
$candidate = realpath($public . $path);
if (
    $publicRoot !== false
    && $candidate !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    return false;
}

http_response_code(404);
echo 'Not Found';

return true;
