# AdminController 模块化拆分设计

## 1. 背景

`app/controller/admin/AdminController.php` 当前约 959 行，同时承担管理员认证、仪表盘统计、平台通道配置、商户管理、人工补单、主页模板、安全设置和已经废弃的 Git 更新入口。类的依赖、数据库访问和响应逻辑相互交织，任何局部修改都需要理解整份文件，且缺少能够证明路由所有权和响应契约不变的专项测试。

本阶段位于管理后台、商户中心前端模块化之后，是既有《CXPAY 超长文件模块化拆分设计》的第五步。`OrderService` 正由 `codex/order-core-reliability` 工作树修改，因此本阶段只处理 `AdminController`，避免把两个高风险后端重构混入同一交付。

## 2. 目标

1. 删除 `AdminController`，将仍在使用的管理员接口迁移到单一业务资源 Controller。
2. 保持全部现有 URL、HTTP 方法、中间件、请求字段、Session 行为和 JSON 响应语义不变。
3. 先用契约测试冻结路由所有权，再逐组迁移方法和路由。
4. 每个新增或扩展后的生产 PHP 文件原则上不超过 300 行。
5. 删除已经由 `SystemUpdateController` 替代且没有路由引用的旧 Git 更新实现。
6. 不修改、不提交用户未跟踪文件 `CXPAY.rar`。

## 3. 非目标

- 不修改管理员登录协议、Token 格式、二次验证码规则或登录限频策略。
- 不改变平台通道校验、敏感配置掩码、加密格式或驱动调用方式。
- 不改变商户开户、密码哈希、API 密钥、费率和 IP 白名单规则。
- 不重构 `OrderService`、`MerchantApiController`、安装流程或云监控服务。
- 不把本阶段扩展为统一响应框架、数据库仓储层或全项目依赖注入改造。
- 不保留新的 `AdminController` 兼容门面，也不使用 Trait 隐藏原有大类。

## 4. 方案选择

采用“行为等价、按资源拆分、逐条迁移路由”的渐进式方案。第一阶段完整迁移现有有效方法，优先建立清晰的类边界；只有已经存在复用边界的依赖继续调用现有 Service，不在同一阶段强行把所有数据库语句包装成新的应用服务。

未采用以下方案：

- 一次性拆 Controller 和全部应用服务：最终结构更薄，但同时改变类边界、调用边界和返回数据边界，回归定位困难。
- 保留 `AdminController` 作为门面：可以减少路由变更，但会长期保留双重入口和废弃代码，无法达到删除大类的目标。
- 通过 Trait 拆文件：只减少单文件行数，不减少对象职责和依赖耦合。

## 5. 目标结构

| 目标文件 | 迁移或保留职责 |
| --- | --- |
| `app/controller/admin/AdminAuthController.php` | `login`、`verifyLoginCode`、Token 签发、`logout` |
| `app/controller/admin/AdminDashboardController.php` | `dashboard`、统计缓存和监控降级 |
| `app/controller/admin/AdminChannelConfigController.php` | `getChannelConfig`、`listChannels`、`saveChannelConfig`、敏感字段判断 |
| `app/controller/admin/AdminMerchantController.php` | `listMerchants`、`saveMerchant` |
| `app/controller/admin/AdminSecurityController.php` | `getSecurityConfig`、`saveSecurityConfig` |
| `app/controller/admin/MerchantTemplateController.php` | `saveTemplate` |
| `app/controller/admin/OrderAdminController.php` | 保留订单列表和关闭，并接收 `forceNotifyOrder` |
| `app/controller/admin/SystemUpdateController.php` | 继续独立负责系统更新，不接收旧实现 |
| `app/controller/admin/AdminController.php` | 全部有效路由迁移后删除 |

已有 `ChannelAdminController` 继续只负责驱动配置输入定义接口 `/api/admin/channel/inputs`。它与 `AdminChannelConfigController` 的边界分别是“驱动元数据”和“平台通道实例配置”，不互相继承。

## 6. 路由迁移

### 6.1 公开认证接口

以下路由只替换 Controller 类名，HTTP 方法和路径保持不变：

| HTTP | URL | 新目标 |
| --- | --- | --- |
| POST | `/api/admin/login` | `AdminAuthController::login` |
| POST | `/api/admin/login/verify` | `AdminAuthController::verifyLoginCode` |
| POST | `/api/admin/logout` | `AdminAuthController::logout` |

### 6.2 管理员认证组接口

以下路由继续位于 `AdminAuthMiddleware` 保护的 `/api/admin` 路由组内：

| HTTP | URL | 新目标 |
| --- | --- | --- |
| ANY | `/dashboard` | `AdminDashboardController::dashboard` |
| GET | `/channel/list` | `AdminChannelConfigController::listChannels` |
| POST | `/channel/save` | `AdminChannelConfigController::saveChannelConfig` |
| GET | `/channel/get` | `AdminChannelConfigController::getChannelConfig` |
| POST | `/channel/config/save` | `AdminChannelConfigController::saveChannelConfig` |
| GET | `/merchant/list` | `AdminMerchantController::listMerchants` |
| POST | `/merchant/save` | `AdminMerchantController::saveMerchant` |
| POST | `/order/force_notify` | `OrderAdminController::forceNotifyOrder` |
| POST | `/template/save` | `MerchantTemplateController::saveTemplate` |
| GET | `/security/config` | `AdminSecurityController::getSecurityConfig` |
| POST | `/security/config/save` | `AdminSecurityController::saveSecurityConfig` |

`/channel/save` 和 `/channel/config/save` 继续映射同一个保存方法，兼容现有两个前端入口。

## 7. 组件行为

### 7.1 管理员认证

认证代码作为一个完整安全边界迁移。账号与 bcrypt 兼容迁移、限频键、二次验证 pending Session、Redis 失败降级、Token HMAC 内容、Token 版本、两小时有效期、Session ID 更新和登录告警均保持原实现顺序。任何失败仍返回当前 JSON 字符串，不引入 HTTP 状态码变化。

### 7.2 仪表盘

仪表盘继续先读取 30 秒 Redis 缓存，缓存不可用时直接查询数据库；监控服务失败时返回稳定指标占位；外层异常继续返回成功码和零值统计，确保后台首页不会因监控或统计故障白屏。

### 7.3 平台通道配置

通道读取继续对敏感字段返回空字符串和 `configured` 标记。列表继续只查询 `merchant_id=0` 的平台通道，不下发解密配置。保存流程中的驱动移除检查、字段白名单、长度限制、驱动元数据、弃用限制、支付分类匹配、金额限制、备用通道校验、旧敏感值保留、`upchannel()` 校验、Authcode 加密和在线状态初值保持原顺序。

### 7.4 商户管理

列表继续限制关键词长度和分页大小，并只选择允许下发的字段。保存继续区分新建与更新；编辑时不隐式轮换 API 密钥；密码使用 bcrypt cost 12；新建 PID、API 密钥和初始密码的生成规则不变；IP 白名单仍由 `IpWhitelist` 规范化。

### 7.5 人工补单

`forceNotifyOrder` 迁入现有 `OrderAdminController`，继续复用 `OrderService::markAsPaid()` 和 `resendNotify()`，不复制结算规则。订单不存在、已支付重发、补单成功和失败的审计日志字段与响应保持不变。

### 7.6 安全设置和主页模板

安全设置继续只返回验证码是否启用、是否配置和 Token 版本，不下发验证码明文。修改密码继续验证当前密码、递增 Token 版本并清除当前 Session。主页模板继续只接受安全文件名并验证对应模板文件存在。

## 8. 数据流与依赖

请求仍经过现有路由和中间件到达资源 Controller。Controller 复用现有 `Authcode`、`MonitorService`、`OrderService`、模型、数据库门面和安全工具，不引入新的全局容器配置。

新增 Controller 的构造器只初始化自身业务所需依赖：

- `AdminAuthController` 和 `AdminSecurityController` 使用 `Authcode`；
- `AdminDashboardController` 使用 `MonitorService`；
- `AdminChannelConfigController` 使用 `Authcode` 和 `PaymentManager`；
- `OrderAdminController` 继续使用 `OrderService`；
- 其余 Controller 不持有无关服务。

这样可以消除原控制器构造时无条件创建所有服务的问题，也便于后续对单一资源继续提取应用服务。

## 9. 错误处理

- 原有校验失败文案、`code` 值和数据字段保持逐分支一致。
- Redis、监控和统计的既有降级策略整体迁移，不扩大捕获范围。
- 不把异常堆栈、数据库错误或原始 HTML 错误页写入 JSON。
- 不在拆分过程中增加“临时成功”或吞掉业务失败。
- 路由迁移后不存在指向已删除类的方法。

## 10. 测试策略

1. 新增真实路由注册表测试，冻结 14 条 URL 映射、HTTP 方法、回调类和 `AdminAuthMiddleware` 分组关系。
2. 遍历 Webman 注册路由，证明没有回调指向旧 `AdminController`；旧文件删除和文件规模由验收命令检查。
3. 使用真实 `Request` 和内存 SQLite 补充控制器行为测试，冻结关键错误、降级响应和人工补单失败审计。
4. 对认证、通道、商户、安全设置和人工补单继续运行现有全量测试，确保拆分不影响相关模型和服务。
5. 对所有新增和修改的 PHP 文件执行 `php -l`，并执行 `git diff --check`。
6. 执行根 PHPUnit；测试数量不得低于拆分前基线 321 个，且必须 0 failure、0 error。
7. 统计生产文件行数；本阶段新增或扩展的目标文件原则上不超过 300 行。若通道配置文件因完整事务边界略超目标，必须继续按读取与保存职责拆分，而不是放宽限制。

## 11. 提交与迁移顺序

1. 提交路由和结构契约测试，确认测试在旧结构上按预期失败。
2. 迁移认证与仪表盘，替换对应路由并运行专项测试。
3. 迁移平台通道配置，替换两个保存入口并运行专项测试。
4. 迁移商户、安全设置和主页模板并运行专项测试。
5. 将人工补单合并到现有 `OrderAdminController`。
6. 删除 `AdminController` 及其中没有路由的旧 Git 更新实现。
7. 运行语法、契约、全量测试和文件规模检查，提交最终验证记录。

每个步骤形成可独立审查的提交。实施在新的隔离工作树和 `codex/` 前缀分支中完成；全部提交完成并验证后，先提交分支，再合并到 `main`，最后在 `main` 重跑全量测试。

## 12. 验收标准

1. `app/controller/admin/AdminController.php` 不再存在。
2. 3 条公开认证路由和 11 条受保护路由保持原 URL、HTTP 方法和中间件边界。
3. 所有迁移接口的请求字段、Session 行为、JSON `code`、`msg` 和 `data` 语义不变。
4. `SystemUpdateController` 仍是唯一系统更新入口，旧 Git 更新方法被删除。
5. 本阶段新增或扩展的生产文件不超过 300 行。
6. PHPUnit 不少于 321 个测试且全部通过；新增路由、结构和关键校验测试通过。
7. `php -l`、`git diff --check` 和工作树状态检查通过。
8. `OrderService` 可靠性工作树、其他现有工作树和 `CXPAY.rar` 均未被修改或提交。

## 13. 实施记录

- 2026-08-09：完成 `AdminController` 资源化拆分，原 959 行文件已删除；无路由的旧 Git 更新实现随旧类一并移除，现有 `SystemUpdateController` 保持唯一更新入口。
- 3 条公开认证路由和 11 条管理员认证组路由均通过 Webman 真实注册表验证，HTTP 方法、回调动作和 `AdminAuthMiddleware` 边界保持不变。
- 新增行为测试使用真实表单 `Request` 验证空凭据、仪表盘基础设施故障降级、永久移除驱动、非法商户数据、短验证码和模板路径穿越；人工补单测试使用内存 SQLite 验证未知订单响应及失败审计落库。

| 生产文件 | 实际行数 |
| --- | ---: |
| `AdminChannelConfigController.php` | 287 |
| `AdminAuthController.php` | 234 |
| `AdminDashboardController.php` | 136 |
| `OrderAdminController.php` | 131 |
| `AdminMerchantController.php` | 121 |
| `AdminSecurityController.php` | 120 |
| `MerchantTemplateController.php` | 34 |

- 10 个新增或修改的 PHP 文件全部通过 `php -l`。
- 管理员专项、通道前端及页面模块契约共 39 个测试、352 个断言，全部通过。
- 根 PHPUnit 共 335 个测试、2669 个断言，0 failure、0 error。
- `git diff --check` 无输出，实施工作树无未提交文件；其他既有工作树和主工作区中的 `CXPAY.rar` 未进入本分支。
