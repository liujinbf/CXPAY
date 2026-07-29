# CXPAY 生产部署指南

## 推荐架构

```text
客户端 -> HTTPS Nginx -> Webman:8787 -> MySQL
                                  \-> Redis
```

Webman 负责动态路由、静态文件、Session、中间件和后台定时进程。不要使用 PHP-FPM 执行 `public/index.php`，否则无法获得正确的常驻进程行为。

## 部署步骤

1. 安装 PHP 8.1+、Composer、MySQL、Redis，并确认所需扩展齐全。
2. 执行 `composer install --no-dev --optimize-autoloader`。
3. 从 `.env.example` 创建 `.env`，配置 `APP_URL`、数据库和 Redis。`APP_KEY` 必须是至少 32 位的随机值；若使用浏览器安装器，可让安装器首次生成。
4. 执行 `php start.php start`，访问 `/install` 完成初始化。
5. 验证后执行 `php start.php stop`，再以 `php start.php start -d` 常驻运行。
6. 配置进程守护（systemd、Supervisor 或容器重启策略）并启用 HTTPS。

## 网页安装器（推荐全新部署使用）

当 Webman 已启动、Nginx 已反向代理到 `127.0.0.1:8787` 后，访问 `https://你的域名/install`。

安装器会自动检查 PHP 版本、必要扩展、Composer 依赖、运行目录权限和 Webman 运行模式；只有所有检查通过才能继续。填写站点公开地址、MySQL 账号和管理员强密码后，安装器会：

- 尝试创建目标数据库，并在导入前确认它是专用空库；发现已有表时会停止，不会覆盖旧数据。
- 导入 `database/install.sql`、生成随机 `APP_KEY` 与 Token 盐、保存管理员密码哈希。
- 写入 `.env`，默认将 Webman 绑定至 `127.0.0.1:8787`，防止业务端口直接暴露公网。
- 创建 `install.lock`，安装完成后关闭安装入口。

网页安装器不会也不应尝试以网站进程权限修改 Nginx、systemd、宝塔防火墙或服务器账户。首次访问前仍需由服务器管理员完成 Webman 服务和 Nginx 反向代理配置；安装完成页面会给出服务重启命令。

已有旧数据库时不要重新执行 `install.sql`。应先完整备份，再按 README 的版本顺序执行尚未应用的 `database/patch_v*.sql`；补丁不可重复执行，执行 `v3` 前必须先清理同一商户下重复的 `out_trade_no`。

## Nginx 示例

```nginx
server {
    listen 443 ssl http2;
    server_name pay.example.com;

    ssl_certificate /etc/letsencrypt/live/pay.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pay.example.com/privkey.pem;

    client_max_body_size 6m;

    location / {
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_http_version 1.1;
        proxy_pass http://127.0.0.1:8787;
    }
}
```

`APP_URL` 应填写最终 HTTPS 地址，支付上游回调地址由它生成。不要仅依赖客户端传入的 Host 头。

## 上线前检查

- `APP_DEBUG=false`、`SYSTEM_UPDATE_ENABLED=false`。
- 数据库与 Redis 不暴露公网，Redis 已设置密码。
- `APP_KEY`、管理员密码、商户密钥均为独立随机值，且未提交到 Git。
- `/install` 已被安装锁保护。
- 数据库补丁版本已核对，`cx_order` 已具备手续费与支付初始化状态字段。
- 至少完成一次真实测试环境下单、上游回调、手续费结算和商户异步通知。
- 商户回调域名可解析到公网地址；默认会拒绝环回、内网和保留地址。确需内网联调时才设置 `ALLOW_PRIVATE_CALLBACKS=true`。
- 对 `runtime/logs`、MySQL 和安装锁所在卷建立备份与监控。

## Docker Compose

在 `.env` 设置 `APP_KEY`、`DB_PASSWORD`、`MYSQL_ROOT_PASSWORD`、`REDIS_PASSWORD` 后运行：

```bash
docker compose up -d --build
docker compose logs -f cxpay-app
```

应用安装锁位于持久卷 `/app/storage/install.lock`。数据库和 Redis 数据分别存放在独立命名卷中。

## 健康与故障排查

```bash
php -v
php -m
composer validate --strict
composer audit
php start.php status
```

若下单返回“暂无可用通道”，依次检查通道是否启用、是否在线、驱动是否可用、金额上下限/日限额及配置校验。助手型通道还需要持续发送签名心跳。
