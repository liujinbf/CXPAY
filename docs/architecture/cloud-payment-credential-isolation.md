# ADR-PAY-001：云端支付凭据隔离

状态：Accepted  
日期：2026-08-06  
适用范围：所有支付宝、微信、QQ 等个人码云监控连接器及其云端支付服务

## 1. 背景

CXPAY 支持通过可安装支付插件连接外部云端支付服务。云端服务可以负责支付账号扫码登录、网页登录态维护、账单监控、订单匹配和到账事件推送。

这类方案的原始设计目标是：

- Cookie 和网页登录态不进入 CXPAY；
- 高风险账号登录及账单监控逻辑隔离在云端；
- CXPAY 只安装一个轻量本地连接器；
- CXPAY 只接收最小授权状态、不可登录账号引用和签名到账事件。

当前 `plugins-src/alipay-scan-monitor` 的 README 已表达这一目标，但驱动元数据后来加入了 `cookie_base64` 输入，允许用户在 CXPAY 后台粘贴 Cookie，违反了原始安全边界。本 ADR 用于消除歧义，并把该边界提升为测试和代码审查门禁。

## 2. 决策

### 2.1 核心不变量

> 支付宝、微信等支付账号的 Cookie、网页登录 Token、浏览器存储、可复用会话和设备登录凭据，只能存在于隔离的云端支付服务中，绝不能进入 CXPAY。

该规则不是建议，也不能通过“高级配置”“兼容旧用户”或“临时排障”绕过。

### 2.2 角色划分

#### 云端支付服务

负责：

- 扫码登录；
- Cookie、Token、设备登录态的获取、加密保存和续期；
- 账单读取或到账监听；
- 订单登记和账单匹配；
- 授权会话管理；
- 签名响应和签名到账回调；
- 异常账单审查和运维状态。

#### CXPAY 本地连接器插件

负责：

- 创建云端授权会话；
- 展示云端返回的短期授权二维码；
- 轮询授权状态；
- 保存不可用于登录的 `account_id`；
- 向云端登记订单；
- 验证云端响应和回调签名；
- 转换为 CXPAY 标准支付驱动结果。

本地连接器不得执行支付账号网页登录、Cookie 获取、浏览器自动化、账单抓取或风控绕过。

#### 内置官方支付驱动

支付宝开放平台、微信支付等官方商户 API 驱动不依赖网页登录 Cookie，采用独立凭据模型。

例如支付宝 `app_auth_token` 属于正式开放平台商家授权令牌，不是个人网页登录 Cookie；它可以由官方驱动在 CXPAY 中加密保存，但必须遵守密钥隔离、最小权限和审计要求。

## 3. 禁止进入 CXPAY 的数据

包括但不限于：

```text
cookie
cookie_base64
cookies
set-cookie
browser_cookie
web_cookie
session_token
login_token
web_token
device_token
browser_storage
local_storage
session_storage
web_session
```

禁止进入以下任何位置：

- 管理端或商户端表单；
- HTTP API 请求和响应；
- 数据库；
- Redis；
- 队列消息；
- 日志和异常堆栈；
- 审计事件；
- 浏览器 localStorage 或 sessionStorage；
- 临时文件；
- 插件注册表；
- 调试导出包。

云端响应中的 `Set-Cookie` 必须在公共 HTTP 客户端边界被丢弃，不能透传到控制器、浏览器或日志。

## 4. 允许保存的最小数据

CXPAY 可以保存：

```text
provider_id
account_id
authorization_session_id
authorization_status
qr_url
expires_at
capability_status
last_seen_at
operations_status
```

要求：

- `account_id` 由云端生成，长度和随机性足以防猜测；
- `account_id` 不能是支付账号、手机号、邮箱或 Cookie 的可逆编码；
- `account_id` 不能用于直接登录支付平台；
- `qr_url` 只能用于短期授权，过期后不可复用；
- CXPAY 不保存二维码中可能包含的原始会话凭据，只保存展示所需最小值和到期时间；
- 授权结束后，云端会话必须失效。

## 5. 标准授权流

```text
CXPAY 本地连接器
        │ POST /v1/auth-sessions
        ▼
云端支付服务
        │ 返回 session_id、qr_url、expires_at
        ▼
CXPAY 展示二维码
        │ 商户使用支付 App 扫码确认
        ▼
云端服务直接获得并保存登录态
        │ CXPAY 轮询 session_id
        ▼
返回 CONFIRMED、account_id
```

禁止的替代流程：

```text
支付平台 Cookie
        ↓
商户浏览器
        ↓
CXPAY 后台表单或 API
        ↓
CXPAY 数据库
        ↓
云端支付服务
```

不得提供手工粘贴 Cookie、Base64 Cookie、浏览器导出 Cookie 或开发者模式导入会话的入口。

## 6. 插件强制声明

云端连接器必须声明：

```json
{
  "runtime_type": "cloud_connector",
  "credential_boundary": "cloud_only",
  "cloud_protocol": "cxpay-cloud-payment-v1"
}
```

含义：

- `runtime_type=cloud_connector`：本地代码只做云服务协议适配；
- `credential_boundary=cloud_only`：支付账号登录凭据只能存在云端；
- `cloud_protocol`：使用受版本控制的标准协议。

插件不得通过其他字段名、嵌套 JSON、通用 textarea、隐藏输入或自由扩展字段绕过凭据边界。

## 7. 运行时和供应链边界

`.cxpay-plugin` 是在 CXPAY PHP 进程中执行的代码，不是安全沙箱。

发布者签名和文件哈希只能证明：

- 包来自受信任发布者；
- 包内容安装后未被篡改。

它们不能限制插件读取环境变量、访问文件系统、访问数据库或任意发起网络请求。

因此第一阶段执行：

- 默认只信任 `cxpay.official`；
- 插件安装后默认停用；
- 启用前审核 manifest 权限；
- 连接器代码保持极小；
- 网络请求必须使用公共 `CloudConnectorHttpClient`；
- 插件不得自行创建 Guzzle、cURL 或 stream HTTP 客户端；
- 第三方发布者接入前必须经过代码审核和独立信任决策。

## 8. 网络约束

公共云连接器客户端必须强制：

- HTTPS；
- 443 端口；
- 禁止 URL 用户名和密码；
- 禁止重定向；
- 禁止 IP 字面量服务地址；
- 域名属于批准的服务商白名单；
- 所有 A/AAAA 结果均为公网地址；
- 实际连接固定到已验证解析结果，同时保留原 Host 和 TLS SNI；
- 限制连接超时、总超时和响应体大小；
- 丢弃上游 `Set-Cookie`；
- 响应签名通过前不解析业务数据；
- 日志不包含原始认证头和敏感响应体。

## 9. 自动化门禁

### 9.1 Manifest 校验

当 `credential_boundary=cloud_only` 时，配置字段名、别名、标题或说明中出现以下语义必须拒绝安装或启用：

```text
cookie
session token
login token
browser storage
local storage
web session
```

密码字段必须在 `secret_config` 中明确声明。

### 9.2 驱动元数据校验

启用前检查 `getMeta()['inputs']`：

- 不允许 Cookie 或网页登录凭据输入；
- 不允许引导用户粘贴 Cookie；
- 不允许通用隐藏字段承载登录态；
- manifest 和驱动元数据必须一致。

### 9.3 源码扫描

官方插件 CI 至少扫描：

```text
cookie_base64
document.cookie
Set-Cookie
localStorage
sessionStorage
```

命中后必须人工确认；对连接器生产代码的直接使用默认失败。

### 9.4 API 与存储测试

必须验证：

- 授权会话响应不含 Cookie；
- 授权轮询结果不含 Cookie；
- 通道配置不含 Cookie；
- 后台 API 不含 Cookie；
- 数据库和 Redis 不含 Cookie；
- 日志脱敏器不记录 Cookie；
- `Set-Cookie` 不向 CXPAY 上层传播；
- 插件不能通过附加字段返回 Cookie。

### 9.5 代码审查清单

相关 PR 必须确认：

```text
[ ] 本变更未使 Cookie 或网页登录凭据进入 CXPAY
[ ] 本变更未增加手工粘贴 Cookie 的入口
[ ] 授权流程只返回不可登录的 account_id 等引用
[ ] 所有云端通信使用公共安全客户端
[ ] 回调具有签名、事件唯一性和防重放保护
```

## 10. 已知偏差与修复要求

`plugins-src/alipay-scan-monitor/src/Driver.php` 当前存在 `cookie_base64` 输入及“手动粘贴 Base64 Cookie”的说明。

该偏差必须在插件进入生产前修复：

1. 删除字段和相关文案；
2. manifest 不再出现任何 Cookie 配置；
3. 授权只通过云端会话二维码完成；
4. 增加防回归测试；
5. 对已有保存值执行安全清理方案，不在日志或迁移输出中打印其内容。

## 11. 结果与代价

### 正面结果

- CXPAY 核心不持有个人支付账号登录态；
- 页面变化和登录维护集中在云端服务；
- 支付核心和高风险账单监控逻辑隔离；
- 可以形成受签名保护的连接器插件生态；
- 后续支付宝和微信插件拥有统一边界。

### 代价

- 云端服务成为个人码支付结果的关键受信任方；
- CXPAY 只能证明受信任云服务确认到账，不能证明支付平台官方直接通知；
- 云服务需要独立的凭据库、租户隔离、审计和密钥轮换；
- 插件 PHP 代码仍具有进程内权限，因此必须严格控制发布者；
- 需要建设公共安全 HTTP 客户端和协议一致性测试。

## 12. 不允许的例外

以下理由不能作为绕过本 ADR 的依据：

- 临时排障；
- 兼容旧版本；
- 高级用户手工配置；
- 云端服务故障时的备用登录；
- 内部测试环境；
- Base64、加密或脱敏后不算 Cookie。

测试环境也不得让 Cookie 经过 CXPAY。测试应使用模拟云端服务、模拟账号引用和合成事件。
