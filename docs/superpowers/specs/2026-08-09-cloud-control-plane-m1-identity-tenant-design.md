# 云端控制面 M1：身份与租户设计

状态：已确认，等待实施计划。

日期：2026-08-09

关联总体设计：`docs/superpowers/specs/2026-08-08-cloud-control-plane-design.md`

## 1. 背景与目标

M0 已把官方云端服务端能力从 CXPAY 支付节点移除，并固定了支付节点与独立云端控制面的边界。M1 在 `services/cloud-control-plane/` 建立第一个可独立部署的云端服务，交付账号、第三方身份、租户、角色、会话和基础门户入口。

M1 必须实现：

- 邮箱验证码注册和密码登录；
- 注册时强制绑定至少一个 QQ 或微信身份；
- 已绑定 QQ/微信身份的快捷登录；
- 客户、代理商和官方三类租户；
- 固定角色、成员邀请、代理与下级客户关系；
- 门户与官方后台会话隔离；
- TOTP 二次验证、会话轮换和吊销；
- 登录与身份变更审计；
- 客户/代理门户和官方后台的基础可操作入口；
- 独立迁移、测试、Docker 和 CI 边界。

## 2. 非目标

M1 不实现：

- 商品、价格、订单、支付回调、退款和对账；
- 代理预充值余额、双分录账本和授信；
- 域名授权、插件授权、续费、冻结和换绑；
- CXPAY 实例激活和实例签名接口；
- 源码包、更新包、插件制品、水印和下载凭证；
- 完整运营报表、完整业务后台和生产迁移工具。

上述能力分别属于 M2–M5。M1 不以临时字段、模拟成功响应或复用支付节点数据库的方式提前实现它们。

## 3. 实现路线与部署边界

采用“同仓库、独立子项目”路线。云端控制面位于：

```text
services/cloud-control-plane/
├─ app/
│  ├─ Shared/
│  ├─ Identity/
│  ├─ Tenant/
│  ├─ Session/
│  ├─ Audit/
│  ├─ Portal/
│  └─ Ops/
├─ config/
├─ migrations/
├─ public/
├─ bin/
├─ frontend/
│  ├─ portal/
│  ├─ ops/
│  └─ shared/
├─ tests/
│  ├─ Unit/
│  ├─ Integration/
│  ├─ Contract/
│  └─ Frontend/
├─ composer.json
├─ composer.lock
├─ package.json
├─ phpunit.xml
├─ Dockerfile
├─ docker-compose.example.yml
└─ .env.example
```

边界规则：

- 子项目拥有独立 Composer、npm、MySQL 8、Redis 7、环境变量和进程入口。
- 子项目不得加载 CXPAY 根目录的 `config/`、数据库连接、Session、商户数据或密钥。
- CXPAY 支付进程不得自动加载云端控制面的 PHP 类或路由。
- 云端服务不得直接查询 CXPAY 支付数据库；后续支付只能通过版本化 API 对接。
- `cloud.cxpay.com` 和 `ops.cloud.cxpay.com` 分别通过同源 `/api/*` 反向代理访问控制面进程，因此浏览器会话 Cookie 保持精确 Host；`api.cloud.cxpay.com` 只承载实例、回调和服务间 API，不向浏览器签发 Portal/Ops Cookie。
- 子项目可以与 CXPAY 同仓发布，但必须可以从自身目录独立安装依赖、迁移、测试、构建和启动。
- 单个业务文件原则上不超过 300 行；超过时按业务职责拆分。

技术栈固定为 PHP 8.2、Webman 2、MySQL 8、Redis 7、PHPUnit、Vue 3、TypeScript 和 Vite。UUID 使用 UUIDv7。

## 4. 模块职责

### 4.1 Shared

提供 UUIDv7、UTC 时钟、事务边界、请求追踪 ID、稳定错误响应、环境配置和密码/加密基础设施。Shared 不包含用户、租户或会话业务规则。

### 4.2 Identity

负责邮箱规范化、邮箱验证码、密码、QQ/微信 OAuth、第三方身份绑定和解绑、TOTP 设置与验证。Identity 通过接口依赖邮件发送器、OAuth 提供商和审计写入器。

### 4.3 Tenant

负责租户、成员、固定角色、成员邀请、代理商资料和代理—客户关系。所有租户范围仓储都显式接收 `TenantContext`。

### 4.4 Session

负责登录挑战、门户/官方会话、当前租户、Session ID 轮换、空闲/绝对过期和吊销。Redis 保存会话载荷，MySQL 保存会话摘要与审计元数据。

### 4.5 Audit

只追加身份、会话、成员、角色和跨租户查看事件。应用层不提供更新或删除审计事件的仓储方法。

### 4.6 Portal 与 Ops

Portal 是客户和代理共用的 API/页面入口；Ops 是官方人员独立入口。Controller 只解析请求、创建上下文、调用应用服务并转换响应，不持有业务事务。

## 5. 身份规则

### 5.1 邮箱

- 邮箱去除首尾空白，域名按 IDNA ASCII 规范化；展示值保留用户输入形式，`email_canonical` 将整个邮箱转小写。
- `email_canonical` 建立全局唯一索引，注册、登录、邀请和找回密码都使用它比较。
- 公开注册必须先完成邮箱验证码验证。
- 密码长度为 12–128 个 Unicode 字符，使用 Argon2id。
- 找回密码和修改密码后吊销除当前会话外的其他会话；找回密码完成后吊销全部旧会话。

### 5.2 QQ 与微信

- 只对接 QQ 互联和微信开放平台网站应用的官方 OAuth。
- 不提供协议登录、模拟扫码成功或非官方供应商兼容路径。
- 第三方身份使用 `provider + issuer + subject` 建立全局唯一索引。
- 一个用户可以同时绑定 QQ 和微信。
- 同一个 QQ 或微信身份只能绑定一个云端用户。
- 用户不能解绑最后一个可用 QQ/微信身份；必须先绑定另一种身份。
- QQ/微信登录只允许命中已经完成邮箱验证且已绑定该身份的用户，不能自动注册。
- OAuth Access Token 只在回调期间使用，不长期保存。
- 未配置官方 OAuth 凭据时，服务保持可启动，但入口返回 `CLOUD_OAUTH_NOT_CONFIGURED`，不得伪造登录结果。

### 5.3 TOTP

- 使用兼容 RFC 6238 的 6 位、30 秒 TOTP。
- 官方成员启用账号前必须完成 TOTP 设置。
- 客户和代理成员可选择启用；启用后每次新登录都需要 TOTP。
- QQ/微信快捷登录不能替代 TOTP。
- TOTP 密钥使用独立环境密钥通过 XChaCha20-Poly1305 加密保存。
- TOTP 设置先生成待确认密钥，用户提供一个有效动态码后才正式启用。
- 客户或代理成员停用 TOTP 时必须再次验证密码和当前动态码；官方成员不能停用，只能通过恢复流程轮换密钥。

## 6. 用户与注册状态机

用户状态：

```text
PENDING_EMAIL
  └─ 邮箱验证码通过并设置密码 → PENDING_IDENTITY
PENDING_IDENTITY
  └─ QQ 或微信绑定成功 → ACTIVE
ACTIVE
  ├─ 连续登录失败 → LOCKED
  └─ 官方禁用 → DISABLED
LOCKED
  └─ 锁定期结束或授权解锁 → ACTIVE
```

公开注册流程：

1. 请求邮箱验证码，服务端先完成邮箱/IP 限流并用统一响应隐藏账号是否存在。
2. 对可注册邮箱创建 `PENDING_EMAIL` 用户和 `PENDING_DELIVERY` 验证记录；已存在的可用账号不创建注册验证码。
3. 服务端生成 6 位验证码并通过邮件发送器投递。成功后把验证记录切换为 `READY`，失败时切换为 `INVALIDATED`；只有 `READY` 记录可验证。
4. 用户提交邮箱、验证码、显示名称和密码。
5. 验证成功后保存 Argon2id 密码摘要，把用户切换为 `PENDING_IDENTITY`，并创建 15 分钟注册会话。
6. 用户选择 QQ 或微信，服务端生成一次性 OAuth State 并跳转官方授权页面。
7. 回调验证 State、提供商响应和第三方身份唯一性。
8. 在同一 MySQL 事务中绑定身份、把用户切换为 `ACTIVE`、创建 `CUSTOMER` 租户和所有者成员关系。
9. 事务提交后创建门户会话；绑定失败不得创建客户租户。超过 24 小时仍未完成邮箱验证的 `PENDING_EMAIL` 用户由清理命令删除。

公开注册只创建客户租户。代理商由官方审核创建，官方账号只能由超级管理员邀请。

邀请接受沿用同一身份门槛：邀请 Token 校验成功后，用户必须验证邀请邮箱、设置或验证密码并绑定 QQ/微信。代理和客户成员完成这些步骤后激活成员关系；官方成员还必须设置 TOTP，完成前成员保持 `PENDING_MFA`。邀请 Token 不进入 OAuth 回跳 URL，服务端只在短期邀请会话中保存其摘要。

## 7. 租户、成员与代理关系

租户类型：

- `OFFICIAL`：官方运营组织；
- `AGENT`：代理商组织；
- `CUSTOMER`：最终授权客户组织。

角色：

| 租户类型 | 角色 |
| --- | --- |
| OFFICIAL | `SUPER_ADMIN`、`OPERATIONS`、`FINANCE`、`ARTIFACT_PUBLISHER`、`SUPPORT`、`AUDITOR` |
| AGENT | `OWNER`、`ADMIN`、`FINANCE`、`OPERATOR`、`VIEWER` |
| CUSTOMER | `OWNER`、`ADMIN`、`DEVELOPER`、`FINANCE`、`VIEWER` |

规则：

- 用户可以属于多个租户，但每次门户请求必须存在唯一当前租户。
- 用户只有一个有效租户时自动选择；有多个时必须选择。
- 切换当前租户必须验证成员仍有效，并轮换 Session ID。
- 代理商所有者和管理员可以邀请内部成员、创建下级客户租户并邀请客户成员。
- 代理商不能替客户设置密码、读取密码摘要或绑定 QQ/微信。
- 每个客户租户同一时间最多存在一个有效上级代理关系；历史关系保留起止时间。
- 官方人员查看客户敏感详情必须提交原因并形成审计事件，不提供模拟登录。
- 角色是 M1 固定枚举，不实现自定义角色和可编辑权限模板。
- 官方邀请对应的成员状态在完成邮箱验证和身份绑定后为 `PENDING_MFA`，完成 TOTP 后才能变为 `ACTIVE`；用户已经属于其他租户时，不改变其全局用户状态。

## 8. 数据模型

所有时间字段使用 UTC `DATETIME(6)`。所有业务主键为 UUIDv7 字符串。外键、唯一约束和状态检查由迁移定义。

### 8.1 cloud_users

关键字段：

- `id`；
- `email`、`email_canonical`；
- `display_name`，`PENDING_EMAIL` 阶段允许为空；
- `password_hash`，`PENDING_EMAIL` 阶段允许为空；
- `status`；
- `email_verified_at`；
- `totp_secret_ciphertext`、`totp_secret_nonce`、`totp_enabled_at`；
- `failed_login_count`、`locked_until`；
- `last_login_at`、`last_login_ip`；
- `created_at`、`updated_at`。

约束：`email_canonical` 全局唯一；未确认 TOTP 时正式密文字段为空。

### 8.2 cloud_user_identities

关键字段：`id`、`user_id`、`provider`、`issuer`、`subject`、`display_name`、`avatar_url`、`bound_at`、`last_login_at`、`created_at`。

约束：`provider + issuer + subject` 全局唯一，`user_id + provider + issuer` 唯一。

### 8.3 cloud_email_verifications

关键字段：`id`、`email_canonical`、`purpose`、`delivery_status`、`code_digest`、`attempts`、`expires_at`、`consumed_at`、`requested_ip`、`created_at`。

`delivery_status` 为 `PENDING_DELIVERY`、`READY` 或 `INVALIDATED`。验证码有效期 10 分钟，最多验证 5 次，只能成功消费一次；投递失败的记录不能验证。

### 8.4 cloud_tenants

关键字段：`id`、`type`、`name`、`status`、`created_by_user_id`、`created_at`、`updated_at`。

租户状态为 `ACTIVE`、`SUSPENDED` 或 `DISABLED`。

### 8.5 cloud_tenant_members

关键字段：`id`、`tenant_id`、`user_id`、`role`、`status`、`joined_at`、`created_at`、`updated_at`。成员状态为 `INVITED`、`PENDING_MFA`、`ACTIVE`、`SUSPENDED` 或 `REMOVED`。

约束：`tenant_id + user_id` 唯一；角色必须属于租户类型允许集合。

### 8.6 cloud_tenant_relations

关键字段：`id`、`agent_tenant_id`、`customer_tenant_id`、`status`、`effective_from`、`effective_until`、`created_by_user_id`、`created_at`。

约束：父租户必须为 `AGENT`，子租户必须为 `CUSTOMER`；客户同一时刻最多一个有效代理关系。

### 8.7 cloud_agent_profiles

关键字段：`tenant_id`、`status`、`level_code`、`credit_status`、`created_at`、`updated_at`。M1 只维护状态，价格、折扣、余额和授信逻辑由 M2 实现。

### 8.8 cloud_sessions

关键字段：

- `id`、`token_digest`、`user_id`；
- `audience`：`PORTAL` 或 `OPS`；
- `current_tenant_id`；
- `ip_address`、`user_agent`；
- `last_activity_at`、`idle_expires_at`、`absolute_expires_at`；
- `revoked_at`、`revoke_reason`、`created_at`。

数据库只保存 Token 摘要。Redis Key 按 audience 分区。

### 8.9 cloud_invitations

关键字段：`id`、`tenant_id`、`email_canonical`、`role`、`token_digest`、`status`、`expires_at`、`accepted_at`、`invited_by_user_id`、`created_at`。

邀请 Token 有效期 72 小时，只能使用一次。被邀请人仍需验证邮箱并绑定 QQ/微信；官方成员还需启用 TOTP。

### 8.10 cloud_audit_events

关键字段：`id`、`occurred_at`、`actor_user_id`、`actor_tenant_id`、`event_type`、`target_type`、`target_id`、`reason`、`request_id`、`ip_address`、`metadata_json`。

应用仓储只有 `append()`；`metadata_json` 不得包含密码、验证码、OAuth Code、Access Token、TOTP 密钥、Session Token 或完整第三方 Subject。

## 9. 会话设计

### 9.1 门户会话

- Cookie 名：`cxpay_cloud_portal_session`；
- 精确 Host：`cloud.cxpay.com`；
- 空闲 2 小时，绝对有效期 12 小时；
- 用户选择记住登录时绝对有效期 30 天；
- `Secure`、`HttpOnly`、`SameSite=Lax`。

### 9.2 官方会话

- Cookie 名：`cxpay_cloud_ops_session`；
- 精确 Host：`ops.cloud.cxpay.com`；
- 空闲 30 分钟，绝对有效期 8 小时；
- 禁止记住登录；
- `Secure`、`HttpOnly`、`SameSite=Lax`。

### 9.3 通用规则

- Session Token 为 32 字节密码学安全随机值。
- 登录、租户切换、修改密码、绑定或解绑 QQ/微信、启用或禁用 TOTP 后轮换 Session ID。
- 所有写请求使用会话绑定的 CSRF Token，并通过 `X-CSRF-Token` 请求头提交。
- 修改密码后吊销其他会话；密码找回后吊销全部旧会话。
- 会话验证同时检查 Redis 载荷、MySQL 元数据、用户状态、成员状态和超时。
- 门户 Cookie 不能访问 Ops API，Ops Cookie 不能访问 Portal API。

## 10. OAuth 适配器

定义统一接口：

```php
interface OAuthProvider
{
    public function name(): string;
    public function authorizationUrl(OAuthState $state): string;
    public function exchangeCallback(OAuthCallback $callback): ExternalIdentity;
}
```

生产实现只有 `QqConnectOAuthProvider` 和 `WechatOpenPlatformOAuthProvider`。测试使用 `FakeOAuthProvider`，假实现只存在测试命名空间并且不能被生产配置加载。

OAuth State 存 Redis，包含随机值摘要、用途 `REGISTER_BIND`/`ACCOUNT_BIND`/`LOGIN`、用户或注册会话 ID、回跳入口和过期时间。State 10 分钟有效，只能消费一次。回调必须先原子消费 State，再交换授权码。

Portal 与 Ops 使用不同 OAuth Redirect URI。`audience` 是服务端由路由确定的固定值，客户端不能通过请求体把 Portal 登录提升为 Ops 登录。

## 11. API 分区与主要接口

### 11.1 Public

```text
POST   /api/public/v1/email-codes
POST   /api/public/v1/registrations
POST   /api/public/v1/password-resets
POST   /api/public/v1/portal/invitations/accept
POST   /api/public/v1/ops/invitations/accept
GET    /api/public/v1/oauth/portal/{provider}/authorize
GET    /api/public/v1/oauth/portal/{provider}/callback
GET    /api/public/v1/oauth/ops/{provider}/authorize
GET    /api/public/v1/oauth/ops/{provider}/callback
POST   /api/public/v1/portal/sessions/password
POST   /api/public/v1/portal/sessions/totp
POST   /api/public/v1/ops/sessions/password
POST   /api/public/v1/ops/sessions/totp
```

`email-codes` 请求体中的 `purpose` 只允许 `REGISTER` 或 `RESET_PASSWORD`。密码重置成功后吊销全部旧会话且不自动登录。Portal 与 Ops 登录分别生成不同 audience 的短期 TOTP 挑战，挑战不能跨入口使用。

### 11.2 Portal

```text
GET    /api/portal/v1/me
GET    /api/portal/v1/tenants
POST   /api/portal/v1/tenants/current
GET    /api/portal/v1/members
POST   /api/portal/v1/invitations
GET    /api/portal/v1/security/identities
POST   /api/portal/v1/security/identities/{provider}/bind
DELETE /api/portal/v1/security/identities/{provider}
POST   /api/portal/v1/security/totp/setup
POST   /api/portal/v1/security/totp/confirm
DELETE /api/portal/v1/security/totp
GET    /api/portal/v1/security/sessions
POST   /api/portal/v1/security/sessions/revoke-others
DELETE /api/portal/v1/session
PATCH  /api/portal/v1/members/{member_id}
DELETE /api/portal/v1/members/{member_id}
```

代理租户额外提供：

```text
GET  /api/agent/v1/customers
POST /api/agent/v1/customers
POST /api/agent/v1/customers/{tenant_id}/invitations
```

### 11.3 Ops

```text
GET  /api/ops/v1/me
GET  /api/ops/v1/users
GET  /api/ops/v1/tenants
GET  /api/ops/v1/agents
POST /api/ops/v1/invitations
POST /api/ops/v1/agents
GET  /api/ops/v1/security/sessions
POST /api/ops/v1/security/sessions/revoke-others
DELETE /api/ops/v1/session
```

跨租户详情接口必须接收非空 `reason` 并审计。M1 不提供官方模拟登录接口。

## 12. 权限检查

权限由 `AuthorizationService` 的固定权限映射判定。Controller 和仓储不得直接使用角色字符串决定访问。

M1 权限映射：

| 租户 | 角色 | M1 权限 |
| --- | --- | --- |
| CUSTOMER | OWNER | 查看租户、管理成员与邀请、管理自身安全设置 |
| CUSTOMER | ADMIN | 查看租户、管理 OWNER 之外的成员与邀请、管理自身安全设置 |
| CUSTOMER | DEVELOPER / FINANCE / VIEWER | 查看租户和成员、管理自身安全设置 |
| AGENT | OWNER | 查看代理租户、管理成员与邀请、创建和查看下级客户、管理自身安全设置 |
| AGENT | ADMIN | 管理 OWNER 之外的成员与邀请、创建和查看下级客户、管理自身安全设置 |
| AGENT | OPERATOR | 创建和查看下级客户、查看成员、管理自身安全设置 |
| AGENT | FINANCE / VIEWER | 查看租户、成员和下级客户、管理自身安全设置 |
| OFFICIAL | SUPER_ADMIN | 管理官方成员、用户、租户、代理商和跨租户查看 |
| OFFICIAL | OPERATIONS | 查询用户/租户/代理商、创建代理商、执行带原因的跨租户查看 |
| OFFICIAL | SUPPORT | 查询用户/租户/代理商、执行带原因的跨租户查看 |
| OFFICIAL | FINANCE / ARTIFACT_PUBLISHER / AUDITOR | 只读查询 M1 用户、租户和代理商元数据 |

所有角色都只能修改自己的密码、第三方身份和可选 TOTP；角色或成员管理不能修改租户 OWNER，OWNER 转移不属于 M1。官方 `AUDITOR` 只能读取审计和元数据，不能创建邀请。

应用服务输入包含：

```text
ActorContext(user_id, session_id, audience)
TenantContext(tenant_id, tenant_type, membership_id, role)
RequestContext(request_id, ip_address, user_agent)
```

租户仓储方法必须接收 `TenantContext`；不存在空上下文查询全部租户数据的重载。官方全局列表通过专用 Ops 仓储实现，不能复用租户范围仓储绕过过滤。

首个官方组织通过 `bin/cloud-admin bootstrap --email=<address>` 引导：命令只在不存在官方超级管理员时创建唯一 `OFFICIAL` 租户和 72 小时超级管理员邀请，并只显示一次邀请 Token；它不能直接写入密码、第三方身份或 TOTP 密钥。后续官方成员全部由超级管理员邀请。

## 13. 限流与安全

- 邮箱验证码：同一邮箱 60 秒内最多 1 次、每小时最多 5 次；同一 IP 每小时最多 20 次。
- 密码登录：同一规范化邮箱或 IP 在 15 分钟内最多 5 次失败；超过后锁定 15 分钟。
- OAuth State：同一会话 10 分钟内最多创建 10 个未消费 State。
- TOTP：同一登录挑战 5 分钟内最多验证 5 次。
- 注册、登录和找回接口对“用户不存在”和“密码错误”返回相同安全提示。
- 邮箱验证码只有邮件投递成功后才进入可用状态；发送器失败返回可重试错误。
- 所有数据库事务使用明确的应用服务边界，Controller 不开启事务。
- 任何响应和日志不得包含 SQL、堆栈、内部密钥、验证码、OAuth Code、Access Token、TOTP 密钥或 Session Token。

## 14. 稳定错误响应

```json
{
  "code": -1,
  "error_code": "CLOUD_IDENTITY_ALREADY_BOUND",
  "msg": "该第三方账号已绑定其他用户",
  "request_id": "req_01...",
  "retryable": false,
  "data": {}
}
```

M1 固定错误码：

```text
CLOUD_EMAIL_CODE_INVALID
CLOUD_EMAIL_CODE_EXPIRED
CLOUD_EMAIL_DELIVERY_FAILED
CLOUD_REGISTRATION_INCOMPLETE
CLOUD_INVITATION_INVALID
CLOUD_IDENTITY_NOT_BOUND
CLOUD_IDENTITY_ALREADY_BOUND
CLOUD_LAST_IDENTITY_REQUIRED
CLOUD_CREDENTIALS_INVALID
CLOUD_ACCOUNT_LOCKED
CLOUD_TOTP_REQUIRED
CLOUD_TOTP_SETUP_REQUIRED
CLOUD_TOTP_INVALID
CLOUD_SESSION_EXPIRED
CLOUD_TENANT_REQUIRED
CLOUD_TENANT_ACCESS_DENIED
CLOUD_PERMISSION_DENIED
CLOUD_OAUTH_NOT_CONFIGURED
CLOUD_OAUTH_STATE_INVALID
CLOUD_RATE_LIMITED
```

客户端只根据 `error_code` 分支，不解析中文 `msg`。未知服务端错误返回统一 `CLOUD_INTERNAL_ERROR` 和请求追踪 ID。

## 15. 审计事件

M1 至少记录：

- 邮箱验证成功和失败限流；
- 注册完成；
- 密码、QQ、微信登录成功或失败；
- TOTP 验证、启用和停用；
- QQ/微信绑定和解绑；
- 会话创建、轮换、吊销和过期；
- 当前租户切换；
- 租户、成员、邀请、角色和代理关系变更；
- 官方人员跨租户查看及其原因。

失败事件不得通过错误消息泄露账号是否存在。审计中的第三方 Subject 只保存不可逆摘要或末尾掩码。

## 16. 基础前端

使用 npm workspaces 管理 `portal`、`ops` 和 `shared`。

Portal 页面：

- 邮箱注册、验证码和密码设置；
- QQ/微信绑定与快捷登录；
- TOTP 设置与验证；
- 租户选择与切换；
- 当前账号、第三方身份和登录设备；
- 客户/代理基础工作台外壳；
- 成员邀请和代理下级客户基础入口。

Ops 页面：

- 独立登录和 TOTP；
- 用户、租户和代理商基础查询；
- 官方成员邀请和代理商创建；
- 需要原因的客户详情查看入口。

Shared 提供类型化 API 客户端、稳定错误码映射、CSRF、表单控件和身份组件。M1 不制作商品、订单、授权、制品和下载页面。

Portal 和 Ops 前端只通过各自站点的同源 `/api/*` 调用后端；反向代理保留原始 Host，并禁止 Portal 站点代理 Ops 专用路径、Ops 站点代理 Portal/Agent 专用路径。

## 17. 配置与部署

独立环境变量至少包括：

```text
CLOUD_APP_ENV
CLOUD_APP_KEY
CLOUD_PORTAL_URL
CLOUD_OPS_URL
CLOUD_DB_HOST
CLOUD_DB_PORT
CLOUD_DB_DATABASE
CLOUD_DB_USERNAME
CLOUD_DB_PASSWORD
CLOUD_REDIS_HOST
CLOUD_REDIS_PORT
CLOUD_REDIS_PASSWORD
CLOUD_REDIS_DB
CLOUD_SESSION_HMAC_KEY
CLOUD_EMAIL_CODE_PEPPER
CLOUD_TOTP_ENCRYPTION_KEY
CLOUD_SMTP_HOST
CLOUD_SMTP_PORT
CLOUD_SMTP_USERNAME
CLOUD_SMTP_PASSWORD
CLOUD_SMTP_FROM
CLOUD_QQ_CLIENT_ID
CLOUD_QQ_CLIENT_SECRET
CLOUD_QQ_PORTAL_REDIRECT_URI
CLOUD_QQ_OPS_REDIRECT_URI
CLOUD_WECHAT_APP_ID
CLOUD_WECHAT_APP_SECRET
CLOUD_WECHAT_PORTAL_REDIRECT_URI
CLOUD_WECHAT_OPS_REDIRECT_URI
```

`/health` 只检查进程存活。身份服务进程在外部能力缺失时仍可启动，以便输出诊断信息；`/ready` 必须检查 MySQL、Redis、迁移版本、必要加密配置、SMTP，以及 QQ/微信至少一个提供商可用。仅缺少其中一个 OAuth 提供商时可保持就绪，但对应入口返回 `CLOUD_OAUTH_NOT_CONFIGURED`；SMTP 缺失或两个 OAuth 提供商都缺失时不允许进入生产流量。

`docker-compose.example.yml` 使用独立服务名、网络和卷，不引用根目录 CXPAY 的 MySQL 或 Redis。生产环境不在 Compose 文件中硬编码任何密钥。

## 18. 版本化迁移

- 使用 `cloud_schema_migrations` 记录版本、校验和和执行时间。
- 迁移执行器获取 MySQL 命名锁，避免并发迁移。
- 已执行迁移的文件校验和变化时拒绝启动迁移。
- 每个迁移只执行一次，不使用散落手工 SQL 补丁。
- M1 首批迁移创建本设计第 8 节列出的表、索引和外键。
- 迁移测试在 MySQL 8 空库执行，并验证重复执行不会修改已完成版本。

## 19. 测试策略

### 19.1 单元测试

- 邮箱规范化与密码策略；
- 邮箱验证码摘要、过期和尝试次数；
- OAuth State 一次性消费；
- QQ/微信身份唯一性规则；
- TOTP 加密、设置和验证；
- 门户/官方会话时效、轮换和 Cookie 属性；
- 固定角色权限和最后身份解绑保护。

### 19.2 MySQL 集成测试

- 邮箱、第三方身份和成员唯一约束；
- 注册激活事务成功与回滚；
- 客户租户只在身份绑定成功后创建；
- 成员邀请和一次性接受；
- 一个客户最多一个有效代理关系；
- 所有跨租户仓储访问被拒绝；
- 迁移锁、版本和校验和。

### 19.3 Redis 集成测试

- 邮箱验证码限流；
- OAuth State 原子消费；
- 会话轮换、空闲/绝对过期和吊销；
- 门户与官方会话命名空间隔离。

### 19.4 API 契约测试

- 邮箱注册、QQ/微信绑定和快捷登录；
- 未绑定身份不能激活；
- TOTP 挑战；
- 多租户选择和切换；
- 身份解绑保护；
- 稳定错误格式和请求追踪 ID；
- Portal Cookie 不能访问 Ops，Ops Cookie 不能访问 Portal。

### 19.5 前端与端到端测试

- 关键表单状态、错误码映射和 CSRF；
- 邮箱注册并绑定 QQ 或微信；
- 已绑定身份快捷登录；
- 多租户选择和切换；
- 官方邀请、强制 TOTP 和独立会话。

测试 OAuth 使用只存在于测试命名空间的假适配器。生产配置扫描必须证明无法加载假实现。

## 20. 验收标准

M1 只有同时满足以下条件才算完成：

1. `services/cloud-control-plane/` 能独立安装、迁移、测试、构建和启动。
2. 云端服务不读取 CXPAY 支付节点数据库、Redis、Session 或密钥。
3. 邮箱未验证或未绑定 QQ/微信的用户不能变为 `ACTIVE`。
4. QQ/微信身份不能自动创建账号，也不能绑定多个用户。
5. 用户不能解绑最后一个 QQ/微信身份。
6. 公开注册只创建客户租户；代理和官方账号只能按邀请流程创建。
7. 官方账号必须启用 TOTP，QQ/微信不能替代二次验证。
8. 门户和官方会话 Cookie、Redis Key、时效和中间件完全隔离。
9. 多租户请求必须显式选择当前租户，跨租户访问全部拒绝。
10. 登录、身份、会话、租户和成员变更均形成不含敏感明文的审计事件。
11. 未配置 QQ、微信或 SMTP 时返回明确错误，不存在模拟成功路径。
12. PHP、迁移、MySQL/Redis 集成、API 契约、Vue 构建和关键端到端测试全部通过。

## 21. 已确认决策

- 邮箱可以公开注册，但必须验证邮箱并绑定至少一个 QQ 或微信后账号才启用。
- 邮箱全局唯一；同一 QQ/微信身份只能绑定一个用户；一个用户可以同时绑定两种身份。
- 不能解绑最后一个第三方身份。
- 公开注册只创建客户；代理由官方审核创建；官方账号由超级管理员邀请。
- 代理可以创建下级客户和邀请成员，但不能替客户设置密码或绑定身份。
- 客户和代理共用门户登录和租户选择；官方后台使用独立会话。
- QQ/微信登录只登录已绑定用户，不自动注册。
- 只接 QQ 互联和微信开放平台官方 OAuth，未配置时明确停用。
- 门户会话空闲 2 小时、最长 12 小时、可记住 30 天；官方会话空闲 30 分钟、最长 8 小时且不能记住。
- 官方账号强制 TOTP，客户和代理可选；QQ/微信不能替代 TOTP。
- 单租户自动进入，多租户必须选择；切换租户轮换 Session ID。
- 采用同仓库内的独立 Webman 子项目，不复用 CXPAY 根项目运行时。
