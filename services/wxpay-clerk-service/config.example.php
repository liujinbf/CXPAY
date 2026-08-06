<?php

/**
 * 微信云端店员服务 — 配置文件
 * 复制为 config.php 并填写以下配置后方可运行。
 */

return [

    // ─── 服务自身安全（与 CXPAY 插件配置一一对应） ──────────────────────────────
    // 对应 wxpay-clerk-adapter 通道配置中的 client_id / client_secret
    'client_id'       => 'your-client-id',
    'client_secret'   => 'your-client-secret-min-32-chars-here-abcd',

    // 对应插件配置中的 callback_secret（本服务向 CXPAY 发送回调时签名用）
    'callback_secret' => 'your-callback-secret-min-32-chars-here-xyz',

    // ─── 本服务对外访问地址 ──────────────────────────────────────────────────────
    'base_url' => 'https://your-clerk-service-domain.example.com',

    // ─── gewe 微信协议服务配置 ───────────────────────────────────────────────────
    // gewe 容器的 HTTP API 地址（本地 Docker 部署通常为 http://gewe:2531 或 http://127.0.0.1:2531）
    'gewe_api_url'   => 'http://127.0.0.1:2531',
    // gewe API Token（gewe 配置文件中设置的 token，留空则不鉴权）
    'gewe_api_token' => '',

    // ─── 数据库 ──────────────────────────────────────────────────────────────────
    'sqlite_path' => __DIR__ . '/storage/clerk.sqlite',

    // ─── 订单匹配参数 ─────────────────────────────────────────────────────────────
    // 订单登记后的最大匹配窗口（秒），超时未匹配的订单将被清理
    'order_ttl'            => 3600,
    // 到账通知距订单创建时间最大允许偏差（秒），防止旧账单误匹配
    'match_window_seconds' => 600,
    // 当一条到账存在多个金额相同的待匹配订单时，是否自动进入人工审核
    // false = 取创建时间最近的一笔自动匹配，true = 全部进入 review_events
    'auto_review_on_ambiguous' => true,

    // ─── 可信 CXPAY 实例 IP 白名单（留空则不限制） ─────────────────────────────
    'allowed_ips' => [],

    // ─── 内部 Webhook 端点 IP 白名单（仅允许 gewe 容器 IP 调用） ─────────────
    // 建议设置为 gewe 容器的内网 IP，防止外部伪造
    'gewe_allowed_ips' => ['127.0.0.1'],

    // ─── 日志 ──────────────────────────────────────────────────────────────────
    'log_file' => __DIR__ . '/storage/logs/clerk.log',

    // ─── 会话过期时间（秒），用于登录扫码超时 ────────────────────────────────────
    'session_ttl' => 300,
];
