# CXPAY 聚合支付系统 (Runtime + Cloud 双层架构)

CXPAY 是基于 PHP 8.1+、Webman 2、MySQL 和 Redis 构建的高性能聚合支付网关与分布式商业控制面系统。

系统严格采用 **「本地轻量支付实例 (CXPAY Runtime) + 官方独立云端控制面 (CXPAY Cloud)」** 双层解耦架构：

---

## 🏛️ 双层体系架构与职责分工

```
┌─────────────────────────────────────────────────────────────┐
│              官方云端控制面 (https://cloud.fcwan.cn)          │
│  ├─ 👑 OEM 代理商租户工作台 (Agent Portal)                   │
│  │   ├─ 多租户 RBAC 权限矩阵 (OWNER / ADMIN / FINANCE / OP)  │
│  │   ├─ 双分录充值与财务账本 (cloud_ledger_*)                │
│  │   ├─ 客户站点商业授权一键下发与免费域名改绑              │
│  │   └─ 全网支付通道插件批发底价代开与划拨                  │
│  ├─ 🛡️ 官方大盘与运营控制台 (Ops Platform)                  │
│  └─ 📦 RSA-SHA256 数字签名插件包发布与动态水证据溯源          │
└──────────────────────────────┬──────────────────────────────┘
                               │ HTTPS / Ed25519 签名鉴权
                               ▼
┌─────────────────────────────────────────────────────────────┐
│          主站支付节点 (CXPAY Runtime — 纯净聚合支付网关)     │
│  ├─ 🚀 核心通道出码与高并发收单 (当面付/免挂助手/企业微信/USDT) │
│  ├─ 📊 商户中心与订单对账结算 (Merchant Center)             │
│  ├─ 🧩 官方插件沙箱热加载与 RSA 公钥验签 (Plugin Sandbox)    │
│  └─ 🎯 轮询组智能调度与授权账单源监控 (BillSource)          │
└─────────────────────────────────────────────────────────────┘
```

### 1. 支付节点（CXPAY Runtime）定位：
- **纯净轻量化网关**：主站不承载任何代理商控制面逻辑，不内置任何核心支付通道驱动源码；
- **沙箱热加载**：所有支付通道均作为加密 `.cxpay-plugin` 交付包，通过 `CloudInstanceClient` 从官方云端拉取、经 `PluginPackageInstaller` 公钥验签后在 `runtime/plugins/cxpay/` 中动态热加载；
- **商户端严格继承**：商户端可用通道 100% 来自主站已安装插件，并受商户套餐白名单严格管控。

### 2. 云端控制面（CXPAY Cloud）定位：
- **全网资产与商业授权统一管理**：统一管理站点授权（`cloud_site_licenses`）、插件目录与价格体系；
- **独立 OEM 代理商租户工作台**：代理商直接在云端工作台（`https://cloud.fcwan.cn`）管理名下客户站点、充值预存款、查看双分录借贷账本、并以专属底价（如 0.4 折）划拨付费插件；
- **高等级安全保障**：支持多租户隔离、Argon2id 密码哈希、QQ/微信 OAuth 绑定及 TOTP 双因素动态口令。

---

## ⚡ 当前功能与特性清单

### 1. 支付网关与通道能力
- **易支付 OpenAPI 协议兼容**：支持 `/submit.php`、`/mapi.php` 标准下单与 MD5/RSA 验签。
- **免挂账单与监控体系**：
  - 支付宝当面付 OpenAPI / 个人免挂云端与安卓挂机助手；
  - 企业微信自建应用 Webhook 账单回调；
  - 授权账单源（BillSource）双端架构：`POST /api/bill-source/ingest` 写入，PC 监控端单调游标拉取。
- **轮询组智能调度**：支持多通道权重调度、金额区间匹配、日限额与熔断降级保护。

### 2. 商户中心与财务安全
- 商户专属控制台、密钥自助重置、费率设置与套餐配额控制；
- 订单并发认领防串单、手续费预占/核销/原路冲正事务。

### 3. 企业级运维与安全加固
- **RBAC 子管理员**：支持独立登录与 24h 一次性 Token 邀请激活；
- **审计日志**：全量操作上下文日志记录与 CSV 导出；
- **生产探针**：提供 `/health`、`/health/live`、`/health/ready` 与 `/metrics` Prometheus 端点。

---

## 🔒 支付节点与云端控制面交互规范

- CXPAY 支付节点仅通过 `config/cloud.php` 中配置的 `CLOUD_API_URL`（默认 `https://cloud.fcwan.cn`）与官方云端安全通信；
- 实例身份采用 **Ed25519 密钥对**（保存在 `runtime/instance/identity.json`），所有请求携带规范串数字签名、时间戳防重放与 Nonce；
- 代理商相关功能已全量迁移至独立云端控制面，主站不提供任何代理商发证入口。

## 环境要求

- PHP 8.1 及以上
- PHP 扩展：`pdo_mysql`、`redis`、`bcmath`、`mbstring`、`curl`、`openssl`、`pcntl`（Linux）
- MySQL 5.7+/8.0
- Redis 5+
- Composer 2

---

## 安装部署

### 🆕 场景一：全新安装（新服务器，第一次部署）

**第一步：从 Gitee 拉取源码**

在宝塔终端中执行（将 `你的Token` 替换为 Gitee 个人令牌，`你的域名` 替换为实际目录）：

```bash
git clone https://liujinbf:你的Token@gitee.com/liujinbf/CXPAY.git /www/wwwroot/你的域名
```

> Gitee 个人令牌获取路径：Gitee 网页 → 右上角头像 → 设置 → 私人令牌 → 生成新令牌（勾选 `projects` 权限）

**第二步：一键自动安装**

```bash
cd /www/wwwroot/你的域名 && bash setup.sh
```

按提示依次填写：域名、数据库账号、Redis、管理员账号密码，脚本自动完成所有配置。

**完成后访问：** `https://你的域名/admin_login.html`

---

### 🔄 场景二：更新已部署的站点（服务器已有代码）

```bash
cd /www/wwwroot/cs.fcwan.cn
git pull
php start.php reload
```

> 若 `git pull` 提示需要用户名密码（Gitee 私有仓库），先执行：
> ```bash
> git config credential.helper store
> git pull
> ```
> 输入一次账号和令牌后将永久保存，以后直接 `git pull` 无需再次输入。

---

### 命令行运行（开发者本地调试）

```bash
composer install
cp .env.example .env
php start.php start -d
```


Webman 是常驻内存框架，不支持把 `public/index.php` 配成 PHP-FPM 单入口。生产环境必须启动 `php start.php start -d`（或使用宝塔守护进程），再由 Nginx 反向代理到 Webman 监听端口。

## Docker Compose

先创建 `.env` 并至少设置以下随机强密钥：

```dotenv
APP_URL=https://pay.example.com
APP_KEY=请填写至少32位随机值
DB_PASSWORD=请填写数据库密码
MYSQL_ROOT_PASSWORD=请填写MySQL根密码
REDIS_PASSWORD=请填写Redis密码
```

然后执行：

```bash
docker compose up -d --build
```

MySQL 与 Redis 默认不暴露宿主机端口。首次启动后访问 `/install`，数据库主机填写 `cxpay-mysql`。

## 下单签名

除 `sign`、`sign_type` 外，将所有非空参数按键名升序排列成 `key=value&key=value`，末尾直接拼接商户密钥，再计算小写 MD5。

必填业务参数：`pid`、`type`、`out_trade_no`、`money`、`notify_url`、`sign`。`type` 仅支持 `alipay`、`wxpay`、`qqpay`。同一商户的 `out_trade_no` 唯一；重复提交只有在金额、支付类型和业务类型一致时才返回原订单。

商户登录密码只用于后台 Session 登录，API 密钥只用于支付请求和通知签名，两者不可混用。管理员编辑商户时，登录密码与 API 密钥留空都会保持原值；填写 API 密钥代表主动轮换。商户配置了 IP 白名单后，开放 API 只接受列表中的 IPv4/IPv6 来源地址。

## 挂机助手上报

接口：`POST /api/appasst/push`。

当前接口只接受 v2 协议。每个监控端绑定一个支付宝、微信或 QQ 钱包通道，并传入与通道 `pay_category` 一致的 `pay_type`。账单事件传 `event=bill`，并提供稳定的 `source_bill_id`（同一笔真实到账重试时保持不变）与实际发生时间 `occurred_at`；心跳事件传 `event=heartbeat`，且这两个字段分别为空字符串和 `0`。签名原文为：

```text
version|channel_id|device_id|event|pay_type|money(两位小数)|source_bill_id|occurred_at|timestamp|nonce|client_version
```

使用通道的 `notify_secret` 计算 HMAC-SHA256，该密钥必须为 32～128 位。`device_id` 必须与通道绑定设备完全一致，`pay_type` 必须与通道分类一致；`timestamp` 允许误差 300 秒，`nonce` 为 16～128 位随机字符串且不可重复。服务端以 `(channel_id, source_bill_id)` 做数据库级幂等校验。助手类通道超过 60 秒没有心跳会被标记离线，但不会改变人工启停状态。

授权账单源（BillSource）通道使用三套独立密钥：`ingest_secret`（采集端写入鉴权）、`pull_secret`（PC 监控端拉取鉴权）和上报至助手接口时使用的通道 HMAC 密钥，三者严格隔离，任何接口均不会返回已存储密钥的明文。

## 数据库升级

全新安装使用 `database/install.sql`。旧数据库升级前请先完整备份并核对已执行版本；未执行过补丁的旧库应按 `patch_v1.sql`、`patch_v2.sql`、`patch_v3.sql`、`patch_v4.sql`、`patch_v5.sql`、`patch_v6.sql` 的顺序逐个执行，已经执行过的版本必须跳过，所有补丁都不可重复执行。`v3` 会增加手续费状态、支付出码认领状态和商户订单唯一索引，`v4` 会增加助手账单幂等字段，`v5` 会增加 PC 授权账单源暂存队列，`v6` 会在 `cx_channel` 表中增加 `stats_date` 字段用于跨日统计重置幂等（与 `ChannelMonitorService::resetDailyStats()` 配合使用，防止宕机后日限额永久失效）。如果历史数据存在重复的 `(merchant_id, out_trade_no)`，需先清理重复记录才能执行 `v3`。

执行 `v5` 后可通过 `SHOW TABLES LIKE 'cx_bill_source_event'` 确认 `cx_bill_source_event` 表已成功创建。执行 `v6` 后可通过 `SHOW COLUMNS FROM cx_channel LIKE 'stats_date'` 确认 `stats_date` 字段已成功添加。

## 验证命令

```bash
composer validate --strict
composer audit
vendor/bin/phpunit
php start.php start
```

详细部署方式见 `DEPLOYMENT.md`，驱动开发约定见 `DEVELOPMENT.md`。
