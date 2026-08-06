<?php

declare(strict_types=1);

/**
 * 支付宝账单通道自动配置代理服务 - 入口文件
 *
 * 路由表：
 *   POST /v1/autoconfig-sessions       - CXPAY 调用，创建配置会话
 *   GET  /v1/autoconfig-sessions/{id}  - CXPAY 轮询，查询会话状态
 *   GET  /guide                        - 商户操作引导页面
 *   POST /guide/verify                 - 商户提交凭证验证
 *   GET  /health                       - 服务健康检查
 *
 * Nginx 配置示例（需要将所有请求重写到此入口）：
 *   location / { try_files $uri /index.php$is_args$args; }
 */

// ─── 自动加载 ──────────────────────────────────────────────────────────────────
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // 未安装 composer 依赖时手动加载核心类
    foreach (['SessionStore', 'SignatureHelper', 'AlipayApiClient', 'AutoConfigController'] as $cls) {
        require_once __DIR__ . '/src/' . $cls . '.php';
    }
}

use AlipayAutoConfig\AlipayApiClient;
use AlipayAutoConfig\AutoConfigController;
use AlipayAutoConfig\SessionStore;
use AlipayAutoConfig\SignatureHelper;

// ─── 加载配置 ──────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => '服务未配置，请先复制 config.example.php 为 config.php 并填写配置'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configFile;

// ─── IP 白名单校验（仅对 API 端点，引导页面不限制）──────────────────────────────
$requestUri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isApiEndpoint = str_starts_with((string)$requestUri, '/v1/');

$allowedIps = array_filter((array)($config['allowed_ips'] ?? []));
if ($isApiEndpoint && $allowedIps !== []) {
    $remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $remoteIp = trim(explode(',', $remoteIp)[0]);
    if (!in_array($remoteIp, $allowedIps, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'IP 不在白名单中'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── 初始化核心对象 ────────────────────────────────────────────────────────────
$signer  = new SignatureHelper(
    (string)($config['client_id']      ?? ''),
    (string)($config['client_secret']  ?? ''),
    (string)($config['callback_secret']?? '')
);
$store  = new SessionStore(
    (string)($config['session_dir'] ?? __DIR__ . '/storage/sessions'),
    (int)($config['session_ttl'] ?? 1200)
);
$alipay = new AlipayApiClient();
$ctrl   = new AutoConfigController($store, $signer, $alipay, rtrim((string)($config['base_url'] ?? ''), '/'));

// ─── 路由分发 ──────────────────────────────────────────────────────────────────
$path = (string)($requestUri ?? '/');

// 健康检查（无需签名）
if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'ts' => time()]);
    exit;
}

// 商户引导页面（无需签名）
if ($path === '/guide' && $requestMethod === 'GET') {
    $sessionId = trim((string)($_GET['session'] ?? ''));
    if ($sessionId === '') {
        http_response_code(400);
        echo '缺少 session 参数';
        exit;
    }
    $ctrl->showGuidePage($sessionId);
}

if ($path === '/guide/verify' && $requestMethod === 'POST') {
    $ctrl->verifyCredentials();
}

// ─── API 端点（须 CXPAY 签名） ────────────────────────────────────────────────
if ($isApiEndpoint) {
    // 读取原始请求体
    $rawBody = (string)file_get_contents('php://input');

    // 提取请求头
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_X_CXPAY_')) {
            $headerName = 'X-CXPAY-' . ucwords(strtolower(substr($key, 13)), '_');
            $headerName = str_replace('_', '-', $headerName);
            $headers[$headerName] = (string)$value;
        }
    }

    // 签名验证
    if (!$signer->verifyRequestHeaders($requestMethod, $path, $rawBody, $headers)) {
        $signer->sendJson(['error' => '签名验证失败'], 401);
    }

    // 路由匹配
    if ($path === '/v1/autoconfig-sessions' && $requestMethod === 'POST') {
        $ctrl->createSession($rawBody);
    }

    if (preg_match('#^/v1/autoconfig-sessions/([a-f0-9]{40})$#', $path, $matches) && $requestMethod === 'GET') {
        $ctrl->pollSession($matches[1]);
    }

    $signer->sendJson(['error' => '路由不存在'], 404);
}

// 未匹配任何路由
http_response_code(404);
echo '404 Not Found';
