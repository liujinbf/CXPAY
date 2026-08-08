<?php

declare(strict_types=1);

use WxpayClerk\AccountRepository;
use WxpayClerk\ApiApplication;
use WxpayClerk\AuthSessionManager;
use WxpayClerk\AuthSessionRepository;
use WxpayClerk\Database;
use WxpayClerk\GeweApiClient;
use WxpayClerk\NonceRepository;
use WxpayClerk\OrderMatcher;
use WxpayClerk\OrderRepository;
use WxpayClerk\OutboxRepository;
use WxpayClerk\PaymentEventRepository;
use WxpayClerk\PaymentMatchingService;
use WxpayClerk\PaymentNotificationParser;
use WxpayClerk\RequestAuthenticator;
use WxpayClerk\ReviewRepository;
use WxpayClerk\SignatureHelper;
use WxpayClerk\WechatWebhookHandler;

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WxpayClerk\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '服务未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configFile;

try {
    $database = new Database((string) ($config['sqlite_path'] ?? __DIR__ . '/storage/clerk.sqlite'));
    $accounts = new AccountRepository($database->pdo());
    $sessions = new AuthSessionRepository($database->pdo());
    $orders = new OrderRepository($database->pdo());
    $events = new PaymentEventRepository($database->pdo());
    $reviews = new ReviewRepository($database->pdo());
    $outbox = new OutboxRepository($database->pdo());
    $gewe = new GeweApiClient(
        (string) ($config['gewe_api_url'] ?? 'http://127.0.0.1:2531'),
        (string) ($config['gewe_api_token'] ?? '')
    );
    $matching = new PaymentMatchingService(
        $database,
        $orders,
        $events,
        $reviews,
        $outbox,
        new OrderMatcher(),
        (int) ($config['match_window_seconds'] ?? 600)
    );
    $webhookToken = (string) ($config['webhook_token'] ?? '');
    $webhookUrl = rtrim((string) ($config['base_url'] ?? ''), '/')
        . '/wechat/message/' . rawurlencode($webhookToken);
    $authSessions = new AuthSessionManager(
        $gewe,
        $sessions,
        $accounts,
        $webhookUrl,
        (int) ($config['session_ttl'] ?? 300)
    );
    $webhook = new WechatWebhookHandler(
        new PaymentNotificationParser(),
        $matching,
        $accounts,
        (string) ($config['log_file'] ?? __DIR__ . '/storage/logs/clerk.log')
    );
    $signer = new SignatureHelper(
        (string) ($config['client_id'] ?? ''),
        (string) ($config['client_secret'] ?? ''),
        (string) ($config['callback_secret'] ?? '')
    );
    $application = new ApiApplication(
        new RequestAuthenticator(
            (string) ($config['client_id'] ?? ''),
            (string) ($config['client_secret'] ?? ''),
            new NonceRepository($database->pdo())
        ),
        $signer,
        $orders,
        $events,
        $outbox,
        $reviews,
        $accounts,
        $matching,
        $authSessions,
        $gewe,
        $webhook,
        $webhookToken,
        array_values(array_filter((array) ($config['gewe_allowed_ips'] ?? []), 'is_string')),
        array_values(array_filter((array) ($config['allowed_ips'] ?? []), 'is_string'))
    );

    $headers = function_exists('getallheaders') ? (array) getallheaders() : [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace('_', '-', substr($key, 5));
            $headers[$name] = (string) $value;
        }
    }
    $response = $application->handle(
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        $headers,
        (string) file_get_contents('php://input'),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        time()
    );
} catch (Throwable) {
    $response = WxpayClerk\HttpResponse::json(['error' => '店员服务未就绪'], 503);
}

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
