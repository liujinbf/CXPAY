# CXPAY 超长文件模块化拆分设计

## 1. 背景

CXPAY 已完成多轮支付通道可靠性与云端控制面隔离优化，但根支付系统仍有多个职责密集的超长文件。继续在这些文件中叠加插件商城、支付通道、商户配置和云端对接逻辑，会放大函数覆盖、回归范围和多人协作冲突。

本设计先暂停云端 M1B，实现一次不改变业务行为的模块化拆分。拆分完成并通过回归后，再恢复云端正式身份 HTTP API、Session、CSRF 和登录审计设计。

## 2. 现状证据

排除 `vendor`、`node_modules`、`.worktrees`、运行目录、生成目录和第三方依赖文档后，当前主要超长业务文件为：

| 文件 | 行数 | 职责密度 |
| --- | ---: | --- |
| `public/admin/index.html` | 3187 | 61 个函数；仪表盘、通道、插件、订单、商户、套餐、告警和系统更新混合 |
| `public/merchant_center.html` | 2551 | 46 个函数；套餐、收银台、通道、轮询组、订单、财务、告警和账户混合 |
| `public/install/index.html` | 1188 | 安装结构、样式和 Vue 流程混合 |
| `app/controller/admin/AdminController.php` | 960 | 认证、仪表盘、商户、通道、安全和更新混合 |
| `app/service/OrderService.php` | 864 | 创建、路由、支付准备、核销、通知和关单混合 |
| `services/wx-monitor-cloud/src/CloudApplication.php` | 781 | 路由注册和云监控处理混合 |
| `app/controller/api/InstallController.php` | 695 | 安装步骤和环境操作混合 |
| `app/controller/api/MerchantApiController.php` | 692 | 商户资料、套餐、告警、订单和安全操作混合 |

`plugins-src` 当前共 9 个源码文件，最大文件为 `wxpay-clerk-adapter/src/Driver.php`，257 行。支付插件源码已具备较合理的驱动与客户端边界，不因行数执行机械拆分。

管理后台和商户中心存在重复函数名。浏览器实际采用后声明覆盖前声明的行为，既增加误改风险，也使测试难以判断哪个实现生效。拆分前必须用契约测试固定当前真正生效的接口、选择器和行为，再只保留一个规范实现。

## 3. 目标

1. 管理后台和商户中心改为静态页面壳、同源 HTML 片段和原生 ES Module 组成的模块化前端。
2. 消除同一页面内的重复全局业务函数，并集中公共请求、提示、确认和转义逻辑。
3. 将 `AdminController` 拆成按业务资源划分的 Controller，保持路由和响应契约不变。
4. 将 `OrderService` 拆成创建、支付路由、核销和待支付订单生命周期服务，同时保留兼容门面。
5. 用测试驱动、逐职责迁移和独立提交控制回归范围。
6. 为后续拆分安装页、商户 API、云监控应用建立可复用方法。

## 4. 非目标

- 不进行视觉改版、文案改写或交互重设计。
- 不更改现有 API URL、HTTP 方法、参数或响应结构。
- 不引入 Vue、React、Vite、Webpack 或新的生产构建步骤。
- 不修改支付通道路由策略、手续费算法、幂等规则或结算语义。
- 不在本里程碑实现云端 M1B、插件商城新功能或支付功能。
- 不按固定行数拆分职责单一、测试清晰的文件。

## 5. 方案选择

采用渐进式原生模块化，不采用前端框架重写，也不只做传统脚本物理分文件。

理由：

- 当前页面由 Webman 静态托管，原生模块可保持部署链路不变。
- 先冻结行为再迁移，风险显著低于一次性框架重写。
- HTML 片段能够同时缩小页面结构文件，而不是只把 JavaScript 移出超长 HTML。
- 模块导入和命名空间可消除加载顺序与重复全局函数冲突。

## 6. 管理后台前端结构

目标目录：

```text
public/admin/
├─ index.html
├─ assets/
│  ├─ app.js
│  ├─ api.js
│  ├─ ui.js
│  ├─ router.js
│  ├─ version.js
│  └─ features/
│     ├─ dashboard.js
│     ├─ channels.js
│     ├─ plugins.js
│     ├─ cloud-monitor.js
│     ├─ merchants.js
│     ├─ plans.js
│     ├─ orders.js
│     ├─ callbill.js
│     ├─ alerts.js
│     └─ system-update.js
└─ views/
   ├─ dashboard.html
   ├─ channels.html
   ├─ plugins.html
   ├─ cloud-monitor.html
   ├─ merchants.html
   ├─ plans.html
   ├─ orders.html
   ├─ callbill.html
   ├─ alerts.html
   └─ system-update.html
```

职责：

- `index.html` 只保留页面骨架、导航、顶层内容容器、共享弹窗容器和第三方 CDN 引用。
- `app.js` 是唯一启动入口，初始化请求层、UI、路由和首个标签页。
- `api.js` 统一封装请求、管理员登录失效、网络异常和服务端稳定错误格式。
- `ui.js` 统一提供 HTML 转义、Toast、自定义确认框和 Lucide 图标刷新。
- `router.js` 管理标签切换、片段缓存、过期请求失效和功能模块生命周期。
- 每个 `features/*.js` 只读取和修改对应片段中的 DOM，并只调用公开的公共层接口。
- 每个 `views/*.html` 只包含单个功能的静态结构，不包含大段业务脚本。

## 7. 商户中心前端结构

目标目录与管理后台同构：

```text
public/
├─ merchant_center.html
└─ merchant/
   ├─ assets/
│  ├─ app.js
│  ├─ api.js
│  ├─ ui.js
│  ├─ router.js
│  ├─ version.js
│  └─ features/
│     ├─ dashboard.js
│     ├─ profile.js
│     ├─ plans.js
│     ├─ cashier.js
│     ├─ channels.js
│     ├─ poll-groups.js
│     ├─ orders.js
│     ├─ finance.js
│     └─ alerts.js
   └─ views/
   ├─ dashboard.html
   ├─ profile.html
   ├─ plans.html
   ├─ cashier.html
   ├─ channels.html
   ├─ poll-groups.html
   ├─ orders.html
   ├─ finance.html
   └─ alerts.html
```

现有 `/merchant_center.html` 保持唯一入口并直接作为页面壳，不新增 `/merchant/index.html`，因此无需重定向，也不会产生两套商户中心实现。

## 8. 前端模块契约

每个功能模块导出：

```javascript
export const feature = {
    id: 'channels',
    async mount(context) {},
    unmount() {},
};
```

`context` 只提供 `api`、`ui`、`signal` 和只读的当前路由信息。功能模块不得自行创建第二套请求包装器或全局提示组件。

迁移初期由 `window.CXAdmin` 和 `window.CXMerchant` 暴露兼容动作。片段迁移后使用 `data-action` 和事件代理，最终只保留启动命名空间，不保留散落的 `window` 函数。

快速切换标签时，`router.js` 为每次片段请求创建 `AbortController`；开始下一次导航前终止上一请求，并在挂载前再次比较导航序号，确保旧片段不能覆盖当前页面。`unmount()` 必须释放计时器、图表实例和事件订阅。

片段加载失败时保留页面壳，显示稳定错误、重试按钮和当前功能名称，不显示空白页。公共请求失败不能把服务端堆栈或原始 HTML 错误页写入 DOM。

## 9. 静态资源版本

`version.js` 导出唯一的 `ASSET_VERSION` 常量。`app.js` 和 `version.js` 必须使用 `Cache-Control: no-cache` 重新验证；`router.js` 根据 `ASSET_VERSION` 为所有功能模块和片段 URL 追加同一个 `v` 查询参数。一次发布中的页面壳、模块和片段必须原子部署；版本不一致时拒绝挂载并提示刷新，避免浏览器混用新旧缓存。

本项目不增加构建产物指纹。每次修改模块或片段时必须在同一提交中更新 `ASSET_VERSION`；契约测试证明入口和全部片段使用该版本生成 URL。

## 10. AdminController 拆分

目标 Controller：

| Controller | 迁移职责 |
| --- | --- |
| `AdminAuthController` | 登录、二次验证码、Token 签发、登出 |
| `AdminDashboardController` | 仪表盘与统计数据 |
| `AdminChannelConfigController` | 通道列表、配置读取和保存 |
| `AdminMerchantController` | 商户列表和商户保存 |
| `AdminSecurityController` | 后台安全配置读取和保存 |
| `OrderAdminController` | 现有订单管理和强制通知 |
| `MerchantTemplateController` | 商户模板保存 |

现有路由 URL 和中间件保持不变，只替换 Controller 映射。已经独立存在的 `SystemUpdateController`、`AlertConfigController`、`ReportController`、`PluginMarketController` 等不得重新并回新 Controller。

每个 Controller 只解析请求、调用应用服务并格式化既有响应。需要跨 Controller 复用的认证、通道配置或商户保存逻辑应进入专用 Service，不通过继承 `AdminController` 复用。

所有路由迁移并通过测试后删除 `AdminController`，不保留新旧两套实现。

## 11. OrderService 拆分

目标结构：

| Service | 职责 |
| --- | --- |
| `OrderService` | 兼容门面，保留现有公开签名并委派 |
| `OrderCreationService` | 验签、幂等、风控、金额预留和建单事务 |
| `PaymentRoutingService` | 通道选择、通道可用性、驱动配置和支付参数准备 |
| `OrderSettlementService` | 支付核销、手续费与余额结算、核销后事件 |
| `PendingOrderService` | 待支付订单关闭、金额释放和批量过期 |
| `MerchantNotifyService` | 保持现有异步商户通知职责 |

兼容门面继续提供：

```php
createOrder(...)
markAsPaid(...)
resendNotify(...)
closePendingOrder(...)
expirePendingOrders(...)
```

调用方在第一阶段无需修改。门面不复制业务规则，只转发到唯一实现。

数据库事务必须整体迁移。例如订单核销、资金变化、手续费记录和状态更新仍处于同一事务；事务提交后的通知和告警继续保持提交后触发。通道选择只能通过 `PaymentRoutingService`，不得产生第二套回退算法。

## 12. 后续批次

第一批完成后，再按独立规格处理：

1. `public/install/index.html` 与 `InstallController`；
2. `MerchantApiController`；
3. `CloudApplication`；
4. 其他超过职责或复杂度门槛的文件。

后续拆分继续使用行为冻结、单职责迁移、兼容门面和逐步删除旧实现的方法，不自动复制本设计的目录名称。

## 13. 文件规模约束

文件行数是预警指标，不是唯一验收标准：

- 页面壳目标不超过 500 行；
- 单个前端功能模块或 HTML 片段目标不超过 400 行；
- Controller 目标不超过 300 行；
- 业务 Service 目标不超过 400 行；
- 超过目标时必须证明其职责仍单一、测试边界清晰，否则继续拆分。

不得通过压缩、合并语句、删除必要注释或把多个职责移动到同一个 `helpers` 文件规避行数门槛。

## 14. 测试策略

### 14.1 前端契约

- 页面壳只加载唯一应用入口；
- 每个导航标签存在对应片段和功能模块；
- 关键 DOM ID、表单字段和 API URL 保持兼容；
- 同一应用不得注册重复动作；
- 正式 HTML 不含大段内联业务脚本；
- 页面壳、片段和模块使用一致资源版本。

### 14.2 浏览器冒烟

- 首屏和标签切换正常加载片段；
- 快速切换不会被旧请求覆盖；
- 片段加载失败显示重试界面；
- 弹窗、Toast、确认操作和退出登录可用；
- 管理后台通道、插件、订单、商户和告警主要流程可触发正确请求；
- 商户中心套餐、收银台、通道、订单和告警主要流程可触发正确请求。

### 14.3 后端契约与集成

- 管理员全部既有路由、HTTP 方法、中间件和响应字段不变；
- `OrderService` 公开方法签名和返回语义不变；
- 手续费预留、订单幂等、支付核销、超时关闭、金额释放和通知测试持续通过；
- 根 PHPUnit 全量测试在每个任务结束时通过，测试数量不得低于拆分前的 262 个。

### 14.4 静态检查

- 所有 PHP 文件通过 `php -l`；
- 所有新增 JavaScript 模块通过语法检查；
- 正式目录不存在测试假实现；
- `git diff --check` 通过。

## 15. 实施顺序与提交边界

1. 增加管理后台页面契约与浏览器冒烟基线；
2. 拆管理后台公共层、页面壳和功能片段；
3. 增加商户中心页面契约与浏览器冒烟基线；
4. 拆商户中心公共层、页面壳和功能片段；
5. 拆 `AdminController` 并逐条迁移路由；
6. 拆 `OrderService`，保留兼容门面；
7. 运行全量回归、统计文件规模并更新开发文档。

每项形成独立提交。任一阶段失败时回退该阶段提交，不通过同时保留新旧实现来规避问题。

## 16. 风险与控制

| 风险 | 控制措施 |
| --- | --- |
| 重复函数的旧实现与实际生效实现不同 | 先记录浏览器当前行为，再保留后声明的有效语义 |
| HTML 片段异步加载改变初始化时序 | 功能统一经 `mount()` 初始化，禁止依赖脚本解析顺序 |
| 图表或定时器在切换后泄漏 | `unmount()` 强制释放资源，浏览器测试覆盖重复切换 |
| 发布缓存混用 | 页面壳、模块和片段共享资源版本，部署保持原子性 |
| Controller 拆分改变响应 | 路由和 JSON 契约测试先于迁移 |
| Service 拆分破坏事务 | 事务整体迁移，数据库集成测试验证提交与回滚 |
| 兼容门面长期不清理 | 门面只允许委派，不允许新增规则；后续调用方迁移单独规划 |

## 17. 验收标准

1. `public/admin/index.html` 和商户中心页面壳均不超过 500 行。
2. 管理后台与商户中心没有重复动作注册或重复业务函数实现。
3. 前端功能按职责分布在不超过 400 行的模块和片段中。
4. `AdminController` 删除，既有管理员 API 全部由专用 Controller 承载。
5. `OrderService` 只保留兼容委派，核心业务进入单职责服务。
6. 页面视觉、操作流程、API URL 和响应结构保持不变。
7. 根项目测试不少于 262 个并全部通过，新增契约和浏览器冒烟测试通过。
8. `plugins-src` 不进行无收益的机械拆分。
9. 用户原有未跟踪文件 `CXPAY.rar` 不被修改或提交。
10. 完成后恢复云端 M1B 设计，从已确认的预登录挑战和 CSRF 决策继续。
