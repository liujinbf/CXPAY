# CXPAY 部署与上线测试手册 (Deployment & Production Guide)

本文档指导如何在单机低配置服务器 (如 2核2G) 以及 **宝塔面板 (AaPanel / Pagoda)** 上一键部署 **CXPAY** 系统并跑通测试。

---

## 一、 服务器环境要求

1. **操作系统**: Linux (Ubuntu 20.04+ / Debian 11+ / CentOS 7+) 或 Windows Server 2019+
2. **PHP 版本**: PHP ≥ 8.1 (需安装扩展: `ext-json`, `ext-pdo`, `ext-redis`, `ext-bcmath`, `ext-openssl`)
3. **数据库**: MySQL ≥ 5.7 / 8.0
4. **高速缓存**: Redis ≥ 5.0
5. **Web 服务器**: Nginx (反向代理 Webman 或常规 PHP-FPM)

---

## 二、 宝塔面板 (AaPanel) 极速部署指南 (防 404 避坑)

如果在宝塔面板 (Nginx + PHP) 环境下部署，请**严格按照以下 3 步设置**，防止打开 `/install` 报 404 错误：

### 1. 设置【运行目录】为 `/public` (最关键)
* 在宝塔面板中，打开网站设置 -> **网站目录** -> **运行目录** 选择 **`/public`**。
* 点击 **【保存】**。

### 2. 设置 Nginx 【伪静态】 (解决路由 404)
在宝塔网站设置中，点击左侧 **【伪静态】** 选项卡：
- **方案 A (下拉选择)**：在伪静态下拉菜单中选择 **`thinkphp`** 并保存。
- **方案 B (手动粘贴代码)**：复制以下规则粘贴保存：
  ```nginx
  location / {
      if (!-e $request_filename) {
          rewrite ^/(.*)$ /index.php/$1 last;
      }
  }
  ```

### 3. 打开可视化安装向导 (`/install`)
配置完以上两步后，直接在浏览器中访问：
* `http://您的域名/install`

系统会自动载入一键图形化安装向导，填入 MySQL 数据库信息，自动完成数据库建表与安全锁配置 (`install.lock`)！

---

## 三、 高性能常驻模式部署 (反向代理 Webman)

Webman 默认在 `8787` 端口上监听，在生产环境推荐使用 Nginx 进行反向代理并配置 SSL 证书：

### 1. Nginx 反向代理配置
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

### 2. 启动 Webman 常驻服务
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

## 四、 上线测试验证流程

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
