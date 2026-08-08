# 微信店员通道可靠性设计

## 1. 背景

`wxpay_clerk_adapter` 通过独立的 `wxpay-clerk-service` 登记 CXPAY 待支付订单，并接收 Gewe/iPad 协议容器推送的微信店员到账消息。现有实现能够完成基础扫码登录、金额匹配和同步回调，但不满足生产资金链路要求：订单在回调前已经标记为匹配，回调失败只记录日志；人工复核不会触发结算；服务缺少订单查询、防重放持久化和到账事件唯一约束；主站复核与运维页面也没有发现该通道。

插件及文档使用“官方、100% 零封号、零风险”等表述，与实际 Gewe/iPad 协议依赖不一致。本阶段必须同步修正能力边界和风险说明。

## 2. 目标

本阶段原地强化现有店员服务，在不删除、不自动启停现有通道的前提下，实现：

1. 订单、到账事件、人工复核和回调任务的事务一致性。
2. 具有租约、指数退避和崩溃恢复能力的持久化回调发件箱。
3. 稳定账单编号幂等、客户端请求 nonce 防重放和授权会话状态约束。
4. 插件已经调用但服务缺失的订单查询接口。
5. 人工匹配后走与自动匹配相同的可靠结算流程。
6. 主站通过能力接口发现复核和运维插件，移除硬编码通道列表。
7. 插件清单、README、运行时能力和实际风险保持一致。
8. 插件、服务和主站之间具备自动化端到端契约测试。

完成后，该通道可以进入小额灰度生产验证，但不得承诺不存在微信账号风控、协议变化或第三方服务风险。

## 3. 非目标

- 不把店员服务迁移到 `wx-monitor-cloud`。
- 不修改 Gewe 容器或实现新的微信协议。
- 不接入微信官方商户支付。
- 不自动迁移、删除或停用现有通道。
- 不在本阶段提供多租户 SaaS 控制台。
- 不修改其他通道的业务行为；仅在它们实现通用能力接口时做兼容性声明调整。

## 4. 架构

```text
Gewe / 微信店员账号
    │ 受限 Webhook
    ▼
wxpay-clerk-service
    ├─ Webhook 鉴权与标准化
    ├─ 授权会话
    ├─ 订单登记和查询
    ├─ 到账事件匹配
    ├─ 人工复核
    ├─ 回调发件箱
    └─ 运维状态
         │ 签名回调
         ▼
wxpay_clerk_adapter
         │
         ▼
CXPAY 统一订单结算与商户通知
```

Gewe Webhook 请求只负责验证、标准化并持久化事件。自动匹配或人工匹配成功时，在同一数据库事务内更新订单与到账事件、写审计并创建回调任务。独立投递进程负责调用 CXPAY，不再在 Webhook 请求内同步回调。

## 5. 组件拆分

现有 `OrderStore` 同时承担连接、迁移、订单、账号、授权和复核职责。本阶段拆分为以下单元：

| 组件 | 单一职责 |
|---|---|
| `Database` | 创建 SQLite 连接和执行事务 |
| `SchemaMigrator` | 版本化、幂等、无损数据库迁移 |
| `OrderRepository` | 订单登记、查询、候选搜索和状态变更 |
| `PaymentEventRepository` | 到账事件幂等写入和状态变更 |
| `ReviewRepository` | 复核列表、审计、匹配和忽略 |
| `AccountRepository` | 账号与 Gewe AppID 索引查询 |
| `AuthSessionRepository` | 授权会话持久化和状态更新 |
| `NonceRepository` | 请求 nonce 登记和过期清理 |
| `OutboxRepository` | 回调任务创建、租约领取和状态更新 |
| `PaymentMatchingService` | 自动匹配和人工匹配业务规则 |
| `OutboxDispatcher` | 回调签名、投递、退避和恢复 |
| `ApiApplication` | 可测试的路由、认证和响应对象 |

主工作区已有未提交的 `getAccountByGeweAppId()` 和 `idx_accounts_gewe_app_id` 优化。本阶段保留其行为，并迁移到 `AccountRepository`，不得退回全表遍历。

## 6. 数据模型与迁移

### 6.1 `schema_migrations`

保存已经执行的迁移版本。迁移必须可以安全重复启动，不删除历史数据。

### 6.2 `request_nonces`

字段包括 `client_id`、`nonce`、`used_at`、`expires_at`，唯一约束为 `(client_id, nonce)`。只有签名、时间戳和 nonce 格式全部通过后才写入；唯一约束冲突代表重放。

### 6.3 `orders`

保留现有订单数据，补充必要索引。`out_trade_no` 唯一。状态只允许 `PENDING`、`MATCHED`、`EXPIRED`。重复登记相同账号、金额和有效期时返回幂等成功；参数不同返回冲突。

### 6.4 `payment_events`

保存 `account_id`、`source_bill_id`、金额、付款人、备注、发生时间、原始摘要、状态、匹配订单和接收时间。唯一约束为 `(account_id, source_bill_id)`。

事件状态为：

```text
RECEIVED
  ├─ 唯一匹配 → MATCHED
  ├─ 多个候选 → REVIEW_REQUIRED
  ├─ 无候选 → UNMATCHED
  └─ 人工忽略 → IGNORED
```

### 6.5 `review_events`

保留历史复核数据，增加对 `payment_event_id` 的关联以及操作者、备注、处理时间审计。人工忽略必须提供非空原因。

### 6.6 `callback_outbox`

保存到账事件、平台订单号、回调载荷、状态、尝试次数、下次执行时间、租约截止时间、最后错误和完成时间。每个 `payment_event_id` 只能创建一个回调任务。

状态为：

```text
PENDING
  → PROCESSING
      ├─ CXPAY 确认成功 → SENT
      ├─ 临时失败 → PENDING
      └─ 超过最大次数 → FAILED
```

## 7. 订单匹配与人工复核

自动匹配顺序：

1. 备注精确包含合法平台订单号，并且账号、金额、订单状态、创建时间和有效期全部一致时精确匹配。
2. 当前账号、金额和时间窗内恰好一个候选订单时唯一匹配。
3. 多个候选订单进入 `REVIEW_REQUIRED`，禁止配置成“自动取最早一笔”。
4. 没有候选订单进入 `UNMATCHED`。

无法确认稳定 `source_bill_id` 的通知不得自动匹配，只能进入人工复核。

人工匹配必须重新读取事件和订单，并验证：

- 事件仍处于 `REVIEW_REQUIRED` 或 `UNMATCHED`。
- 订单仍为 `PENDING`。
- 账号和金额一致。
- 到账时间落在订单创建至过期时间内。
- 订单和事件均未被其他事务占用。

校验通过后，在一个事务内将订单和事件标记为 `MATCHED`、写入审计并创建发件箱任务。重复提交完全相同的人工结果返回幂等成功，竞争占用返回 `409`。

## 8. 可靠回调与主动查询

回调字段继续兼容插件：

```text
source_bill_id
out_trade_no
money
occurred_at
timestamp
nonce
sign
```

除 `sign` 外按键排序，以 RFC3986 查询编码形成规范串，再使用 `callback_secret` 计算 HMAC-SHA256。CXPAY 只有返回 HTTP 2xx 且响应正文为 `success` 时，任务才标记为 `SENT`。

投递器通过数据库租约原子领取任务。失败时采用指数退避；进程在投递中崩溃后，租约到期的任务可被重新领取。超过最大次数的任务进入 `FAILED`，运维接口必须返回失败数量、最早积压时间和最后错误摘要。

`GET /v1/orders/{out_trade_no}` 在订单具有可信匹配事件时返回 `paid=true`，并返回金额、发生时间和回调状态。这样即使回调暂时失败，主站仍可以通过主动查询恢复订单；未匹配、过期或不存在的订单不得返回已支付。

## 9. API 契约

```text
GET  /health
POST /v1/orders
GET  /v1/orders/{out_trade_no}
POST /v1/auth-sessions
GET  /v1/auth-sessions/{session_id}
GET  /v1/accounts/{account_id}/capabilities
GET  /v1/review/events
POST /v1/review/events/{event_id}/match
POST /v1/review/events/{event_id}/ignore
GET  /v1/ops/status
POST /wechat/message/{webhook_token}
```

订单登记成功必须明确返回 `accepted=true`。插件只有收到这一结果后才能展示收款码。

状态码约定：

| 状态码 | 含义 |
|---|---|
| `400` | 字段、金额、时间或状态转换不合法 |
| `401` | 身份、签名、时间窗口或 Webhook 鉴权失败 |
| `404` | 资源不存在 |
| `409` | 重放、参数冲突、重复占用或非法并发状态 |
| `503` | 数据库或服务未就绪 |

经过 CXPAY 身份认证的响应，包括业务错误响应，都使用 `callback_secret` 对原始 JSON 正文签名。无法确认身份的 `401` 响应不签名。

## 10. 安全设计

- 插件配置只接受公网 HTTPS 云服务地址。
- HTTP 客户端每次请求前重新验证地址，禁止重定向。
- `client_secret`、`callback_secret` 长度必须为 32 至 128 字节。
- 请求时间窗口为 300 秒，nonce 长度为 16 至 128 位且窗口内不可重复。
- Gewe Webhook 同时校验非空来源 IP 白名单和至少 32 字节的不可猜测路径令牌。
- Webhook 正文限制大小，字符串字段限制长度，金额使用两位小数字符串校验。
- 密钥优先从环境变量读取，不输出到日志、API 或运维状态。
- 回调地址必须是公网 HTTPS，禁止重定向和私网目标。
- 日志不得记录 Cookie、密钥、完整原始消息或数据库连接信息。

## 11. 主站能力发现

新增：

```php
PaymentEventReviewInterface
OperationsStatusInterface
```

具备能力的插件显式实现接口。`CallbillAdminController` 和 `CloudMonitorAdminController` 通过接口发现通道，不再硬编码通道类型数组。该调整同时兼容现有 `wxpay_cloud_adapter` 和 `alipay_scan_monitor`，但不改变它们的业务逻辑。

插件 `manifest.json` 必须与运行时能力一致，包括订单登记、订单查询、签名回调、密钥轮换、人工复核和运维状态。

## 12. 文案与能力边界

删除“微信官方到账通知”“100% 零封号”“零风险”等表述。统一说明：

- 服务依赖 Gewe/iPad 协议和个人微信店员账号。
- 商户主账号无需在本服务登录，但店员账号仍存在协议变化和风控风险。
- 使用者必须自行确认平台规则、账号授权和当地合规要求。
- 服务故障、账号离线或消息格式变化时必须允许人工复核。

## 13. 测试与验收

### 13.1 单元测试

1. 请求签名、响应签名、时间窗口和 nonce 防重放。
2. 数据库历史结构无损迁移和重复启动。
3. 订单幂等登记与参数冲突。
4. 备注精确匹配、金额唯一匹配、歧义和无候选。
5. 重复 `source_bill_id` 幂等。
6. 人工匹配重新校验、竞争处理和忽略审计。
7. 发件箱租约、指数退避、失败恢复和最大次数。
8. 授权会话创建、确认、失败和过期。

### 13.2 集成测试

1. 插件登记订单，服务明确返回 `accepted=true` 后才出码。
2. Webhook 到账只创建一个事件和一个回调任务。
3. 相同 Webhook 重放十次只匹配和回调一次。
4. CXPAY 首次失败、后续恢复时回调最终成功。
5. 回调失败期间订单查询仍返回可信支付结果。
6. 人工匹配创建与自动匹配相同的回调任务。
7. 插件声明的每个接口都有服务端路由。
8. 主站复核和运维页面通过能力接口发现店员通道。
9. Gewe、HTTP 和 CXPAY 使用测试替身，不依赖真实账号或公网服务。

### 13.3 全局验证

- `composer validate --strict`
- 全量 PHPUnit
- PHP 语法扫描覆盖 `app`、`support`、`config`、`process`、`plugins-src` 和 `services/wxpay-clerk-service`
- `git diff --check`

验收标准是：到账事件在重复、并发、回调失败和进程崩溃情况下不丢失、不重复核销；人工复核能够真正完成结算；插件、服务、主站和文档的能力声明一致。

## 14. 发布策略

升级保持现有配置字段兼容。先在测试环境导入历史 SQLite 数据验证迁移，再进行小额灰度。灰度期间重点监控账号在线状态、未匹配事件、回调积压、失败任务和主动查询恢复次数。稳定后再扩大通道使用范围，但发布页面始终保留协议和风控风险说明。
