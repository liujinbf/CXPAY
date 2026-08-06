<?php

declare(strict_types=1);

/**
 * 微信云端店员服务 — 主入口
 *
 * 路由表：
 *   POST /v1/orders                      CXPAY 登记待匹配订单
 *   POST /v1/auth-sessions               CXPAY 创建微信登录会话
 *   GET  /v1/auth-sessions/{id}          CXPAY 轮询登录状态
 *   GET  /v1/accounts/{id}/capabilities  CXPAY 查询账号在线能力
 *   GET  /v1/ops/status                  CXPAY 全局运维状态
 *   GET  /v1/review/events               CXPAY 待审核到账列表
 *   POST /v1/review/events/{id}/match    CXPAY 手动关联到账
 *   POST /v1/review/events/{id}/ignore   CXPAY 忽略到账事件
 *   POST /wechat/message                 gewe Webhook（内部，限 IP）
 *   GET  /health                         健康检查（无需签名）
 *
 * Nginx 重写示例：
 *   location / { try_files $uri /index.php$is_args$args; }
 */

// ─── 自动加载 ──────────────────────────────────────────────────────────────────
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // 未运行 composer install 时逐类手动加载
    foreach ([
        'SignatureHelper', 'OrderStore', 'PaymentNotificationParser',
        'OrderMatcher', 'CxpayCallback', 'GeweApiClient',
        'AuthSessionManager', 'WechatWebhookHandler', 'ApiServer',
    ] as $cls) {
        require_once __DIR__ . '/src/' . $cls . '.php';
    }
}

use WxpayClerk\ApiServer;
use WxpayClerk\AuthSessionManager;
use WxpayClerk\CxpayCallback;
use WxpayClerk\GeweApiClient;
use WxpayClerk\OrderMatcher;
use WxpayClerk\OrderStore;
use WxpayClerk\PaymentNotificationParser;
use WxpayClerk\SignatureHelper;
use WxpayClerk\WechatWebhookHandler;

// ─── 配置加载 ──────────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => '服务未配置，请先将 config.example.php 复制为 config.php 并填写配置'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configFile;

// ─── 路由解析 ──────────────────────────────────────────────────────────────────
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath   = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$rawBody       = (string)file_get_contents('php://input');
$remoteIp      = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);

// ─── 健康检查（无需签名，无需 IP 验证） ─────────────────────────────────────────
if ($requestPath === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'ts' => time()]);
    exit;
}

// ─── IP 白名单校验（CXPAY API 端点） ────────────────────────────────────────────
$allowedIps = array_filter((array)($config['allowed_ips'] ?? []));
if (str_starts_with($requestPath, '/v1/') && $allowedIps !== []) {
    if (!in_array($remoteIp, $allowedIps, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'IP 未授权'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── gewe Webhook 端点（限 gewe 内网 IP） ──────────────────────────────────────
if ($requestPath === '/wechat/message' && $requestMethod === 'POST') {
    $geweAllowedIps = array_filter((array)($config['gewe_allowed_ips'] ?? []));
    if ($geweAllowedIps !== [] && !in_array($remoteIp, $geweAllowedIps, true)) {
        http_response_code(403);
        echo json_encode(['error' => '来源 IP 不被允许']);
        exit;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => '无效的 JSON']);
        exit;
    }

    // 构建依赖链
    $store    = new OrderStore((string)($config['sqlite_path'] ?? __DIR__ . '/storage/clerk.sqlite'));
    $parser   = new PaymentNotificationParser();
    $matcher  = new OrderMatcher(
        $store,
        (int)($config['match_window_seconds'] ?? 600),
        (bool)($config['auto_review_on_ambiguous'] ?? true)
    );
    $cbSender = new CxpayCallback(
        (string)($config['cxpay_notify_url'] ?? ''),
        (string)($config['callback_secret']  ?? ''),
        (string)($config['client_id']        ?? '')
    );
    $handler = new WechatWebhookHandler(
        $parser, $matcher, $cbSender, $store,
        (string)($config['log_file'] ?? __DIR__ . '/storage/logs/clerk.log')
    );

    $handler->handle($payload);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ─── CXPAY API 端点 ────────────────────────────────────────────────────────────
if (str_starts_with($requestPath, '/v1/')) {
    $store  = new OrderStore((string)($config['sqlite_path'] ?? __DIR__ . '/storage/clerk.sqlite'));
    $signer = new SignatureHelper(
        (string)($config['client_id']       ?? ''),
        (string)($config['client_secret']   ?? ''),
        (string)($config['callback_secret'] ?? '')
    );
    $gewe   = new GeweApiClient(
        (string)($config['gewe_api_url']   ?? 'http://127.0.0.1:2531'),
        (string)($config['gewe_api_token'] ?? '')
    );
    $authMgr = new AuthSessionManager(
        $gewe, $store,
        (string)($config['base_url']    ?? ''),
        (int)($config['session_ttl']    ?? 300)
    );
    $api = new ApiServer($signer, $store, $authMgr, $gewe);
    $api->dispatch($requestMethod, $requestPath, $rawBody);
}

// ─── 未匹配路由 ───────────────────────────────────────────────────────────────
http_response_code(404);
echo '404 Not Found';
