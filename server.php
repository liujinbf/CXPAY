<?php
// 本地开发模拟运行服务：免外部服务一键启动收银台 API 服务器
// 监听端口：8787

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

if (str_contains($requestUri, 'submit.php') || str_contains($requestUri, 'mapi.php')) {
    $amount = $_GET['money'] ?? $_POST['money'] ?? '1.00';
    $subject = $_GET['name'] ?? $_POST['name'] ?? 'CXPAY 极速测试订单';
    $tradeNo = 'CX' . date('YmdHis') . sprintf('%04d', mt_rand(1, 9999));

    $html = file_get_contents(__DIR__ . '/public/cashier/index.html');
    
    // 替换为动态下单数据
    $html = str_replace(
        ['"1.00"', '"测试应用 - VIP会员购买"', '"CX" + Date.now()'],
        ['"' . number_format((float)$amount, 2, '.', '') . '"', '"' . addslashes($subject) . '"', '"' . $tradeNo . '"'],
        $html
    );
    
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// 静态文件路由
$filePath = __DIR__ . '/public' . $requestUri;
if (file_exists($filePath) && !is_dir($filePath)) {
    return false;
}

// 默认主页重定向到下单演示
header('Location: /submit.php?pid=1000&type=alipay&money=99.00&name=VIP高级订阅套餐&out_trade_no=' . time());
