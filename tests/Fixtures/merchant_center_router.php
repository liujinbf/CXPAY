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
            'current_plan_name' => '默认基础套餐',
            'plan_expire_format' => '无到期限制',
            'list' => [],
        ],
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
