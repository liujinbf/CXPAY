<?php

declare(strict_types=1);

// Webman 是常驻进程框架，不能由 PHP-FPM/CGI 按请求加载。
// 保留此文件只为给旧部署方式提供明确提示，避免绕过 Webman 中间件和安全配置。
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "CXPAY 必须通过 Webman 常驻进程运行。\n";
echo "请执行 php start.php start，并将 Nginx 反向代理到 127.0.0.1:8787。\n";
