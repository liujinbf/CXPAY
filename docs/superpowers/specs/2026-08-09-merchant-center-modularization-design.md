# 商户中心页面模块化设计

**状态：** 已确认，待实施计划
**日期：** 2026-08-09
**范围：** `public/merchant_center.html` 及其新增同源前端资源
**决策：** 采用页面壳、公共运行时、功能视图和生命周期模块四层结构

## 1. 背景

`public/merchant_center.html` 当前有 2550 行，布局、十个标签页、弹窗、公共 UI、会话请求和全部业务逻辑集中在同一文件中。页面包含约 45 个具名动作、60 个内联事件以及多组动态 HTML 拼接，已经超过单文件可可靠维护的范围。

当前问题不只是文件过长：

- `loadMerchantDashboardData()` 重复声明，实际行为依赖浏览器后声明覆盖前声明；
- 标签切换直接调用全局函数，页面结构、初始化时序和业务逻辑相互耦合；
- 仪表盘每次进入都会新增 ECharts resize 监听且不释放；
- 通道账号授权使用长轮询，但切换页面后没有统一取消边界；
- Toast、确认框、HTML 转义、复制和请求错误处理只能作为全局函数复用；
- 通道模块同时承担驱动发现、表单渲染、二维码解析、账单源授权和通道 CRUD，后续修改容易波及其他标签；
- 目前没有商户中心页面结构契约，重构时难以证明入口、API、字段和用户行为未变化。

本设计在不改变业务行为的前提下，建立与已完成管理后台一致的模块化运行机制。

## 2. 目标与非目标

### 2.1 目标

1. 保持 `/merchant_center.html` 为唯一公开入口，现有哈希标签继续可直接访问。
2. 把页面缩减为不超过 500 行的布局壳，并移除正式页面中的内联业务脚本和内联事件。
3. 将十个标签分别拆为同源 HTML 片段与原生 ES Module 功能模块。
4. 单个功能模块或 HTML 片段不超过 400 行，并保持单一职责。
5. 保持现有视觉、中文文案、导航顺序、表单字段、本地存储键和 `/api/merchant/*` 契约不变。
6. 统一会话请求、错误处理、HTML 转义、Toast、确认、复制、图标刷新和路由逻辑。
7. 标签切换时可靠释放图表、事件监听、授权轮询、语音播放和模块内缓存。
8. 建立源码契约、模块契约、浏览器冒烟和完整回归测试。

### 2.2 非目标

- 不引入 Vue、React、Vite、Webpack 或新的生产构建步骤；
- 不重新设计页面视觉或调整业务文案；
- 不修改商户登录、权限、中间件、数据库结构或 API 响应；
- 不把浏览器本地的 `cx_cashier_config` 改造成服务端配置；
- 不在本阶段新增真实轮询组编辑能力，继续按当前启用通道分组展示；
- 不顺带重构管理后台、安装页、Controller 或 Service；
- 不处理未跟踪文件 `CXPAY.rar`。

## 3. 方案选择

### 3.1 采用方案

采用“公共运行时 + 十个功能模块”的细粒度方案。该方案与管理后台已经验证的架构一致，能复用资源版本、片段路由、AbortController、错误重试和生命周期测试方法，同时让每个商户业务标签拥有独立边界。

### 3.2 未采用方案

| 方案 | 优点 | 不采用原因 |
| --- | --- | --- |
| 按账户、支付、交易、套餐四个业务域合并 | 文件数量少 | 通道和账户域仍会过大，跨标签共享 DOM，难以独立卸载 |
| 只提取 JavaScript，保留全部内联面板 | 改动较少 | 主 HTML 仍超过 1000 行，片段无法独立测试和按需加载 |
| 引入前端框架重写 | 组件模型成熟 | 增加构建链、部署和迁移风险，偏离渐进式优化目标 |

## 4. 目标目录结构

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
   │     ├─ alerts.js
   │     ├─ channels.js
   │     ├─ cashier.js
   │     ├─ poll-groups.js
   │     ├─ orders.js
   │     ├─ finance.js
   │     ├─ plans.js
   │     └─ api-keys.js
   └─ views/
      ├─ dashboard.html
      ├─ profile.html
      ├─ alerts.html
      ├─ channels.html
      ├─ cashier.html
      ├─ poll-groups.html
      ├─ orders.html
      ├─ finance.html
      ├─ plans.html
      └─ api-keys.html

app/middleware/
└─ MerchantAssetCacheMiddleware.php

tests/
├─ Fixtures/merchant_center_router.php
└─ Unit/
   ├─ MerchantCenterSourceIntegrityTest.php
   ├─ MerchantCenterModuleContractTest.php
   └─ MerchantAssetCacheMiddlewareTest.php
```

`merchant_center.html` 保留全局依赖 CDN、侧边栏、顶部栏、功能根节点和唯一模块入口。业务面板、表单和弹窗进入对应 view，不在壳层保留第二份实现。

## 5. 功能模块边界

| 功能 ID | 职责 | 数据来源或持久化 |
| --- | --- | --- |
| `dashboard` | 首页指标、最近订单、七日趋势图、快捷导航 | `/api/merchant/dashboard`、`/api/merchant/order/list?page_size=5`、趋势订单接口 |
| `profile` | 商户资料展示、密码修改 | `/api/merchant/profile`、`/api/merchant/change_password` |
| `notice-config` / `alerts` | 通知开关、事件订阅、接收渠道、测试通知 | `/api/merchant/alert/config*`、`/api/merchant/alert/test` |
| `channel-list` / `channels` | 通道列表、创建编辑、启停、删除、二维码、驱动字段、账单源和账号授权 | `/api/merchant/channel/*`、`/api/merchant/bill-source/*` |
| `channel-config` / `cashier` | 收银台公告、超时、跳转、语音、浮动金额和主题 | `localStorage['cx_cashier_config']` |
| `poll-group` / `poll-groups` | 按支付分类展示当前启用通道和均分权重 | `/api/merchant/channel/list` |
| `order-list` / `orders` | 商户订单列表和状态展示 | `/api/merchant/order/list` |
| `finance-log` / `finance` | 服务费变动明细 | `/api/merchant/finance_log` |
| `plan-buy` / `plans` | 当前套餐、套餐广场和购买确认 | `/api/merchant/plan/list`、`/api/merchant/plan/buy` |
| `api-keys` | 对接地址、PID、密钥、复制和密钥重置 | `/api/merchant/profile`、`/api/merchant/reset_key` |

路由 ID 保留页面现有值，文件名允许使用更清晰的领域名。`app.js` 负责两者的显式映射，禁止通过文件名猜测路由。

## 6. 公共运行时设计

### 6.1 `version.js`

导出唯一 `MERCHANT_ASSET_VERSION` 和 `assetUrl(path)`。所有动态模块和 HTML 片段必须携带相同版本参数，避免部署期间新旧资源混用。

### 6.2 `api.js`

导出 `merchantFetch(url, options)`：

- 继续使用同源 Cookie 会话，不新增客户端 Token；
- 保留请求 URL、HTTP 方法、Content-Type 和表单编码；
- 收到 401 时跳转 `/merchant_login.html`；
- 不把业务 `code` 统一改写为 HTTP 异常，业务模块继续按当前响应语义处理；
- 网络异常原样抛给功能模块，由功能模块显示当前页面对应的错误文案。

### 6.3 `ui.js`

只提供无业务含义的公共能力：

- `escapeHtml(value)`；
- `safeCreateIcons(root?)`；
- `showToast(message, type)`；
- `showConfirm(title, message, isDanger)`；
- `copyText(value, trigger)`；
- 必要的表单序列化小工具。

公共层不得保存当前商户、当前通道、当前套餐或当前标签等业务状态。

### 6.4 `router.js`

路由接口与管理后台一致，但使用商户功能定义：

```javascript
createRouter({ container, definitions, context, activateFeature })
```

职责包括：

- 解析现有哈希并对未知 ID 回退到 `dashboard`；
- 使用 `AbortController` 取消上一标签的请求；
- 等待上一功能 `unmount()` 后装载新片段；
- 缓存成功加载的 HTML 片段，不缓存失败响应；
- 通过递增导航序号阻止旧异步结果覆盖新页面；
- 片段失败时显示错误卡片和“重新加载”按钮；
- 只在成功解析功能后更新导航、标题和哈希。

### 6.5 `app.js`

应用入口显式注册十个路由，创建公共上下文并代理壳层导航、退出登录和跨功能跳转。对外只暴露冻结的最小命名空间：

```javascript
window.CXMerchant = Object.freeze({ navigate: router.navigate });
```

功能模块通过注入的 `api`、`ui` 和 `navigate` 协作，不读取其他模块内部变量。

## 7. 生命周期与状态规则

每个功能模块必须导出：

```javascript
export const feature = {
    id: 'dashboard',
    async mount(context) {},
    async unmount() {},
};
```

`context` 包含 `root`、`api`、`ui`、`signal` 和 `navigate`。模块只能在自己的 `root` 查询功能 DOM；侧边栏、顶部商户信息等壳层元素通过入口提供的窄接口更新。

具体资源规则：

- `dashboard` 在卸载时 `dispose()` ECharts 实例并移除 resize 监听；
- `channels` 把通道缓存、驱动缓存、当前编辑项和授权轮询控制器保存在模块闭包，卸载时清空并停止轮询；
- `cashier` 停止仍在播放的语音演示，主题状态不再放到 `window.selectedCashierTheme`；
- `plans` 把套餐缓存保存在模块内，购买成功后只刷新当前模块；
- 所有模块使用根节点事件代理，不给动态 HTML 拼接 `onclick`；
- AbortError 属于正常切换，不展示错误 Toast；其他错误继续使用现有业务文案。

## 8. 确定性迁移规则

重构前先建立当前可见行为基线：

1. `loadMerchantDashboardData()` 保留后声明的有效实现，因为浏览器当前以该实现覆盖前一版本；
2. 后部实现负责仪表盘统计、最近五笔订单、套餐信息和顶部/侧边栏余额，迁移时不能退回早期简化版本；
3. `loadMerchantProfile()` 当前同时填充壳层、账户表单和 API 密钥页，拆分后由公共商户会话状态提供一次资料加载结果，`profile` 与 `api-keys` 分别渲染自己的字段；
4. 现有哈希、导航标题和标签顺序保持不变；
5. 当前 `cx_cashier_config` 字段与默认值保持不变；
6. 所有动态文本继续经过 HTML 转义，二维码内容和通道 ID 继续以数据字段传递；
7. 不保留“新模块 + 旧内联实现”双轨状态。每迁移一项，必须删除对应旧面板和旧函数。

## 9. 数据流

首次进入页面时：

1. 壳层加载第三方 CDN 和唯一 `merchant/assets/app.js`；
2. 入口通过 `api.js` 请求 `/api/merchant/profile`，验证会话并更新侧边栏及顶部账户摘要；
3. 路由解析哈希，未知值回退首页；
4. 路由并行加载目标 HTML 片段与功能模块；
5. 功能模块挂载事件并请求自己的业务数据；
6. 标签切换先中止旧请求和释放旧资源，再挂载新模块。

功能间跳转只使用 `navigate(id)`。仪表盘跳订单、套餐或通道时不直接操作目标页面 DOM。

## 10. 错误与会话处理

- 401：清理当前功能状态并跳转 `/merchant_login.html`；
- 片段或模块加载失败：在功能根节点显示统一错误卡片，允许重试；
- 业务响应失败：保留现有接口消息，显示在表格空态、表单旁或 Toast；
- 通道授权轮询超时：保留现有超时语义，关闭二维码并允许重新发起；
- 标签切换导致的请求中止：静默结束，不污染控制台；
- ECharts、QRCode、jsQR 或 speechSynthesis 不可用：功能降级并给出可理解提示，不阻止其他标签加载；
- 退出登录：无论服务端注销请求是否成功，都跳转登录页；服务端成功路径仍优先执行。

## 11. 缓存与部署

新增 `MerchantAssetCacheMiddleware`，只对以下入口资源返回 `Cache-Control: no-cache, must-revalidate`：

- `merchant_center.html`；
- `merchant/assets/app.js`；
- `merchant/assets/version.js`。

其余带显式版本参数的模块和片段可以按现有静态策略缓存。每批功能迁移都更新资源版本，部署时页面壳、应用入口、版本文件、模块和片段必须原子发布。

## 12. 测试策略

### 12.1 源码与结构契约

- 原页面没有冲突标记、重复静态 ID 或重复具名函数；
- 最终壳层不超过 500 行，没有内联业务脚本和 `onclick`；
- 十个路由均存在对应 view 和 feature；
- 每个 view/feature 不超过 400 行；
- 页面只加载一个应用入口，所有资源使用同一版本；
- 通道、套餐、通知、订单、账户和密钥关键 API 字符串保持不变；
- `cx_cashier_config` 和关键表单 ID 保持兼容。

### 12.2 JavaScript 行为测试

通过 Node 可执行契约验证：

- `merchantFetch` 保留同源会话并正确处理 401；
- 路由对未知标签回退首页；
- HTML 转义、资源版本和复制降级语义不变；
- 模块导出有效生命周期对象；
- 生产目录不包含测试假实现。

### 12.3 浏览器冒烟

使用无数据库 fixture 返回稳定商户 API 响应，覆盖：

1. 首屏资料和仪表盘加载；
2. 十个标签依次加载且哈希、标题、唯一活动片段一致；
3. 快速切换通道、套餐、订单后只保留最终页面；
4. 仪表盘反复进入不会累计图表或 resize 监听；
5. 通道授权轮询在离开标签后停止；
6. 套餐确认、通道编辑、密码修改、密钥重置和通知测试触发原 API；
7. 片段 404 显示错误态，解除故障后重试成功；
8. 控制台没有 SyntaxError、未定义函数、重复 ID 或未处理 Promise。

### 12.4 项目门禁

- 所有 PHP 文件通过 `php -l`；
- 所有新增 JavaScript 通过 `node --check`；
- 根 PHPUnit 不少于当前 293 个测试并全部通过；
- `git diff --check` 通过；
- 用户的 `CXPAY.rar` 始终不进入暂存区。

## 13. 实施顺序

1. 建立商户中心源码完整性和可见行为基线；
2. 提取版本、请求、UI、会话资料和缓存中间件；
3. 建立渐进式路由与无数据库浏览器 fixture；
4. 迁移仪表盘、账户和 API 密钥；
5. 迁移通道列表、通道弹窗和授权轮询；
6. 迁移收银台配置和轮询组；
7. 迁移订单、财务和套餐；
8. 迁移通知设置，删除最终兼容层并执行完整验收。

每个步骤形成独立提交。任一阶段失败时修复该阶段，禁止长期保留新旧实现规避问题。

## 14. 风险与控制

| 风险 | 控制措施 |
| --- | --- |
| 重复仪表盘函数迁移了错误版本 | 契约固定后声明的当前有效语义和关键字段 |
| 资料同时被多个标签消费导致重复请求 | 入口维护只读会话快照，模块按需渲染，不共享可变 DOM |
| 通道模块超过 400 行 | 将驱动字段渲染和授权轮询拆成同目录内部辅助模块，但公共 feature 仍为唯一入口 |
| 切换标签时旧请求覆盖新页面 | AbortController 与导航序号双重保护 |
| 授权轮询在后台继续运行 | 模块卸载主动 abort，并测试离开标签后的请求数量 |
| 资源版本不一致造成白屏 | 唯一版本模块、入口重新验证缓存和原子发布 |
| 商户会话行为被统一请求层改变 | 保持 Cookie 会话、原请求编码和业务 code 语义 |
| 动态内容引入 XSS 回归 | 统一转义，动作参数使用 dataset，不拼接可执行事件 |

## 15. 验收标准

1. `public/merchant_center.html` 不超过 500 行。
2. 正式页面没有重复函数、重复静态 ID、内联业务脚本和内联事件。
3. 十个功能均为独立 view/feature，单文件不超过 400 行。
4. 入口 URL、哈希、导航、视觉、文案、本地存储键和 API 契约保持兼容。
5. 图表、事件、授权轮询、缓存和语音资源在卸载时释放。
6. 十标签、快速切换、错误重试和关键动作浏览器回归通过。
7. PHP 语法、JavaScript 语法、契约测试和根 PHPUnit 全部通过。
8. 每个迁移阶段有独立提交，可按提交边界审查。
9. `CXPAY.rar` 不被修改或提交。
10. 完成本阶段后再进入 `AdminController` 和 `OrderService` 拆分，不混合实施。

## 16. 实施记录（2026-08-09）

- `public/merchant_center.html` 从 2550 行拆分为 114 行，仅保留页面壳和一个 ES Module 入口；
- 十个哈希标签均已迁移为独立 view/feature，最大视图为 `cashier.html` 254 行，最大功能模块为 `channel-editor.js` 238 行；
- 公共请求、资源版本、UI、会话资料和路由各保留一个实现来源，最终资源版本为 `merchant-modules-v6`；
- 十标签逐项验证均保持原哈希且只有一个活动 `[data-feature]`，快速执行通道、套餐、订单导航后最终只显示订单；
- 通道授权轮询在离开标签后停止，收银台配置完整保存 `cx_cashier_config`，套餐购买保持 URL 编码 `plan_id`，通知保存保持既有 JSON 请求契约；
- 通知片段模拟 404 时显示错误卡片，解除拦截后点击重试恢复；除刻意制造的 404、既有 favicon 404 和 Tailwind CDN 提示外，没有应用脚本错误；
- 187 个 PHP 文件通过语法检查，全部 JavaScript 模块通过 `node --check`，根 PHPUnit 为 321 个测试、2470 个断言且全部通过；
- `git diff --check` 通过，主工作区未跟踪的 `CXPAY.rar` 未修改、未暂存、未提交；
- 本阶段未拆分 `AdminController`、`OrderService` 或其他后端超长文件，后续应继续按独立计划和提交边界处理。
