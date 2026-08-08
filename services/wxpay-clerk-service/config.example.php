<?php

declare(strict_types=1);

/**
 * 微信店员服务配置模板。
 * 生产环境优先通过环境变量注入密钥，不要把 config.php 提交到仓库。
 */

$csv = static function (string $name, string $default = ''): array {
    $value = getenv($name);
    $raw = $value !== false ? $value : $default;
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
};

return [
    // CXPAY 插件与本服务之间的 HMAC 身份。
    'client_id' => getenv('WXCLERK_CLIENT_ID') ?: 'your-client-id',
    'client_secret' => getenv('WXCLERK_CLIENT_SECRET') ?: '',
    'callback_secret' => getenv('WXCLERK_CALLBACK_SECRET') ?: '',

    // 至少 32 位随机路径令牌；Gewe 回调路径为 /wechat/message/{token}。
    'webhook_token' => getenv('WXCLERK_WEBHOOK_TOKEN') ?: '',

    // 本服务公网 HTTPS 地址和 CXPAY 公网 HTTPS 地址。
    'base_url' => getenv('WXCLERK_BASE_URL') ?: 'https://clerk.example.com',
    'cxpay_notify_url' => getenv('CXPAY_NOTIFY_URL') ?: 'https://pay.example.com',

    // Gewe 位于受控内网，可使用 HTTP；Token 不得写入日志。
    'gewe_api_url' => getenv('GEWE_API_URL') ?: 'http://127.0.0.1:2531',
    'gewe_api_token' => getenv('GEWE_API_TOKEN') ?: '',

    'sqlite_path' => getenv('WXCLERK_SQLITE_PATH') ?: __DIR__ . '/storage/clerk.sqlite',
    'match_window_seconds' => (int) (getenv('WXCLERK_MATCH_WINDOW') ?: 600),
    'session_ttl' => (int) (getenv('WXCLERK_SESSION_TTL') ?: 300),
    'outbox_max_attempts' => (int) (getenv('WXCLERK_OUTBOX_MAX_ATTEMPTS') ?: 12),
    'outbox_lease_seconds' => (int) (getenv('WXCLERK_OUTBOX_LEASE_SECONDS') ?: 60),

    // API 白名单可留空并依赖 HMAC；Webhook 白名单不得为空。
    'allowed_ips' => $csv('WXCLERK_ALLOWED_IPS'),
    'gewe_allowed_ips' => $csv('WXCLERK_GEWE_ALLOWED_IPS', '127.0.0.1'),

    'log_file' => getenv('WXCLERK_LOG_FILE') ?: __DIR__ . '/storage/logs/clerk.log',
];
