# 支付宝第三方应用当面付与云端支付连接器隔离设计

日期：2026-08-06  
目标分支：`work/alipay-isv-f2f`  
基线分支：`work/epay-upstream-consolidation`  
状态：已批准

## 1. 目标

为 CXPAY 增加支付宝第三方应用商家授权与当面付扫码支付能力，并形成可扩展到多商户、多授权账号和生产灰度的完整交易闭环。

本设计同时固化云端个人码监控通道的凭据隔离边界，防止后续开发再次把支付宝或微信 Cookie、网页登录 Token 或可复用会话带入 CXPAY。

第一期交付范围包括：

- 支付宝第三方应用商家扫码授权；
- 沙箱与生产双环境隔离；
- `alipay.trade.precreate` 动态二维码；
- 支付宝异步通知验签和业务字段校验；
- 主动查单与通知补偿；
- 超时订单先查后关；
- 整单退款和多次部分退款；
- 退款默认人工审核；
- 退款查询与结果不确定补偿；
- 全链路幂等、审计、敏感信息保护；
- 云端支付服务与本地连接器插件的强制凭据隔离规范。

## 2. 已批准的关键决策

### 2.1 支付模式

新增内置驱动：

- 驱动标识：`alipay_isv_f2f`
- 展示名称：支付宝商家当面付（扫码授权免挂机）
- 支付方式：`alipay.trade.precreate`
- 二维码：每笔订单动态生成
- 监控方式：支付宝官方异步通知 + CXPAY 主动查单

该驱动与现有个人码监控驱动严格分离：

- `alipay_app_asst`：个人收款码安卓监听；
- `alipay_scan_monitor`：云端账单监控连接器；
- `alipay_isv_f2f`：支付宝官方第三方应用当面付。

不得恢复已永久移除的 `alipay_official` 或 `alipay_scan_bill`。

### 2.2 部署模型

第一期先服务一个正式商家，但数据结构和服务边界必须平滑支持多商户。

每个 CXPAY 商户、每个环境第一期只允许一个有效支付宝授权；表结构保留 `account_slot`，以后可支持同一商户绑定多个支付宝商家账号。

### 2.3 凭据存储

采用混合方案：

- `.env` 保存沙箱和生产的 AppID、应用私钥、支付宝公钥、网关和环境开关；
- 数据库保存商户授权状态、支付宝商家身份和加密后的 `app_auth_token`；
- 通道记录只引用授权记录，不复制令牌；
- 沙箱和生产授权记录完全分离；
- 日志、后台页面和 API 不回显完整密钥或令牌。

### 2.4 授权入口

商户后台和管理员后台均可发起授权会话：

- 商户后台负责自助扫码授权、重新授权和解除绑定；
- 管理员后台负责状态查看、诊断、停用和生产审批；
- 管理员不能代替商家完成支付宝确认；
- 管理员不能查看完整 `app_auth_token`。

### 2.5 退款范围

第一期支持：

- 整单全额退款；
- 多次部分退款；
- 每次退款使用独立 `out_request_no`；
- 累计退款额不得超过实付金额；
- 商户后台和商户 API 均可提交；
- 所有退款默认进入人工审核；
- 管理员只能审批、拒绝、查询和执行受控补偿，不能手工标记成功。

## 3. 两类支付架构

CXPAY 支付体系明确分成两条产品线。

### 3.1 Cloud Connector Plugin

适用于个人收款码、账单监控和远端支付服务。

```text
支付宝 / 微信账号
        │ Cookie、网页登录态、账单访问
        ▼
隔离的云端支付服务
        │ 签名后的授权状态、订单登记结果、到账事件
        ▼
CXPAY 本地连接器插件
        │ 标准支付驱动结果
        ▼
CXPAY 订单核销
```

云端支付服务负责账号登录、Cookie 保存、凭据续期、账单监控、订单匹配和签名事件推送。

本地连接器插件只负责协议适配，不接触支付账号登录态。

### 3.2 Native Official Driver

适用于支付宝和微信官方商户 API。

`alipay_isv_f2f` 属于此类。它直接使用支付宝开放平台凭据和商家授权令牌，不依赖网页登录 Cookie。

两类通道必须使用不同驱动标识、授权模型、后台说明、状态机和验签方式。订单创建后禁止在两类通道之间切换。

## 4. 强制架构决策：Cloud Credential Isolation

详见：

- `docs/architecture/cloud-payment-credential-isolation.md`
- `docs/plugin-development/cloud-connector-contract.md`

核心不变量：

> 支付宝、微信 Cookie、网页登录 Token、浏览器存储、可复用会话和设备登录凭据只能存在于隔离的云端支付服务中，绝不能进入 CXPAY。

CXPAY 禁止接收、保存、展示、转发或记录：

- `cookie`、`cookie_base64`、`Set-Cookie`；
- 网页登录 Token、Session Token、设备 Token；
- 浏览器 localStorage、sessionStorage；
- 可复用的个人账号登录态。

CXPAY 只允许保存不可用于重新登录的最小引用：

- `provider_id`；
- `account_id`；
- `authorization_session_id`；
- 授权状态；
- 短期授权二维码；
- 能力和运维状态。

支付宝开放平台正式授权产生的 `app_auth_token` 不属于网页登录 Cookie，但仍是高敏感凭据，必须使用 `APP_KEY` 派生密钥进行 AES-256-GCM 加密保存。

当前 `plugins-src/alipay-scan-monitor` 中的 `cookie_base64` 是已知架构偏差，必须在个人码插件进入生产前删除，并由自动化测试阻止再次引入。

## 5. 组件设计

### 5.1 `AlipayClientFactory`

职责：

- 根据 `sandbox` 或 `production` 构建客户端；
- 读取平台级 AppID、私钥、公钥和网关；
- 验证环境配置完整性；
- 统一 SDK 配置、超时、日志脱敏和异常归一化；
- 禁止业务层直接散落构建 SDK 客户端。

### 5.2 `AlipayAuthorizationService`

职责：

- 创建授权会话；
- 生成支付宝官方授权地址；
- 校验一次性 `state`；
- 处理支付宝回调；
- 使用授权码换取授权令牌；
- 查询并绑定支付宝商家身份；
- 加密保存令牌；
- 重新授权、撤销和失效处理；
- 写入授权审计事件。

### 5.3 `AlipayIsvF2fDriver`

实现现有 `PaymentDriverInterface`：

- `pay()`：预下单并返回动态二维码；
- `notify()`：支付宝官方验签和支付结果规范化；
- `query()`：主动查单；
- `getMeta()`：声明驱动元数据和非敏感通道配置；
- `upchannel()`：验证环境、授权归属和生产门禁。

驱动不得直接处理控制器、数据库事务、退款审核或后台权限。

### 5.4 `AlipayTradeService`

职责：

- 主动查单；
- 关单；
- 上游交易状态归一化；
- 网络临时失败、确定性失败和结果不确定分类；
- 提供后台任务补偿所需的幂等接口。

### 5.5 `AlipayRefundService`

职责：

- 校验原订单和退款资格；
- 生成或接受稳定的 `out_request_no`；
- 执行退款；
- 主动查询退款结果；
- 处理结果不确定；
- 写入退款审计事件。

### 5.6 `RefundRiskService`

第一期所有退款均输出 `pending_review`。未来开放自动退款后，仍由同一服务根据平台上限、商户阈值、首次退款、频率和异常订单决定自动执行或人工审核。

## 6. 平台配置

建议新增 `config/alipay_isv.php`，从 `.env` 读取：

```dotenv
ALIPAY_ISV_SANDBOX_ENABLED=true
ALIPAY_ISV_SANDBOX_APP_ID=
ALIPAY_ISV_SANDBOX_PRIVATE_KEY=
ALIPAY_ISV_SANDBOX_PUBLIC_KEY=
ALIPAY_ISV_SANDBOX_GATEWAY=

ALIPAY_ISV_PRODUCTION_ENABLED=false
ALIPAY_ISV_PRODUCTION_APP_ID=
ALIPAY_ISV_PRODUCTION_PRIVATE_KEY=
ALIPAY_ISV_PRODUCTION_PUBLIC_KEY=
ALIPAY_ISV_PRODUCTION_GATEWAY=

ALIPAY_ISV_AUTH_CALLBACK_URL=
ALIPAY_ISV_NOTIFY_BASE_URL=
```

约束：

- 生产默认关闭；
- 沙箱和生产配置分别校验；
- 私钥和公钥不进入通道数据库；
- 配置错误只禁用对应环境，不影响其他支付通道启动；
- 日志不得输出密钥正文；
- 生产启用还必须通过数据库中的人工审批门禁。

## 7. 数据库设计

### 7.1 `cx_alipay_authorization`

建议字段：

```text
id
merchant_id
environment
account_slot
platform_app_id
alipay_user_id
merchant_pid
app_auth_token_cipher
scopes_json
status
authorized_at
last_verified_at
reauth_required_at
revoked_at
failure_code
failure_message
created_by_type
created_by_id
created_at
updated_at
```

唯一约束：

```text
UNIQUE (merchant_id, environment, account_slot)
```

第一期 `account_slot=default`。

不保存应用私钥、支付宝公钥、授权码、原始回调正文和明文令牌。

### 7.2 `cx_alipay_authorization_event`

追加式记录：

- `session_created`；
- `authorization_confirmed`；
- `authorization_failed`；
- `capability_verified`；
- `reauth_required`；
- `revoked`；
- `replaced`；
- `channel_disabled`。

事件中不得包含令牌或授权码。

### 7.3 `cx_refund`

建议字段：

```text
id
merchant_id
order_id
merchant_refund_no
out_request_no
refund_amount
reason
status
risk_result
reviewed_by
reviewed_at
executed_at
alipay_trade_no
alipay_response_code
alipay_response_sub_code
last_query_at
query_attempts
failure_message
created_at
updated_at
```

唯一约束：

```text
UNIQUE (merchant_id, merchant_refund_no)
UNIQUE (order_id, out_request_no)
```

### 7.4 `cx_refund_event`

追加记录：

- `submitted`；
- `risk_evaluated`；
- `approved`；
- `rejected`；
- `execution_started`；
- `execution_uncertain`；
- `execution_failed`；
- `refund_succeeded`；
- `query_started`；
- `query_completed`。

### 7.5 订单上游生命周期字段

建议增加或等价复用：

```text
upstream_state
upstream_last_query_at
upstream_query_attempts
upstream_close_status
upstream_last_error_code
upstream_last_error_at
```

原始支付宝响应不得整段写入订单表。

## 8. 授权流程

```text
商户或管理员发起授权
        ↓
服务端创建一次性授权会话
        ↓
生成支付宝官方授权 URL
        ↓
前端展示二维码
        ↓
商家扫码并确认
        ↓
支付宝回调 CXPAY
        ↓
校验 state、环境、会话、有效期
        ↓
使用 auth_code 换取 app_auth_token
        ↓
查询并确认授权商家身份
        ↓
加密保存，授权状态变为 active
        ↓
原页面轮询获得授权成功
```

授权会话优先存 Redis：

```text
session_id
merchant_id
environment
account_slot
state_hash
created_by_type
created_by_id
expires_at
consumed_at
result_status
authorization_id
```

规则：

- `session_id` 和 `state` 使用密码学安全随机数；
- 建议有效期 10 分钟；
- `state` 一次性消费；
- `auth_code` 不写数据库和日志；
- 轮询接口不返回令牌；
- 管理员发起的授权仍绑定指定商户；
- 回调环境必须与发起环境一致；
- 重新授权保留历史事件并替换当前有效授权。

授权状态：

```text
pending -> active | failed | expired
active -> reauth_required | revoked | replaced
```

授权失效时停止新订单并将相关通道离线，但保留历史查单和退款能力。

## 9. 通道设计

`alipay_isv_f2f` 通道配置只保存：

```json
{
  "environment": "sandbox",
  "authorization_id": 123,
  "store_id": "",
  "timeout_minutes": 3
}
```

`upchannel()` 必须验证：

- 环境值合法；
- 对应平台环境已启用且凭据完整；
- 授权属于当前 CXPAY 商户；
- 授权环境与通道环境一致；
- 授权状态为 `active`；
- 商家具有当面付所需能力；
- 生产通道已有沙箱验收记录和管理员审批。

`online_status=1` 表示配置和授权可用于发起交易，不表示支付宝网络永久可达。

## 10. 支付流程

```text
商户向 CXPAY 下单
        ↓
OrderService 创建平台订单并绑定通道
        ↓
AlipayIsvF2fDriver 加载授权记录
        ↓
解密 app_auth_token
        ↓
调用 alipay.trade.precreate
        ↓
获得 qr_code
        ↓
保存到 cx_order.pay_url
        ↓
向付款方展示动态二维码
```

请求映射：

- `out_trade_no`：CXPAY 平台流水号；
- `total_amount`：订单最终应付金额；
- `subject`：订单标题；
- `notify_url`：`/notify/alipay_isv_f2f`；
- 授权令牌：来自通道引用的授权记录；
- 超时时间：不得超过本地订单有效期。

出码幂等：

- 同一个 CXPAY 订单始终使用同一个支付宝订单号；
- 网络超时后不得生成新业务号；
- 重试仍使用相同订单号和金额；
- 已保存二维码时直接复用；
- 金额、环境或授权发生变化时拒绝复用；
- SDK 原始异常不得直接返回付款人。

金额全程使用两位十进制字符串和 `bc*` 运算，不得在验签和结算链路中依赖浮点数。

## 11. 异步通知与核销

通知路径：

```text
/notify/alipay_isv_f2f
```

处理顺序：

1. 定位 CXPAY 订单及通道；
2. 加载通道绑定授权；
3. 使用对应环境支付宝公钥验签；
4. 校验 `app_id`；
5. 校验平台订单号；
6. 校验支付金额；
7. 校验收款商家身份；
8. 校验成功交易状态；
9. 调用唯一核销入口 `OrderService::markAsPaid()`；
10. 数据库事务提交后返回 `success`。

验签成功但业务字段不一致时仍必须拒绝核销，并记录脱敏安全事件。

通知和主动查询可能同时确认同一订单，幂等由订单状态锁和数据库事务保证，不能依赖进程内状态。

## 12. 主动查单与关单

补偿任务建议按以下节奏处理已出码待支付订单：

- 出码后 15 秒；
- 30 秒；
- 60 秒；
- 此后每 60 秒；
- 到期时最终查询一次。

查询结果：

- 支付成功：调用同一核销入口；
- 等待付款：继续等待；
- 交易不存在：按退避策略重试；
- 授权失效：授权标记 `reauth_required`，通道离线并告警；
- 临时错误：保持当前状态；
- 明确关闭或失败：进入本地终态。

订单到期后必须：

1. 先主动查单；
2. 已支付则核销；
3. 仍待支付才调用关单；
4. 支付宝确认关闭后再关闭本地订单并释放预占手续费；
5. 关单发现已支付则转为核销；
6. 网络结果不确定时保持 `closing`，不得直接标记关闭。

## 13. 退款状态机与并发控制

退款状态：

```text
pending_review -> approved | rejected | cancelled
approved -> executing
executing -> succeeded | failed | uncertain
uncertain -> succeeded | failed
```

第一期所有退款默认进入 `pending_review`。

累计额度校验必须在事务中锁定原订单和相关退款记录：

```text
已成功退款
+ 待审核退款
+ 已批准退款
+ 执行中退款
+ 结果不确定退款
<= 原订单实付金额
```

明确被拒绝或明确失败的退款不再占用额度；结果不确定的退款继续占用，直到查询清楚。

同一退款号重复请求：

- 参数一致：返回原退款记录；
- 订单或金额不一致：拒绝为幂等冲突。

管理员不能手工把退款改成成功。执行重试必须复用原 `out_request_no`。

## 14. 错误分类

所有支付宝调用统一分成：

### 14.1 确定性业务失败

例如授权失效、权限不足、金额非法。停止盲目重试，返回明确业务状态。

### 14.2 临时失败

例如网络超时、限流和上游短时不可用。按退避策略重试，不改变业务终态。

### 14.3 结果不确定

请求可能已被支付宝接受，但本地未收到明确响应。不得生成新业务号，也不得直接标记失败；必须使用原业务号主动查询。

日志只允许记录：

```text
request_id
merchant_id
channel_id
authorization_id
order/refund number
environment
API method
response code
脱敏错误摘要
```

禁止记录私钥、公钥全文、`app_auth_token`、授权码和完整敏感报文。

## 15. 接口边界

### 15.1 商户授权

可复用现有通用入口，也可增加支付宝专用门面：

```text
POST /api/merchant/channel/authorization/start
POST /api/merchant/channel/authorization/poll
GET  /api/merchant/alipay/authorization/status
POST /api/merchant/alipay/authorization/revoke
POST /api/merchant/alipay/authorization/reauthorize
```

控制器只负责鉴权、参数校验和响应，不直接调用支付宝 SDK。

### 15.2 管理员授权管理

```text
GET  /api/admin/alipay/authorizations
GET  /api/admin/alipay/authorizations/{id}
POST /api/admin/alipay/authorizations/{id}/diagnose
POST /api/admin/alipay/authorizations/{id}/revoke
POST /api/admin/alipay/production/approve
```

### 15.3 退款

商户 API：

```text
POST /api/refund/create
GET  /api/refund/query
```

商户后台：

```text
POST /api/merchant/refunds
GET  /api/merchant/refunds
GET  /api/merchant/refunds/{id}
```

管理员：

```text
GET  /api/admin/refunds
POST /api/admin/refunds/{id}/approve
POST /api/admin/refunds/{id}/reject
POST /api/admin/refunds/{id}/query
POST /api/admin/refunds/{id}/retry
```

所有入口必须调用同一个退款应用服务。

## 16. 云端连接器插件修订

`alipay-scan-monitor` 保留为个人码云监控连接器，但生产启用前必须：

1. 删除 `cookie_base64` 及相关说明；
2. 明确 `runtime_type=cloud_connector`；
3. 明确 `credential_boundary=cloud_only`；
4. 使用精确服务商域名权限；
5. 迁移到公共 `CloudConnectorHttpClient`；
6. 金额全程使用十进制字符串；
7. 增加事件唯一性和 nonce 防重放；
8. 实现或明确远端查单语义；
9. 增加 Cookie 隔离自动化测试；
10. 后台明确显示“到账由云端服务确认，并非支付宝官方接口直接通知”。

该修订与 `alipay_isv_f2f` 实现分成独立工作项和独立 PR。

## 17. 测试要求

### 17.1 单元测试

- 环境配置解析；
- 授权状态机；
- 授权所有权检查；
- 令牌加解密；
- 预下单参数；
- 通知验签和业务字段校验；
- 交易状态归一化；
- 查询和关单决策；
- 退款累计额度；
- 多次部分退款；
- 退款幂等；
- 结果不确定状态。

### 17.2 集成测试

- 授权成功后创建通道；
- 未授权时禁止启用；
- 沙箱授权不能用于生产通道；
- 授权商户与通道商户不一致时拒绝；
- 重复初始化不重复预下单；
- 通知与查单并发只核销一次；
- 超时关单与支付成功竞态；
- 并发部分退款不能超额；
- 审核通过只执行一次退款。

### 17.3 安全回归测试

强制断言：

- 插件 manifest 不允许 Cookie 配置；
- 插件 `getMeta()` 不允许 Cookie 输入；
- 授权 API 响应不含 Cookie；
- 数据库不含个人网页登录 Cookie；
- 日志脱敏器不记录 Cookie；
- 云服务 `Set-Cookie` 不向 CXPAY 传播；
- 私钥不进入数据库；
- `app_auth_token` 不明文保存；
- API 不返回完整令牌；
- 授权码不落盘；
- 签名、金额、AppID、商家 PID 任一错误均不能核销。

## 18. 沙箱与生产验收

沙箱至少完成：

1. 商家扫码授权；
2. 授权能力探测；
3. 动态二维码；
4. 支付成功通知；
5. 主动查单；
6. 重复通知幂等；
7. 到期关单；
8. 整单退款；
9. 多次部分退款；
10. 退款查询；
11. 授权撤销后通道离线；
12. 沙箱配置不能调用生产网关。

生产首次上线限制：

- 单商户；
- 单通道；
- 低单笔上限；
- 低日限额；
- 全部退款人工审核；
- 完成小额真实支付、查单、关单和退款后逐步放开。

## 19. 工作项与发布顺序

### 工作项 A：云端连接器凭据隔离

分支建议：`work/cloud-connector-credential-isolation`

- 架构文档；
- 插件合同；
- 删除 `cookie_base64`；
- manifest 安全字段；
- 公共安全 HTTP 客户端；
- 防重放和金额修复；
- Cookie 隔离测试。

### 工作项 B：支付宝官方当面付

分支：`work/alipay-isv-f2f`

- 授权表和服务；
- 内置驱动；
- 预下单、通知、查单、关单；
- 沙箱验收；
- 生产门禁。

### 工作项 C：退款与审核

分支建议：`work/alipay-refund-workflow`

- 退款表和事件；
- 商户后台与 API；
- 人工审核；
- 多次部分退款；
- 查询和不确定状态补偿。

发布顺序：

1. 合并架构文档和 Cookie 防回归门禁；
2. 修复个人码云连接器边界；
3. 增加支付宝授权结构；
4. 完成沙箱授权和支付闭环；
5. 完成查单与关单；
6. 完成退款与审核；
7. 完成沙箱端到端验收；
8. 配置生产凭据但保持关闭；
9. 管理员审批；
10. 小额灰度上线。

## 20. 数据迁移和生产安全

- 数据库迁移前必须备份；
- 新表和新字段使用独立迁移；
- 不自动转换现有 `alipay_app_asst` 或 `alipay_scan_monitor` 通道；
- 不恢复已移除驱动；
- 迁移必须可回滚；
- 生产迁移、`.env` 修改、服务重启和浏览器验收必须由明确上线操作执行；
- 不修改 `install.lock`；
- 不使用 `git clean -fd`；
- 现有生产工作树中的未跟踪文件不得被删除或覆盖。

## 21. 完成标准

只有同时满足以下条件，才能称为支付宝商家扫码免挂机通道对接完成：

1. 商家可以扫码授权第三方应用；
2. CXPAY 不接触个人网页登录 Cookie；
3. 每笔支付生成官方动态二维码；
4. 支付宝通知验签和业务字段校验完整；
5. 丢失通知可通过主动查单恢复；
6. 到期订单先查后关；
7. 重复通知和查询不会重复核销；
8. 支持整单及多次部分退款；
9. 退款默认人工审核；
10. 不确定退款不会被误判失败；
11. 沙箱和生产彻底隔离；
12. 生产默认关闭并经过人工审批；
13. 所有高敏感令牌加密保存；
14. 云端连接器和官方驱动的信任模型在后台明确区分；
15. Cookie 隔离测试成为 CI 强制门禁；
16. 定向测试和完整测试套件均通过；
17. 生产小额验收完成并有审计记录。
