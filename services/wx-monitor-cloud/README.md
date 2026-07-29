# WX Monitor Cloud

WX Monitor Cloud 是与 CXPAY 主站分离部署的微信个人收款监控协调服务。它负责管理扫码授权会话、账号能力、待匹配订单、真实到账事件及可靠回调，不包含微信官方商户支付，也不伪造微信登录或账单。

## 当前边界

已经实现：

- CXPAY 客户端与授权采集器双角色 HMAC-SHA256 鉴权；
- 300 秒请求时钟窗口、随机数重放保护；
- AES-256-GCM 敏感密钥加密存储；
- 授权会话状态机；
- 多采集器任务可见性隔离及 `CLAIMED` 原子领取；
- 收款单/小账本能力上报与查询；
- CXPAY 待支付订单登记；
- 账号、金额、时间窗唯一账单匹配；
- 歧义账单转 `REVIEW_REQUIRED`，不自动回调；
- 异常账单查询、人工匹配/忽略及操作审计；
- 客户端/采集器版本化密钥、宽限期轮换和单密钥吊销；
- 按客户端隔离的账号、采集器活动和队列运维指标；
- 回调发件箱、指数退避、进程崩溃租约恢复；
- CXPAY 插件响应和事件签名兼容。

尚未实现：

- 具体微信授权/账单采集器；
- PostgreSQL 支持和完整多节点高可用编排；
- 可视化运维后台；
- 密钥轮换和吊销管理界面。

没有授权采集器时，扫码会话会保持 `WAITING_COLLECTOR`，不会返回虚假二维码。

## 启动要求

- PHP 8.1 或更高；
- OpenSSL、mbstring，以及 PDO SQLite 或 PDO MySQL；
- HTTPS 反向代理；
- 服务端与 CXPAY、采集器均应保持 NTP 时间同步。

独立安装：

```bash
cd services/wx-monitor-cloud
composer install --no-dev --classmap-authoritative
```

生成主密钥：

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

设置环境变量后启动：

```bash
export WXMC_MASTER_KEY="生成的主密钥"
export WXMC_DSN="sqlite:/absolute/path/wx-monitor-cloud.sqlite"
export WXMC_LISTEN="http://127.0.0.1:8787"
php server.php start
```

生产环境应由 Nginx/Caddy 提供 TLS，并只向公网暴露 HTTPS 入口。SQLite 适合单节点或开发环境；正式环境建议使用 MySQL 8.0 及以上版本。

MySQL 配置示例：

```bash
export WXMC_DSN="mysql:host=127.0.0.1;port=3306;dbname=wx_monitor_cloud;charset=utf8mb4"
export WXMC_DB_USER="wx_monitor_cloud"
export WXMC_DB_PASSWORD="使用密钥管理系统注入的数据库密码"
export WXMC_WORKER_COUNT="4"
```

服务启动和 `bin/provision.php` 都会自动执行版本化迁移。迁移通过 `schema_migrations` 记录版本，SQLite 使用即时写事务，MySQL 使用命名咨询锁，防止多个进程同时执行同一迁移。每个 Worker 会建立自己的 PDO 连接；SQLite 强制单 Worker，MySQL 可通过 `WXMC_WORKER_COUNT` 配置 1 至 32 个 Worker。迁移只做幂等建表，不会删除现有 SQLite 数据；上线前仍应备份数据库。

## 创建 CXPAY 客户端

```bash
export WXMC_PROVISION_ROLE="client"
export WXMC_PROVISION_ID="cxpay-site-01"
export WXMC_CALLBACK_URL="https://pay.example.com/notify/wxpay_cloud_adapter"
php bin/provision.php
```

命令只输出一次请求密钥和响应/回调密钥：

- `request_secret` 填入插件 `client_secret`；
- `response_secret` 填入插件 `callback_secret`；
- 客户端 ID 填入插件 `client_id`。

## 创建授权采集器

```bash
export WXMC_PROVISION_ROLE="collector"
export WXMC_PROVISION_ID="authorized-collector-01"
php bin/provision.php
```

采集器必须是用户明确授权且合法的数据来源。服务仅定义采集器协议，不提供绕过微信安全机制的实现。

官方采集器 SDK 和守护进程位于 `agents/wx-collector`。默认适配器不会生成任何数据；合法服务商可以实现 SDK 接口，或者直接使用标准签名 HTTPS 适配器。

## API 流程

```text
CXPAY 创建授权会话
  -> 授权采集器领取会话并提供二维码
  -> 用户扫码
  -> 采集器确认账号并报告能力
  -> CXPAY 保存 account_id
  -> CXPAY 下单时登记金额与有效期
  -> 采集器上报真实到账
  -> 云服务唯一匹配订单
  -> 发件箱签名回调 CXPAY
```

客户端接口：

- `POST /v1/auth-sessions`
- `GET /v1/auth-sessions/{session_id}`
- `GET /v1/accounts/{account_id}/capabilities`
- `POST /v1/orders`
- `GET /v1/review/events`
- `POST /v1/review/events/{event_id}/match`
- `POST /v1/review/events/{event_id}/ignore`
- `GET /v1/ops/status`

采集器接口：

- `GET /v1/collector/auth-sessions/pending`
- `POST /v1/collector/auth-sessions/{session_id}`
- `POST /v1/collector/events`

健康检查：`GET /health`。

## 签名规则

请求规范串：

```text
HTTP_METHOD\n
REQUEST_PATH\n
TIMESTAMP\n
NONCE\n
SHA256(RAW_BODY)
```

客户端使用 `X-CXPAY-*` 请求头，采集器使用 `X-Collector-*` 请求头。签名算法为小写十六进制 HMAC-SHA256。

云服务对客户端响应原始 JSON 体使用 `response_secret` 签名，并通过 `X-CXPAY-Signature` 返回。

## 人工复核

金额无法唯一匹配时，到账事件保持 `REVIEW_REQUIRED`；没有候选订单时保持 `UNMATCHED`。CXPAY 管理后台会从已安装的微信云监控插件拉取这些事件。

人工匹配仍会校验客户端、微信账号、金额、订单待支付状态以及订单有效期是否覆盖真实到账时间。校验通过后云服务原子占用订单、写入 `payment_event_reviews` 审计记录，并把签名回调加入 `callback_outbox`。重复匹配相同订单返回幂等成功，不会重复创建回调。忽略操作必须填写原因，且不会触发支付回调。

## 密钥轮换与吊销

查看某个调用方的密钥元数据（不会输出密钥明文）：

```bash
export WXMC_KEY_ACTION=list
export WXMC_PROVISION_ID=cxpay-site-01
php bin/keys.php
```

轮换 CXPAY 请求密钥：

```bash
export WXMC_KEY_ACTION=rotate
export WXMC_PROVISION_ID=cxpay-site-01
export WXMC_KEY_TYPE=request
export WXMC_KEY_GRACE_SECONDS=3600
export WXMC_KEY_ACTIVATE_AFTER_SECONDS=0
php bin/keys.php
```

命令只输出一次新密钥。云服务立即接受新密钥，并在 3600 秒内继续接受旧密钥；应在宽限期内把插件 `client_secret` 更新为新值。

响应/回调密钥需要无中断切换，建议设置延迟生效：

```bash
export WXMC_KEY_TYPE=response
export WXMC_KEY_GRACE_SECONDS=3600
export WXMC_KEY_ACTIVATE_AFTER_SECONDS=300
php bin/keys.php
```

在 300 秒生效时间之前，将插件当前 `callback_secret` 移到 `callback_secret_previous`，再把命令输出的新密钥填入 `callback_secret`。插件在过渡期同时验证两个密钥；新密钥生效并确认稳定后删除上一密钥配置。

吊销指定密钥：

```bash
export WXMC_KEY_ACTION=revoke
export WXMC_PROVISION_ID=cxpay-site-01
export WXMC_KEY_ID=key_xxxxxxxxxxxxxxxxxxxxxxxx
php bin/keys.php
```

系统不允许在替代密钥尚未生效时吊销唯一的当前响应密钥。重新执行 `bin/provision.php` 会立即替换该身份的全部托管密钥，只应用于首次创建或明确的灾难恢复，不应代替日常轮换。

## 运维状态

`GET /v1/ops/status` 使用与其他客户端接口相同的 HMAC 鉴权，只返回当前 Client 所属账号及其关联采集器。指标包括授权会话、订单、到账事件、回调发件箱状态和最早积压时间。采集器只有在签名、防重放和时间窗口校验全部通过后才更新活动时间；超过 120 秒没有合法请求时，CXPAY 管理后台将其显示为离线。

接口不返回请求密钥、响应密钥、加密密文、随机数记录或其他 Client 的账号。公开 `/health` 只用于负载均衡存活检查，不提供业务指标。
