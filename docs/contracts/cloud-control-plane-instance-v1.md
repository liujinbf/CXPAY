# CXPAY 独立云端控制面实例协议 v1

状态：M0 冻结契约，服务端和客户端将在后续里程碑实现。

本文定义 CXPAY 支付节点与官方独立云端控制面之间的最小协议。CXPAY 只运行支付业务；账号、授权、商品、订单、源码包、更新包和插件资产归云端控制面管理。本文不得作为把云端数据库、平台签名私钥或对象存储凭据放入 CXPAY 的依据。

## 1. 边界与资产规则

- 源码包和系统更新包只能由已授权用户登录云端工作台后下载。
- 插件购买和续费在云端工作台完成；插件文件只能由已激活的 CXPAY 实例在本地插件商城中下载和更新。
- 浏览器不得获得插件对象存储永久地址，CXPAY 也不得保存平台对象存储凭据。
- 实例身份使用 Ed25519 密钥对。私钥仅保存在 CXPAY，云端只保存公钥、指纹、绑定域名和撤销状态。
- 旧授权 Key 只允许在一次性迁移接口的 HTTPS 请求体中使用，不得出现在 URL、日志、响应、插件目录或下载凭证中。

## 2. 标识与域名规范

- `instance_id`：云端生成，以 `ins_` 开头，不得由域名推导。
- `plugin_id`：符合 `cxpay.[a-z0-9._-]+`，由官方插件清单分配。
- 域名先转换为 IDNA ASCII、小写并移除末尾点；不得包含协议、路径、查询串、端口或通配符。
- 一个有效实例同时绑定授权主体、规范域名和实例公钥。域名变更必须在云端工作台发起并重新确认实例。

## 3. 接口清单

```text
POST /api/instance/v1/activations/exchange-legacy
POST /api/instance/v1/activations/confirm
GET  /api/instance/v1/plugins/catalog
GET  /api/instance/v1/plugins/{plugin_id}/updates
POST /api/instance/v1/plugins/{plugin_id}/download-grants
GET  /api/artifact/v1/downloads/{grant_token}
```

除首次 `exchange-legacy` 外，所有实例接口都必须签名。所有请求和响应使用 UTF-8 JSON，插件二进制兑换接口除外。

## 4. 实例激活

### 4.1 迁移旧授权

`POST /api/instance/v1/activations/exchange-legacy`

该接口仅用于存量授权迁移，必须通过 TLS 调用，并对源 IP、授权主体和失败次数限流。请求体：

```json
{
  "legacy_key": "仅本次请求使用的旧授权凭据",
  "domain": "pay.example.com",
  "instance_public_key": "Base64URL 编码的 Ed25519 公钥",
  "instance_fingerprint": "公钥原始字节的 SHA-256 十六进制值",
  "product_version": "1.0.0"
}
```

成功响应只返回短期激活挑战，不返回新的永久密钥：

```json
{
  "code": 1,
  "data": {
    "instance_id": "ins_01...",
    "activation_id": "act_01...",
    "challenge": "Base64URL 随机挑战",
    "expires_at": "2026-08-08T12:05:00Z"
  }
}
```

旧授权凭据验证完成后不得写入实例表、审计明文或响应；日志仅记录授权主体内部 ID 和迁移结果。

### 4.2 确认绑定

`POST /api/instance/v1/activations/confirm`

该请求使用上一步分配的 `instance_id` 和已提交的实例私钥完成请求签名，并携带 `Idempotency-Key`。请求体：

```json
{
  "activation_id": "act_01...",
  "challenge": "Base64URL 随机挑战",
  "domain": "pay.example.com"
}
```

云端验证挑战、域名授权、公钥签名和时效后激活实例。成功响应包含 `instance_id`、规范域名、授权状态、实例状态及服务端时间，不包含共享密钥。

云端必须根据 `instance_public_key` 原始字节自行计算并核对指纹，不能信任客户端单独提交的 `instance_fingerprint`。

## 5. 请求鉴权

### 5.1 请求头

```text
X-CXPAY-Instance: ins_...
X-CXPAY-Timestamp: Unix 秒
X-CXPAY-Nonce: 至少 16 字节随机值的 Base64URL
X-CXPAY-Signature: Ed25519 签名的 Base64URL
Idempotency-Key: 写请求必填，建议使用 UUIDv7
```

### 5.2 规范串

签名原文由以下六行组成，行尾不得追加换行：

```text
HTTP_METHOD
REQUEST_PATH
TIMESTAMP
NONCE
BODY_SHA256
INSTANCE_ID
```

规则：

- `HTTP_METHOD` 使用大写。
- `REQUEST_PATH` 使用请求目标路径；存在查询参数时按参数名和值升序排列，并使用 RFC 3986 百分号编码后附加查询串。不得包含协议、主机或片段。
- `BODY_SHA256` 是实际传输请求体字节的 SHA-256 小写十六进制值；无请求体时计算空字节串。
- `INSTANCE_ID` 必须与 `X-CXPAY-Instance` 完全一致。
- `X-CXPAY-Signature` 是实例 Ed25519 私钥对上述 UTF-8 字节签名后的无填充 Base64URL。

### 5.3 防重放与幂等

- 云端仅接受与服务端时间相差不超过 300 秒的请求。
- 云端必须按 `instance_id + nonce` 持久化随机数，至少保留 10 分钟；重复随机数直接拒绝。
- 写请求必须携带 `Idempotency-Key`。相同实例、键和请求体哈希应返回首次结果；同一键对应不同请求体时返回 HTTP 409。
- 客户端校时失败时不得自动回退旧授权 Key，应提示管理员修复系统时间。

## 6. 插件目录与更新

### 6.1 插件目录

`GET /api/instance/v1/plugins/catalog`

只返回当前授权主体可见的商品信息、权益状态、兼容版本和已安装版本可用的更新摘要。未购买插件可以展示商品信息，但不得返回下载凭证或资产地址。

### 6.2 更新检查

`GET /api/instance/v1/plugins/{plugin_id}/updates?current_version={version}&cxpay_version={version}`

云端必须同时校验实例状态、站点授权、插件权益和 CXPAY 兼容范围。响应包含候选版本、变更摘要、最低/最高兼容版本、包哈希和平台签名元数据，不包含下载地址。

## 7. 一次性下载凭证

`POST /api/instance/v1/plugins/{plugin_id}/download-grants`

请求体指定目标版本并携带 `Idempotency-Key`：

```json
{
  "version": "1.2.3",
  "cxpay_version": "1.0.0"
}
```

成功响应的 `data` 只允许包含以下字段：

```json
{
  "grant_token": "dgr_一次性随机令牌",
  "expires_at": "2026-08-08T12:05:00Z",
  "sha256": "插件包 SHA-256",
  "size": 1048576,
  "signature": {
    "algorithm": "Ed25519",
    "key_id": "platform-2026-01",
    "value": "Base64URL 平台签名"
  }
}
```

- 下载凭证有效期最长 5 分钟，只能成功兑换一次，并绑定实例、插件、版本和资产哈希。
- CXPAY 使用固定的 `GET /api/artifact/v1/downloads/{grant_token}` 兑换插件包；令牌不得放入浏览器、日志或分析事件。
- 兑换服务在校验后流式返回插件包，不暴露对象存储永久地址。
- CXPAY 下载后必须校验长度、SHA-256、平台 Ed25519 签名和插件包内部清单签名，全部通过后才允许安装。
- 网络失败时可在凭证未消费且未过期的前提下重试；过期或已消费必须重新申请。

平台插件包签名原文固定为以下五行，行尾不得追加换行：

```text
CXPAY-PLUGIN
PLUGIN_ID
VERSION
SHA256
SIZE
```

其中 `SHA256` 为插件包字节的 SHA-256 小写十六进制值，`SIZE` 为十进制字节数。签名使用 `signature.key_id` 对应的平台 Ed25519 私钥生成。

## 8. 稳定错误响应

错误响应格式：

```json
{
  "code": -1,
  "error_code": "CLOUD_SIGNATURE_INVALID",
  "msg": "请求签名无效",
  "request_id": "req_01...",
  "data": {}
}
```

稳定错误码：

| 错误码 | HTTP | 含义 |
| --- | ---: | --- |
| `CLOUD_INSTANCE_NOT_FOUND` | 404 | 实例不存在或标识错误 |
| `CLOUD_INSTANCE_REVOKED` | 403 | 实例已撤销或冻结 |
| `CLOUD_SIGNATURE_INVALID` | 401 | Ed25519 签名验证失败 |
| `CLOUD_REQUEST_EXPIRED` | 401 | 请求超出 300 秒时间窗 |
| `CLOUD_NONCE_REPLAYED` | 409 | 随机数已使用 |
| `CLOUD_SITE_LICENSE_INACTIVE` | 403 | 域名授权无效、过期或未绑定 |
| `CLOUD_PLUGIN_NOT_ENTITLED` | 403 | 未购买插件或订阅已到期 |
| `CLOUD_PLUGIN_VERSION_INCOMPATIBLE` | 409 | 插件版本与当前 CXPAY 不兼容 |
| `CLOUD_ARTIFACT_NOT_AVAILABLE` | 404 | 插件资产不存在、已撤回或尚未发布 |

客户端只能根据 `error_code` 分支处理，不得解析可变的中文 `msg`。服务端新增错误码可以向后兼容，但不得改变既有错误码的含义。

## 9. 密钥轮换与撤销

- 平台插件签名公钥以 `key_id` 版本化；新旧公钥必须有明确的并行验证窗口。
- 实例私钥丢失时只能从云端工作台撤销旧实例并重新激活，不能通过找回接口导出私钥。
- 实例撤销、域名解绑、权益到期和插件撤回必须立即阻止新下载凭证；已签发但未消费的凭证也应失效。
- 所有激活、撤销、域名变更、权益变化和下载凭证兑换都写入不可变审计记录，但不得记录旧授权 Key、实例私钥或完整下载令牌。
