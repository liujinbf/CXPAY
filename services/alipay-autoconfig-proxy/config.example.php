<?php

/**
 * 支付宝账单通道自动配置代理服务 - 配置文件
 *
 * 将此文件复制为 config.php 并填写以下配置项后方可运行。
 */

return [

    // ─── 服务自身安全 ──────────────────────────────────────────────────────────
    // CXPAY 调用本代理服务时使用的 Client ID 和 HMAC 密钥
    // 与 alipay-accountlog-monitor 插件配置中的 autoconfig_client_id / autoconfig_client_secret 一一对应
    'client_id'     => 'your-client-id',
    'client_secret' => 'your-client-secret-min-32-chars-here',

    // 本服务对 CXPAY 响应的回调签名密钥（CXPAY 插件侧的 autoconfig_callback_secret）
    'callback_secret' => 'your-callback-secret-min-32-chars-here',

    // ─── 会话存储 ──────────────────────────────────────────────────────────────
    // 会话文件存储目录（须可写，不能在 Web 根目录下暴露）
    'session_dir'     => __DIR__ . '/storage/sessions',
    // 会话过期时间（秒），默认 20 分钟
    'session_ttl'     => 1200,

    // ─── 本服务对外访问地址（用于生成引导页面 URL）──────────────────────────────
    'base_url' => 'https://your-proxy-service-domain.example.com',

    // ─── 支付宝账单 API 配置（用于验证商户填写的 AppID 是否有效） ────────────────
    // 验证时调用 alipay.data.bill.accountlog.query 测试接口权限
    'alipay_gateway' => 'https://openapi.alipay.com/gateway.do',

    // ─── 可信 CXPAY 实例 IP 列表（留空则不限制来源 IP）────────────────────────
    // 建议生产环境填写 CXPAY 服务器的公网 IP
    'allowed_ips' => [],

    // ─── 日志 ──────────────────────────────────────────────────────────────────
    'log_file' => __DIR__ . '/storage/logs/proxy.log',
];
