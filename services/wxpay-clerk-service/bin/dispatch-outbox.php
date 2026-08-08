<?php

declare(strict_types=1);

use WxpayClerk\CallbackPayloadSigner;
use WxpayClerk\CurlCallbackTransport;
use WxpayClerk\Database;
use WxpayClerk\OutboxDispatcher;
use WxpayClerk\OutboxRepository;
use WxpayClerk\PublicHttpsUrlGuard;

require dirname(__DIR__) . '/vendor/autoload.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('缺少 wxpay-clerk-service/config.php');
}
$config = require $configFile;
$notifyUrl = trim((string) ($config['cxpay_notify_url'] ?? ''));
if ($notifyUrl === '') {
    throw new RuntimeException('缺少 cxpay_notify_url 配置');
}

$database = new Database((string) $config['sqlite_path']);
$dispatcher = new OutboxDispatcher(
    new OutboxRepository($database->pdo()),
    new CallbackPayloadSigner((string) $config['callback_secret']),
    new CurlCallbackTransport(new PublicHttpsUrlGuard()),
    rtrim($notifyUrl, '/') . '/notify/wxpay_clerk_adapter',
    (int) ($config['outbox_max_attempts'] ?? 12),
    (int) ($config['outbox_lease_seconds'] ?? 60)
);

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}
$runOnce = in_array('--once', $argv, true);
do {
    $processed = $dispatcher->dispatchOne(time());
    if ($runOnce) {
        break;
    }
    if (!$processed) {
        usleep(500_000);
    }
} while ($running);
