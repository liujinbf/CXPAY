<?php

declare(strict_types=1);

// 禁止 PHP-FPM 单入口运行，以免绕过 Webman 路由、中间件、会话和定时进程。
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "CXPAY 不支持 PHP-FPM 单入口模式。请启动 Webman 并通过 Nginx 反向代理。\n";
