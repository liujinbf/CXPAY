# CXPAY 部署与上线测试手册 (Deployment & Production Guide)

本文档指导如何在单机低配置服务器 (如 2核2G) 上部署 **CXPAY** 系统并跑通测试。

---

## 一、 服务器环境要求

1. **操作系统**: Linux (Ubuntu 20.04+ / Debian 11+ / CentOS 7+) 或 Windows Server 2019+
2. **PHP 版本**: PHP ≥ 8.1 (需安装扩展: `ext-json`, `ext-pdo`, `ext-redis`, `ext-bcmath`, `ext-openssl`)
3. **数据库**: MySQL ≥ 5.7 / 8.0
4. **高速缓存**: Redis ≥ 5.0
5. **Web 服务器**: Nginx (反向代理 Webman)

---

## 二、 部署步骤

### 1. 导入数据库
将 `database/install.sql` 导入 MySQL 数据库：
```bash
mysql -u root -p cxpay < database/install.sql
```

### 2. 配置环境变量与连接
在 `.env` 或 `config/database.php` / `config/redis.php` 中配置数据库与 Redis 连接参数。

### 3. Nginx 反向代理配置
Webman 默认在 `8787` 端口上监听，使用 Nginx 进行反向代理并配置 SSL 证书：

```nginx
server {
    listen 80;
    server_name pay.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name pay.yourdomain.com;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    location / {
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Host $http_host;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_pass http://127.0.0.1:8787;
    }
}
```

### 4. 启动 Webman 常驻服务
在 `CXPAY/` 根目录下执行启动命令：

```bash
# 调试模式启动 (实时查看控制台输出)
php start.php start

# 生产环境后台常驻启动
php start.php start -d

# 停止服务 / 重启服务
php start.php stop
php start.php restart
```

---

## 三、 上线测试验证流程

### 1. 基础下单链路验证
- **接口地址**: `https://pay.yourdomain.com/submit.php`
- **请求类型**: `GET / POST`
- **必传参数**: `pid` (商户ID), `type` (`alipay`/`wxpay`/`qqpay`), `out_trade_no`, `money`, `notify_url`, `sign`
- **预期结果**: 系统通过 `PollService` 匹配通道，自动调用对应驱动并返回调起支付的跳转链接或二维码。

### 2. 挂机助手推送测试
- **接口地址**: `https://pay.yourdomain.com/api/appasst/push`
- **请求类型**: `POST`
- **参数**: `app_name=alipay_asst&device_id=PHONE001&money=1.00&remark=订单号`
- **预期结果**: 系统写入 `cx_callbill` 表，自动检索处于“待支付”的订单并标记支付成功。

### 3. 高并发压测 (Benchmark)
在开发/测试服务器使用 `wrk` 工具进行 QPS 压力测试：
```bash
wrk -t4 -c200 -d15s https://pay.yourdomain.com/submit.php
```
*在 2核2G 低配服务器上，常规 QPS 吞吐预期达到 2000~4000+，内存占用稳定保持在 50MB 左右。*
