# CXPAY 聚合支付网关

CXPAY 是基于 PHP 8.1、Webman 2、MySQL 和 Redis 的个人收款码监控网关。项目聚焦支付宝、微信和 QQ 钱包个人收款码，通过安卓或 PC 监控端、可信外部账单服务完成到账确认，并向下游商户提供易支付兼容的下单与通知协议。

## 当前能力边界

已可用：

- 易支付风格的 `/submit.php`、`/mapi.php` 下单入口与 MD5 参数签名。
- 商户隔离的通道管理、订单查询、余额充值单和后台人工补单。
- 支付宝、微信、QQ 钱包个人固定收款码，以及安卓/PC 监控端安全账单上报。
- 支付宝、微信、QQ 外部账单服务回调型个人码驱动；外部服务必须配置共享鉴权凭据。
- 授权账单源（BillSource）双端架构：采集端通过 `POST /api/bill-source/ingest` 写入账单，PC 监控端通过 `GET /api/bill-source/poll` 按单调游标拉取，两端密钥相互隔离并支持在线轮换。
- 插件商城与动态驱动注册：管理员可通过后台安装、启停和卸载支付驱动插件，插件驱动在运行时动态注册到 PaymentManager。
- 订单幂等、手续费预占/核销/释放、支付出码并发认领、通道金额校验、回调重试和 SSRF 防护。
- 管理员与商户 Session 登录、登录限流、同源写操作校验。
- 商户后台登录密码与支付 API 密钥相互独立；管理员创建商户时只返回一次初始凭据。
- Docker Compose 部署及浏览器安装向导。

尚未完成或默认停用：

- 项目不接入支付宝、微信官方商户支付，也不把第三方易支付上游作为收款通道；相关驱动源码仅作历史兼容并统一标记为不可用。
- 微信/QQ/支付宝“协议云端扫码登录”不在支付节点提供；旧路由已移除，墓碑控制器统一返回 HTTP 410 迁移状态，对应驱动仅适用于已经具备外部账单推送服务的部署者。
- VIP 在线购买、授权、源码包和更新包属于独立云端控制面；支付节点的在线更新与在线回滚因缺少可信发布包签名和原子部署链路而明确禁用。
- 旧版轮询组表尚未接入当前通道调度器，管理写接口已禁用；现有调度直接使用通道权重、金额区间和日限额。
- 旧版 VIP 套餐表仅保留历史数据读取；购买、续期和套餐费率应用未形成闭环，套餐写接口已禁用。
- 前端样式及二维码解析脚本来自公共 CDN；严格离线环境需要自行托管这些静态依赖。

个人收款码、通知监听和非官方账单接口可能受到支付机构规则、账号风控和当地法规限制。生产部署前应自行完成合规评估，并准备监控端掉线、通知格式变化和人工复核方案。

## 支付节点与云端控制面边界

- CXPAY 是独立部署的支付系统，只承载支付、商户、通道、订单和本地插件生命周期，不承载官方云端控制面。
- `/cloud` 只跳转到 `CLOUD_PORTAL_URL` 指定的独立工作台；云端账号、授权、商品、订单和资产使用独立服务及数据存储。
- 被授权用户在云端工作台购买并下载源码包和系统更新包，支付节点不能提供这些资产。
- 支付插件的购买和续费在云端工作台完成；插件文件只能由已激活的 CXPAY 实例通过本地插件商城下载和更新，不能从云端网页直接下载。
- M0 已关闭旧域名 + 授权 Key 的插件下载协议；基于 Ed25519 实例身份和一次性下载凭证的协议见 `docs/contracts/cloud-control-plane-instance-v1.md`，将在后续里程碑实现。

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
