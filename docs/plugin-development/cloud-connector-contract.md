# CXPAY 云端支付连接器合同

版本：`cxpay-cloud-payment-v1`  
状态：Design Approved  
日期：2026-08-06

## 1. 目的

本合同定义 CXPAY 本地连接器插件与隔离云端支付服务之间的最小协议、安全边界和验收要求。

适用场景：

- 支付宝个人收款码云账单监控；
- 微信个人收款码云账单监控；
- 其他经平台批准的远端支付监控服务。

不适用：

- 支付宝开放平台官方商家支付；
- 微信支付官方商户 API；
- 在 CXPAY 本机运行浏览器自动化、Cookie 登录或账单抓取；
- 任何规避支付平台安全控制的实现。

## 2. 架构角色

### 2.1 云端支付服务

云端服务持有支付账号网页登录凭据，并负责：

- 创建扫码登录会话；
- 加密保存 Cookie 和 Token；
- 维持登录态；
- 监控账单或到账事件；
- 接受 CXPAY 订单登记；
- 唯一匹配账单和订单；
- 推送签名到账事件；
- 提供事件审查和运维状态。

### 2.2 本地连接器插件

本地插件负责：

- 通过标准客户端调用云端；
- 将云端授权二维码展示给商户；
- 保存不可登录的 `account_id`；
- 登记订单；
- 验证云端响应和回调；
- 转换为 `PaymentDriverInterface` 结果。

插件不持有支付账号 Cookie、网页登录 Token 或浏览器存储。

## 3. Manifest 必要字段

云端连接器必须声明：

```json
{
  "schema": 1,
  "id": "cxpay.alipay.scan_monitor",
  "slug": "alipay_scan_monitor",
  "name": "支付宝扫码免挂适配器",
  "version": "1.2.0",
  "publisher": "cxpay.official",
  "payment_type": "alipay",
  "collection_mode": "personal_qr",
  "monitor_mode": "callback",
  "runtime_type": "cloud_connector",
  "credential_boundary": "cloud_only",
  "cloud_protocol": "cxpay-cloud-payment-v1",
  "permissions": {
    "outbound_hosts": ["api.provider.example"],
    "callbacks": ["/notify/alipay_scan_monitor"],
    "scheduled_tasks": false,
    "secret_config": [
      "client_secret",
      "callback_secret",
      "callback_secret_previous"
    ]
  }
}
```

安全语义是强制的，不得因为 manifest 数字 schema 暂未升级而忽略。实现阶段可以通过兼容迁移扩展当前 schema 1 校验器，但新生产包必须具备上述字段。

### 3.1 `runtime_type`

固定为：

```text
cloud_connector
```

表示插件本地只做远端协议适配。

### 3.2 `credential_boundary`

固定为：

```text
cloud_only
```

表示支付账号登录凭据只能存在云端。

### 3.3 `outbound_hosts`

必须是实际主机名列表，不接受描述性文本、通配符、IP 地址或带端口 URL。

错误示例：

```json
["由管理员配置的云账单服务域名"]
```

正确示例：

```json
["api.paycloud.example"]
```

管理员配置的 `monitor_base_url` 主机必须精确匹配批准主机之一。

## 4. 禁止配置字段

插件 manifest、`getMeta()['inputs']`、API 和数据库中不得出现或承载：

```text
cookie
cookie_base64
cookies
set_cookie
browser_cookie
login_token
session_token
web_token
device_token
local_storage
session_storage
web_session
```

不得通过以下方式规避：

- 改名为 `extra_data`；
- 放入 JSON textarea；
- 隐藏字段；
- Base64、URL 编码、加密或压缩；
- 管理员专用字段；
- 临时调试接口。

## 5. 允许的通道配置

典型配置：

```json
{
  "qr_url": "https://qr.alipay.com/example",
  "monitor_base_url": "https://api.paycloud.example",
  "provider_id": "cxpay.official.alipay-cloud",
  "account_id": "opaque-account-reference",
  "client_id": "cxpay-instance-id",
  "client_secret": "encrypted-at-rest",
  "callback_secret": "encrypted-at-rest",
  "callback_secret_previous": "encrypted-at-rest-or-empty"
}
```

要求：

- `account_id` 是不可登录的云端引用；
- `client_secret` 和回调密钥属于 CXPAY 与云端之间的接口密钥，不是支付账号凭据；
- 密钥使用 CXPAY 敏感配置加密机制保存；
- 密码字段不回显原值；
- 服务地址必须通过公共安全客户端校验。

## 6. HTTP 安全

插件不得直接使用 Guzzle、cURL、`file_get_contents(http...)` 或 stream context 发起云端请求。

所有请求必须通过公共：

```text
CloudConnectorHttpClient
```

公共客户端负责：

- 仅允许 HTTPS；
- 仅允许 443；
- 主机名匹配 manifest 白名单；
- 禁止 IP 字面量、用户信息和重定向；
- 验证所有 DNS A/AAAA 结果为公网地址；
- 固定实际连接到已验证地址；
- 保留原 Host 和 TLS SNI；
- 设置连接和总超时；
- 限制响应体大小；
- 不向上层暴露 `Set-Cookie`；
- 统一请求签名和响应验签；
- 统一脱敏日志。

## 7. 请求认证

每个请求至少包含：

```text
X-CXPAY-Client
X-CXPAY-Timestamp
X-CXPAY-Nonce
X-CXPAY-Signature
```

规范串：

```text
HTTP_METHOD\nREQUEST_PATH\nTIMESTAMP\nNONCE\nSHA256(RAW_BODY)
```

算法：

```text
HMAC-SHA256(client_secret, canonical_string)
```

要求：

- 时间戳使用 Unix 秒；
- nonce 使用至少 128 位随机数；
- 服务端在时间窗口内拒绝重复 nonce；
- 签名比较使用常量时间比较；
- 路径必须使用标准化后的实际请求路径；
- 禁止请求重定向，否则签名路径会失去约束。

## 8. 响应认证

云端所有成功和业务错误响应都必须签名。

推荐响应头：

```text
X-CXPAY-Timestamp
X-CXPAY-Nonce
X-CXPAY-Signature
```

响应规范串：

```text
STATUS_CODE\nTIMESTAMP\nNONCE\nSHA256(RAW_BODY)
```

连接器在验签前不得解析或信任业务 JSON。

密钥轮换期间可以接受：

- `callback_secret`；
- `callback_secret_previous`。

宽限期结束后必须清空旧密钥。

## 9. 授权会话接口

### 9.1 创建授权会话

```http
POST /v1/auth-sessions
```

请求：

```json
{
  "reference": "cxpay-channel-123",
  "pay_type": "alipay"
}
```

响应：

```json
{
  "session_id": "opaque-session-id",
  "status": "PENDING",
  "qr_url": "https://provider.example/auth/qr/...",
  "expires_at": 1785945600
}
```

约束：

- `session_id` 不可猜测；
- `qr_url` 短期有效；
- 响应不得包含 Cookie、Token 或浏览器存储；
- 云端授权页面直接与支付平台交互；
- CXPAY 不作为 Cookie 中转站。

### 9.2 查询授权会话

```http
GET /v1/auth-sessions/{session_id}
```

状态：

```text
PENDING
SCANNED
CONFIRMED
FAILED
EXPIRED
```

确认响应：

```json
{
  "session_id": "opaque-session-id",
  "status": "CONFIRMED",
  "account_id": "opaque-account-reference",
  "capabilities": {
    "bill_monitor": true
  },
  "confirmed_at": 1785945000
}
```

`account_id` 不能用于登录支付平台，也不能是账号明文。

## 10. 订单登记接口

```http
POST /v1/orders
```

请求：

```json
{
  "account_id": "opaque-account-reference",
  "out_trade_no": "CX123456789",
  "amount": "18.88",
  "expires_at": 1785945600,
  "pay_type": "alipay"
}
```

响应：

```json
{
  "accepted": true,
  "registration_id": "provider-order-reference",
  "out_trade_no": "CX123456789"
}
```

规则：

- 金额必须是两位十进制字符串；
- 云端按 `(client_id, out_trade_no)` 幂等；
- 重复请求参数一致返回原结果；
- 参数不一致返回幂等冲突；
- 未明确返回 `accepted=true` 时，CXPAY 不得展示收款码；
- 订单过期后云端停止匹配新到账事件，迟到事件进入审查队列。

## 11. 订单查询接口

```http
GET /v1/orders/{out_trade_no}
```

返回状态：

```text
REGISTERED
PAID
EXPIRED
CANCELLED
REVIEW_REQUIRED
UNKNOWN
```

示例：

```json
{
  "out_trade_no": "CX123456789",
  "status": "PAID",
  "event_id": "evt_01...",
  "source_trade_no": "provider-bill-id",
  "amount": "18.88",
  "occurred_at": 1785945200
}
```

本地连接器的 `query()` 应调用该接口，而不是永久返回未支付。

## 12. 到账回调

推荐路径：

```text
/notify/{driver_code}
```

字段：

```text
provider_id
account_id
event_id
source_trade_no
out_trade_no
amount
occurred_at
timestamp
nonce
signature
```

签名规范：

1. 除 `signature` 外按字段名排序；
2. 使用 RFC3986 查询字符串编码；
3. 使用回调密钥计算 HMAC-SHA256。

校验要求：

- `event_id` 全局唯一；
- `nonce` 在有效窗口内不可重复；
- `timestamp` 偏差不超过配置窗口；
- `occurred_at` 在允许的账单延迟范围；
- `account_id` 与订单绑定账号一致；
- `out_trade_no` 已向云端登记；
- `amount` 与订单应付金额一致；
- `source_trade_no` 格式合法；
- 签名通过；
- 重复事件返回原处理结果；
- 验证失败不返回具体差异。

CXPAY 最终核销仍由订单数据库事务和状态锁裁决。

## 13. 异常事件审查

云端服务应提供：

```http
GET  /v1/review/events
POST /v1/review/events/{event_id}/match
POST /v1/review/events/{event_id}/ignore
```

异常事件不能直接触发支付核销。

人工匹配前，云端必须再次验证：

- 账号；
- 金额；
- 到账时间窗；
- 订单状态；
- 事件未被其他订单消费。

所有匹配和忽略操作记录操作人、时间、理由和前后状态。

## 14. 运维状态

```http
GET /v1/ops/status
```

示例：

```json
{
  "service": "ok",
  "account_status": "online",
  "last_bill_sync_at": 1785945000,
  "queue_depth": 0,
  "callback_delivery": "ok"
}
```

CXPAY 后台必须明确：该状态来自云端服务，不代表支付平台官方 SLA。

## 15. 错误模型

标准错误：

```json
{
  "code": "AUTH_REQUIRED",
  "message": "账号需要重新授权",
  "request_id": "req_01...",
  "retryable": false
}
```

错误分类：

- `retryable=false`：配置、授权、参数或权限错误；
- `retryable=true`：临时网络、限流或服务故障；
- `result_uncertain=true`：请求可能已执行，必须按原业务号查询。

错误响应同样必须签名。

## 16. 日志和审计

允许记录：

```text
request_id
provider_id
client_id
account_id 的短掩码
channel_id
out_trade_no
event_id
API path
status code
脱敏错误码
```

禁止记录：

- Cookie；
- 请求签名密钥；
- 完整回调密钥；
- 完整认证头；
- 云端返回的敏感原始正文；
- 支付账号明文；
- 扫码登录页面的可复用会话信息。

## 17. 插件能力声明

能力必须与实现一致。

例如：

```json
{
  "dynamic_qr": false,
  "server_monitor": false,
  "external_monitor": true,
  "account_authorization": true,
  "order_registration": true,
  "order_query": true,
  "signed_callback": true,
  "callback_key_rotation": true,
  "payment_event_review": true,
  "operations_status": true
}
```

不得把“云端服务监控”描述成“CXPAY 本机 server monitor”。

## 18. 测试合同

连接器包必须通过：

- manifest 安全字段测试；
- Cookie 禁止字段测试；
- 授权响应无 Cookie 测试；
- 请求签名测试；
- 响应验签测试；
- 新旧回调密钥轮换测试；
- nonce 重放测试；
- `event_id` 重放测试；
- 金额十进制字符串测试；
- 订单登记幂等测试；
- 订单查询测试；
- 上游超时和不确定结果测试；
- 私网、DNS 重绑定、重定向和超大响应测试；
- 日志脱敏测试；
- 插件停用后驱动不可用测试。

## 19. 生产启用要求

生产启用前必须：

1. 插件包由受信任发布者签名；
2. 云服务域名加入批准列表；
3. 完成服务商身份和 TLS 审查；
4. 为每个 CXPAY 实例分配独立 `client_id` 和密钥；
5. 完成回调密钥轮换演练；
6. 完成授权、订单登记、真实小额到账、重复回调和异常事件审查；
7. 证明 CXPAY 请求、数据库、Redis 和日志中不存在 Cookie；
8. 后台向管理员展示正确的信任声明。
