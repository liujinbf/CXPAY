# 商户中心页面模块化 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在保持商户中心入口、视觉、哈希导航、会话、本地配置和 API 契约不变的前提下，把 2550 行的 `public/merchant_center.html` 拆成不超过 500 行的页面壳、十个同源视图片段和可正确卸载的单职责原生 JavaScript 模块。

**Architecture:** `/merchant_center.html` 保持唯一公开入口，`merchant/assets/app.js` 负责会话资料、壳层事件和十标签路由；每个标签由一个 `merchant/views/*.html` 和一个 `merchant/assets/features/*.js` 挂载。通道领域额外使用编辑器与授权控制器两个内部模块，避免单个功能文件超过 400 行；所有异步切换统一经 AbortController、导航序号和 `mount/unmount` 生命周期管理。

**Tech Stack:** Webman 静态资源、原生 ES Module、Fetch/AbortController、Cookie 商户会话、Tailwind CDN、Lucide、ECharts、QRCode、jsQR、SpeechSynthesis、PHPUnit 10、Node.js 22、Playwright CLI。

## Global Constraints

- `/merchant_center.html`、十个现有哈希 ID、导航顺序、视觉和中文文案保持不变。
- `/api/merchant/*` URL、HTTP 方法、Content-Type、请求字段、响应 `code/msg/data` 语义保持不变。
- 继续使用同源 Cookie 会话；不得新增客户端商户 Token。
- 保留 `localStorage['cx_cashier_config']` 的字段、默认值和保存行为。
- 不引入 Vue、React、Vite、Webpack、npm 生产依赖或新的构建步骤。
- 最终页面壳不超过 500 行；单个功能模块、内部辅助模块或 HTML 片段不超过 400 行。
- 每个功能模块必须导出 `feature = { id, mount(context), unmount() }`。
- 公共请求、转义、Toast、确认、复制、图标和资源版本只能有一个实现来源。
- 模块只能查询自己的 `root`；壳层摘要通过注入的 `shell` 接口更新。
- 所有片段和动态模块必须经唯一 `assetUrl()` 携带相同版本。
- 根 PHPUnit 测试不得少于计划开始时的 293 个。
- 用户未跟踪文件 `CXPAY.rar` 不得修改、删除、暂存或提交。

## Target File Map

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
   │     ├─ channel-editor.js
   │     ├─ channel-authorization.js
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

---

### Task 1: 固定商户中心确定性源码基线

**Files:**
- Create: `tests/Unit/MerchantCenterSourceIntegrityTest.php`
- Modify: `public/merchant_center.html`

**Interfaces:**
- Consumes: 浏览器当前以后声明的第二个 `loadMerchantDashboardData()` 作为有效实现。
- Produces: 无冲突标记、无重复静态 ID、无重复具名函数的迁移基线。

- [ ] **Step 1: 写入会捕获重复函数的失败测试**

创建测试类并让断言直接读取真实页面：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MerchantCenterSourceIntegrityTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/public/merchant_center.html');
        self::assertIsString($html);
        $this->html = $html;
    }

    public function testSourceHasNoMergeConflictMarkers(): void
    {
        self::assertDoesNotMatchRegularExpression('/^(<<<<<<<|=======|>>>>>>>)\s.*$/m', $this->html);
    }

    public function testStaticMarkupIdsAreUnique(): void
    {
        $markup = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $this->html);
        self::assertIsString($markup);
        preg_match_all('/\bid="([^"]+)"/', $markup, $matches);
        $duplicates = array_keys(array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1
        ));
        self::assertSame([], $duplicates, '商户中心静态 DOM id 不得重复');
    }

    public function testNamedBusinessFunctionsAreUnique(): void
    {
        preg_match_all(
            '/\b(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
            $this->html,
            $matches
        );
        $duplicates = array_keys(array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1
        ));
        self::assertSame([], $duplicates, '商户中心动作不得依赖后声明覆盖前声明');
    }

    public function testEffectiveDashboardKeepsRecentOrdersAndPlanSummary(): void
    {
        self::assertStringContainsString('/api/merchant/order/list?page_size=5', $this->html);
        self::assertStringContainsString('dashboard-plan-name', $this->html);
        self::assertStringContainsString('dashboard-recent-orders-tbody', $this->html);
    }
}
```

- [ ] **Step 2: 运行测试并确认失败原因正确**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterSourceIntegrityTest.php
```

Expected: `testNamedBusinessFunctionsAreUnique` 失败并明确报告 `loadMerchantDashboardData`；其他断言通过。

- [ ] **Step 3: 删除早期被覆盖的仪表盘函数**

从 `merchant_center.html` 删除当前第 1130 行附近的早期 `loadMerchantDashboardData()`，保留后部同时更新统计、最近订单、套餐和壳层余额的实现。不得改动保留实现的 API URL、数据字段和空值默认值。

- [ ] **Step 4: 运行基线与全量回归**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterSourceIntegrityTest.php
php vendor/bin/phpunit --display-warnings
git diff --check
```

Expected: 源码测试通过；根测试不少于 297 个且全部通过。

- [ ] **Step 5: 提交确定性基线**

```powershell
git add -- public/merchant_center.html tests/Unit/MerchantCenterSourceIntegrityTest.php
git commit -m "fix: restore deterministic merchant center source"
```

---

### Task 2: 建立商户公共运行时与缓存策略

**Files:**
- Create: `public/merchant/assets/version.js`
- Create: `public/merchant/assets/api.js`
- Create: `public/merchant/assets/ui.js`
- Create: `app/middleware/MerchantAssetCacheMiddleware.php`
- Create: `tests/Unit/MerchantCenterModuleContractTest.php`
- Create: `tests/Unit/MerchantAssetCacheMiddlewareTest.php`
- Modify: `config/static.php`

**Interfaces:**
- Produces: `MERCHANT_ASSET_VERSION`、`assetUrl(path)`、`merchantFetch(url, options)`、`escapeHtml(value)`、`safeCreateIcons(root)`、`showToast(message, type)`、`showConfirm(title, message, isDanger)`、`copyText(value, trigger)`。
- Consumes: 同源 Cookie 会话和 `/merchant_login.html`。

- [ ] **Step 1: 写入公共模块与运行时行为失败测试**

创建 `MerchantCenterModuleContractTest`，文件存在性测试会先失败：

```php
#[DataProvider('coreModuleProvider')]
public function testCoreModuleExistsAndExportsContract(string $file, array $exports): void
{
    $path = self::MERCHANT . '/assets/' . $file;
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1);
    foreach ($exports as $export) {
        self::assertStringContainsString('export ' . $export, $source);
    }
}

public static function coreModuleProvider(): iterable
{
    yield 'version' => ['version.js', ['const MERCHANT_ASSET_VERSION', 'function assetUrl']];
    yield 'api' => ['api.js', ['async function merchantFetch']];
    yield 'ui' => ['ui.js', [
        'function escapeHtml',
        'function safeCreateIcons',
        'function showToast',
        'function showConfirm',
        'function copyText',
    ]];
}
```

增加 Node 行为测试，使用手工固定期望而不是复用实现计算结果：

```javascript
globalThis.window = { location: { origin: 'https://merchant.example.test', assign: (url) => redirected = url } };
globalThis.localStorage = { getItem: () => null };
globalThis.fetch = async () => ({ status: 401 });

const version = await import(pathToFileURL(process.argv[1]).href);
if (version.assetUrl('/merchant/assets/app.js') !==
    'https://merchant.example.test/merchant/assets/app.js?v=merchant-modules-v1') {
    throw new Error('资源 URL 版本错误');
}

const ui = await import(pathToFileURL(process.argv[2]).href);
if (ui.escapeHtml(`<a data-x="'">&</a>`) !== '&lt;a data-x=&quot;&#39;&quot;&gt;&amp;&lt;/a&gt;') {
    throw new Error('HTML 转义错误');
}

const api = await import(pathToFileURL(process.argv[3]).href);
try { await api.merchantFetch('/api/merchant/profile'); } catch (error) {
    if (error.message !== '商户登录状态已失效') throw error;
}
if (redirected !== '/merchant_login.html') throw new Error('401 未跳转商户登录页');
```

- [ ] **Step 2: 运行测试并确认三个模块缺失**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php
```

Expected: 因 `version.js`、`api.js`、`ui.js` 不存在而失败。

- [ ] **Step 3: 实现版本与同源会话请求**

`version.js`：

```javascript
export const MERCHANT_ASSET_VERSION = 'merchant-modules-v1';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', MERCHANT_ASSET_VERSION);
    return url.href;
}
```

`api.js`：

```javascript
export async function merchantFetch(url, options = {}) {
    const response = await fetch(url, options);
    if (response.status === 401) {
        window.location.assign('/merchant_login.html');
        throw new Error('商户登录状态已失效');
    }
    return response;
}
```

- [ ] **Step 4: 迁移无业务状态的 UI 能力**

把现有 Toast、确认弹窗、转义和复制降级逻辑原样移动到 `ui.js`。`copyText` 优先使用 Clipboard API，失败时调用现有 textarea + `document.execCommand('copy')` 路径；完成后调用同一个 Toast 实现。`safeCreateIcons(root)` 仅检查 Lucide 可用性并刷新图标，不保存页面状态。

- [ ] **Step 5: 写入缓存中间件失败测试**

创建 `MerchantAssetCacheMiddlewareTest`，用真实 Webman Request/Response 断言三个入口重新验证、普通片段不被改写：

```php
#[DataProvider('revalidatedPathProvider')]
public function testEntryAssetsRequireRevalidation(string $path): void
{
    $request = new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $response = (new MerchantAssetCacheMiddleware())->process(
        $request,
        static fn (): Response => new Response(200, [], 'ok')
    );
    self::assertSame('no-cache, must-revalidate', $response->getHeader('Cache-Control'));
}

public static function revalidatedPathProvider(): iterable
{
    yield ['/merchant_center.html'];
    yield ['/merchant/assets/app.js'];
    yield ['/merchant/assets/version.js'];
}
```

Run and confirm class-not-found failure.

- [ ] **Step 6: 实现并注册精确缓存中间件**

`MerchantAssetCacheMiddleware` 只匹配：

```php
private const REVALIDATED_PATHS = [
    'merchant_center.html',
    'merchant/assets/app.js',
    'merchant/assets/version.js',
];
```

在 `config/static.php` 的 middleware 数组中追加该类，不替换现有 `AdminAssetCacheMiddleware`。

- [ ] **Step 7: 验证并提交公共运行时**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantAssetCacheMiddlewareTest.php
node --check public/merchant/assets/version.js
node --check public/merchant/assets/api.js
node --check public/merchant/assets/ui.js
php vendor/bin/phpunit --display-warnings
git diff --check
git add -- app/middleware/MerchantAssetCacheMiddleware.php config/static.php public/merchant/assets tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantAssetCacheMiddlewareTest.php
git commit -m "refactor: extract merchant center core runtime"
```

---

### Task 3: 建立渐进式商户功能路由

**Files:**
- Create: `public/merchant/assets/router.js`
- Create: `public/merchant/assets/app.js`
- Create: `tests/Fixtures/merchant_center_router.php`
- Modify: `public/merchant_center.html`
- Modify: `tests/Unit/MerchantCenterModuleContractTest.php`

**Interfaces:**
- Produces: `createRouter({ container, definitions, context, activateLegacy, activateFeature })`、`window.CXMerchant.navigate(id)`、`context.getMerchantProfile({ refresh })`、`context.shell.applyProfile(profile)`、`context.shell.applyDashboard(data)`。
- Consumes: Task 2 的 `assetUrl`、`merchantFetch` 和公共 UI。

- [ ] **Step 1: 写入唯一入口与路由回退失败测试**

增加断言：

```php
public function testPageLoadsOneMerchantApplicationEntry(): void
{
    $html = (string) file_get_contents(self::MERCHANT_CENTER);
    self::assertSame(1, substr_count($html, 'type="module" src="/merchant/assets/app.js"'));
    self::assertFileExists(self::MERCHANT . '/assets/app.js');
    self::assertFileExists(self::MERCHANT . '/assets/router.js');
}
```

Node 测试导入 `resolveFeatureId(id, definitions)`，手工断言注册 ID 原样返回、未知 ID 返回 `dashboard`。

- [ ] **Step 2: 运行测试并确认入口和路由缺失**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php --filter 'ApplicationEntry|Router'
```

Expected: `app.js` 和 `router.js` 不存在导致失败。

- [ ] **Step 3: 实现带竞态保护的渐进式路由**

`router.js` 使用以下状态：

```javascript
const fragmentCache = new Map();
let activeFeature = null;
let activeController = null;
let navigation = 0;
```

`navigate(requestedId)` 必须按此顺序执行：解析 ID、递增导航号、中止旧控制器、等待旧 `unmount()`、未迁移定义调用 `activateLegacy(id)`、已迁移定义并行加载片段与模块、检查导航号、挂载、捕获非 AbortError 并渲染重试卡片。成功片段才写入缓存。

- [ ] **Step 4: 实现入口的会话快照与壳层窄接口**

`app.js` 使用带版本的动态导入，并提供：

```javascript
let profilePromise = null;

async function getMerchantProfile({ refresh = false } = {}) {
    if (refresh || !profilePromise) {
        profilePromise = api.merchantFetch('/api/merchant/profile')
            .then((response) => response.json())
            .then((payload) => {
                if (payload.code !== 1 || !payload.data) {
                    throw new Error(payload.msg || '商户资料加载失败');
                }
                shell.applyProfile(payload.data);
                return Object.freeze({ ...payload.data });
            })
            .catch((error) => {
                profilePromise = null;
                throw error;
            });
    }
    return profilePromise;
}
```

`shell.applyProfile()` 只更新侧边栏 PID、余额、顶部商户名；`shell.applyDashboard()` 只更新壳层余额。功能片段字段不在 shell 中查询。

- [ ] **Step 5: 加入混合迁移根节点与兼容导航**

在原面板容器顶部添加：

```html
<div id="merchant-feature-root" class="hidden"></div>
```

页面末尾只增加：

```html
<script type="module" src="/merchant/assets/app.js"></script>
```

旧 `switchTab(tabId)` 暂时缩减为 `window.CXMerchant.navigate(tabId)` 兼容包装；入口负责初始哈希导航，删除旧 `DOMContentLoaded` 的二次初始化，避免模块加载时序竞争。

- [ ] **Step 6: 创建无数据库浏览器 fixture**

`tests/Fixtures/merchant_center_router.php`：

- 静态文件交给 PHP 内建服务器；
- `/api/merchant/profile` 返回完整 PID、name、money、rate、key、gateway_url、mapi_url、site_url；
- `/api/merchant/dashboard`、订单、财务、套餐、通道和告警路径返回与真实响应字段一致的最小数据；
- 未识别 API 返回 `{ "code": 1, "msg": "浏览器冒烟测试模拟响应", "data": [] }`；
- 不连接数据库，不在正式目录加入假实现。

- [ ] **Step 7: 验证渐进式入口并提交**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantCenterSourceIntegrityTest.php
node --check public/merchant/assets/router.js
node --check public/merchant/assets/app.js
php -l tests/Fixtures/merchant_center_router.php
php vendor/bin/phpunit --display-warnings
git diff --check
git add -- public/merchant_center.html public/merchant/assets tests/Fixtures/merchant_center_router.php tests/Unit/MerchantCenterModuleContractTest.php
git commit -m "refactor: add progressive merchant center router"
```

---

### Task 4: 迁移仪表盘、账户与 API 密钥

**Files:**
- Create: `public/merchant/views/dashboard.html`
- Create: `public/merchant/views/profile.html`
- Create: `public/merchant/views/api-keys.html`
- Create: `public/merchant/assets/features/dashboard.js`
- Create: `public/merchant/assets/features/profile.js`
- Create: `public/merchant/assets/features/api-keys.js`
- Modify: `public/merchant/assets/app.js`
- Modify: `public/merchant/assets/version.js`
- Modify: `public/merchant_center.html`
- Modify: `tests/Unit/MerchantCenterModuleContractTest.php`

**Interfaces:**
- Feature IDs: `dashboard`、`profile`、`api-keys`。
- APIs: `/api/merchant/dashboard`、`/api/merchant/order/list?page_size=5`、`/api/merchant/order/list?page_size=200&status=1`、`/api/merchant/change_password`、`/api/merchant/reset_key`、`/api/merchant/profile`。

- [ ] **Step 1: 写入三个功能的失败契约**

数据提供器逐项断言 view/module 文件存在、片段根节点含对应 `data-feature`、模块运行时导出 `feature.mount/unmount`。账户模块契约触发密码 API，密钥模块契约触发 reset API；仪表盘契约检查三个数据 URL。

```php
#[DataProvider('identityFeatureProvider')]
public function testIdentityFeaturePairExists(string $file, string $feature): void
{
    $view = self::MERCHANT . '/views/' . $file . '.html';
    $module = self::MERCHANT . '/assets/features/' . $file . '.js';
    self::assertFileExists($view);
    self::assertFileExists($module);
    self::assertStringContainsString('data-feature="' . $feature . '"', (string) file_get_contents($view));
}
```

- [ ] **Step 2: 运行并确认六个目标文件缺失**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php --filter IdentityFeature
```

Expected: 三个 view 和三个 feature 不存在导致失败。

- [ ] **Step 3: 原样迁移三个面板并移除内联事件**

每个 view 使用唯一根 section。仪表盘的套餐、订单、API 和通道快捷入口改为 `data-navigate`；账户密码按钮改为 `data-action="change-password"`；密钥复制和重置改为 `data-action` 与 `data-copy-source`。删除原页面对应 `tab-*` 面板。

- [ ] **Step 4: 实现可卸载仪表盘**

`dashboard.js` 保存 `chart` 和 `resizeHandler`。`mount()` 并行请求统计、最近订单和趋势订单，调用 `shell.applyDashboard(data)`，只在 root 内填充卡片与表格；根事件代理调用 `navigate(target)`。`unmount()` 必须：

```javascript
chart?.dispose();
chart = null;
resizeHandler = null;
```

resize 监听使用路由传入的 `signal` 注册，不能使用匿名且不可移除的全局监听。

- [ ] **Step 5: 实现账户和密钥模块**

`profile.mount()` 调用 `getMerchantProfile()` 填充 name/PID，密码修改保持 `application/x-www-form-urlencoded` 和 `current_password/new_password` 字段。`api-keys.mount()` 使用同一资料快照填充全部地址、PID、key；重置成功后调用 `getMerchantProfile({ refresh: true })` 再渲染，不把 key 放入全局变量。

- [ ] **Step 6: 注册功能、删除旧函数并更新版本**

`app.js` 注册三项：

```javascript
definitions.set('dashboard', { view: 'dashboard.html', module: 'dashboard.js' });
definitions.set('profile', { view: 'profile.html', module: 'profile.js' });
definitions.set('api-keys', { view: 'api-keys.html', module: 'api-keys.js' });
```

从大页面删除仪表盘、趋势图、资料、密码、密钥重置和复制函数。资源版本更新为 `merchant-modules-v2`。

- [ ] **Step 7: 验证生命周期与浏览器三标签**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantCenterSourceIntegrityTest.php
Get-ChildItem public/merchant/assets -Recurse -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
php vendor/bin/phpunit --display-warnings
```

用 fixture 启动 PHP 服务，Playwright 依次进入 dashboard/profile/api-keys；反复进入 dashboard 三次，确认最终只有一个图表画布且控制台无 ECharts 重复初始化异常。结束后关闭浏览器会话和服务器。

- [ ] **Step 8: 提交身份与仪表盘模块**

```powershell
git add -- public/merchant_center.html public/merchant tests/Unit/MerchantCenterModuleContractTest.php
git commit -m "refactor: modularize merchant overview and identity"
```

---

### Task 5: 迁移商户通道与授权生命周期

**Files:**
- Create: `public/merchant/views/channels.html`
- Create: `public/merchant/assets/features/channels.js`
- Create: `public/merchant/assets/features/channel-editor.js`
- Create: `public/merchant/assets/features/channel-authorization.js`
- Modify: `public/merchant/assets/app.js`
- Modify: `public/merchant/assets/version.js`
- Modify: `public/merchant_center.html`
- Modify: `tests/Fixtures/merchant_center_router.php`
- Modify: `tests/Unit/MerchantCenterModuleContractTest.php`

**Interfaces:**
- `createChannelEditor({ root, api, ui, signal, reload, authorization })` returns `{ openNew(), open(item), close(), submit(silent), dispose() }`。
- `createChannelAuthorization({ root, api, ui, signal })` returns `{ configureBillSource(id), detectCapabilities(id), start(id), closeQr(), dispose() }`。
- Feature ID: `channel-list`。

- [ ] **Step 1: 写入通道文件、API 和轮询中止失败契约**

契约断言四个目标文件存在且各自不超过 400 行；通道组合必须保留这些边界：

```php
foreach ([
    '/api/merchant/channel/list',
    '/api/merchant/channel/save',
    '/api/merchant/channel/toggle',
    '/api/merchant/channel/delete',
    '/api/merchant/channel/capabilities',
    '/api/merchant/channel/authorization/start',
    '/api/merchant/channel/authorization/poll',
    '/api/merchant/channel/drivers',
    '/api/merchant/bill-source/status',
    '/api/merchant/bill-source/rotate-token',
] as $api) {
    self::assertStringContainsString($api, $combinedSource);
}
```

Node 行为测试给 `createChannelAuthorization` 注入可计数 fetch 和 AbortSignal：启动授权后 abort，断言后续两秒不再增加 poll 请求次数。该测试必须先因模块缺失失败。

- [ ] **Step 2: 运行并确认通道模块缺失**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php --filter Channel
```

- [ ] **Step 3: 迁移通道视图和动态动作**

把通道卡片容器、添加/编辑弹窗、二维码上传区和动态驱动字段容器移动到 `channels.html`。静态按钮全部改为 `data-action`；动态卡片只输出 `data-channel-id`、`data-next-status`，标题、类型和说明继续通过 `escapeHtml`。

- [ ] **Step 4: 实现列表协调器 `channels.js`**

模块内部只保存 `Map<number, channel>`。`mount()` 创建 editor 与 authorization，代理添加、编辑、启停、删除、账单源和账号授权动作，并加载列表。`unmount()` 顺序调用两个控制器的 `dispose()`、清空 Map、释放 root 引用。

- [ ] **Step 5: 实现编辑器 `channel-editor.js`**

迁移分类/驱动切换、动态配置字段、二维码读取、计划限制检查和 `/channel/save`。驱动配置值写回 form 时按原字段名处理；jsQR 不可用或无法识别时显示原错误文案。`submit(true)` 供账号授权前静默保存，普通保存成功调用 `reload()`。

- [ ] **Step 6: 实现授权控制器 `channel-authorization.js`**

控制器拥有独立 `AbortController`，并把父级 `signal.abort` 转发给它。轮询每两秒执行一次，满足成功、失败、过期或中止任一条件立即退出。二维码 DOM 由控制器创建并在 `closeQr()` 删除；`dispose()` 必须中止请求并移除二维码。

- [ ] **Step 7: 扩充 fixture 并做浏览器通道回归**

fixture 为 list、drivers、capabilities、save、toggle、delete、authorization/start、authorization/poll、bill-source/status、rotate-token 返回完整字段。Playwright 验证：列表出现、打开新增弹窗、驱动字段切换、关闭弹窗、启动授权后离开通道页；网络记录确认离开后不再产生 poll。

- [ ] **Step 8: 注册、清理旧实现、更新版本并提交**

注册 `channel-list -> channels.html/channels.js`，删除原通道面板、全局弹窗与从 `editChannelById` 到 `renderDriverConfigFields` 的旧函数；更新为 `merchant-modules-v3`。

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantCenterSourceIntegrityTest.php
Get-ChildItem public/merchant/assets/features -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
php vendor/bin/phpunit --display-warnings
git diff --check
git add -- public/merchant_center.html public/merchant tests/Fixtures/merchant_center_router.php tests/Unit/MerchantCenterModuleContractTest.php
git commit -m "refactor: modularize merchant channels and authorization"
```

---

### Task 6: 迁移收银台配置与轮询组

**Files:**
- Create: `public/merchant/views/cashier.html`
- Create: `public/merchant/views/poll-groups.html`
- Create: `public/merchant/assets/features/cashier.js`
- Create: `public/merchant/assets/features/poll-groups.js`
- Modify: `public/merchant/assets/app.js`
- Modify: `public/merchant/assets/version.js`
- Modify: `public/merchant_center.html`
- Modify: `tests/Unit/MerchantCenterModuleContractTest.php`

**Interfaces:**
- Feature IDs: `channel-config`、`poll-group`。
- Persistence: `localStorage['cx_cashier_config']`。
- API: `/api/merchant/channel/list`。

- [ ] **Step 1: 写入配置持久化与功能对失败测试**

Node 测试导入 `readCashierConfig(storage)` 和 `normalizeCashierConfig(formValue)`，使用手工数据断言：缺失配置返回 timeout 180、redirect `return_url`、tts true、mapi `qrcode`、theme `classic_blue`；timeout 59 和 301 返回验证错误。文件不存在时测试失败。

- [ ] **Step 2: 迁移收银台面板与模块**

移动完整设置表单和四个主题卡片，全部事件改为根代理。模块导出可测试的纯函数：

```javascript
export function readCashierConfig(storage) {}
export function validateCashierConfig(config) {}
export const feature = { id: 'channel-config', async mount(context) {}, unmount() {} };
```

主题保存在模块变量 `selectedTheme`；`unmount()` 调用 `speechSynthesis.cancel()`，清空 root 和主题状态。

- [ ] **Step 3: 迁移轮询组面板与模块**

保持当前语义：只读取启用通道，按 `pay_category` 分组，组内均分权重。标签与颜色映射保留微信、支付宝、QQ 钱包和其他四类；所有标题和 c_type 转义。

- [ ] **Step 4: 注册、删除旧实现并更新版本**

注册 `channel-config` 与 `poll-group`，删除原两个面板以及 `adjustTimeout` 到 `loadPollGroups` 的对应函数；资源版本更新为 `merchant-modules-v4`。

- [ ] **Step 5: 验证并提交**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantCenterSourceIntegrityTest.php
node --check public/merchant/assets/features/cashier.js
node --check public/merchant/assets/features/poll-groups.js
php vendor/bin/phpunit --display-warnings
git diff --check
git add -- public/merchant_center.html public/merchant tests/Unit/MerchantCenterModuleContractTest.php
git commit -m "refactor: modularize merchant cashier settings"
```

---

### Task 7: 迁移订单、财务与套餐

**Files:**
- Create: `public/merchant/views/orders.html`
- Create: `public/merchant/views/finance.html`
- Create: `public/merchant/views/plans.html`
- Create: `public/merchant/assets/features/orders.js`
- Create: `public/merchant/assets/features/finance.js`
- Create: `public/merchant/assets/features/plans.js`
- Modify: `public/merchant/assets/app.js`
- Modify: `public/merchant/assets/version.js`
- Modify: `public/merchant_center.html`
- Modify: `tests/Unit/MerchantCenterModuleContractTest.php`

**Interfaces:**
- Feature IDs: `order-list`、`finance-log`、`plan-buy`。
- APIs: `/api/merchant/order/list`、`/api/merchant/finance_log`、`/api/merchant/plan/list`、`/api/merchant/plan/buy`。

- [ ] **Step 1: 写入三功能失败契约**

断言 view/module 配对存在，运行时导出有效 feature；订单状态映射必须保留 0 待支付、1 已完成、2 已超时/关闭、3 已退款；套餐购买保持 `plan_id` 字段和 urlencoded POST。

- [ ] **Step 2: 迁移订单和财务模块**

移动表格结构和加载逻辑。模块只在 root 内查询 tbody；错误响应显示在表格空态。订单号、商户订单号、标题、支付类型、时间和财务备注继续转义；金额继续显式 Number 转换和两位小数。

- [ ] **Step 3: 迁移套餐模块**

模块保存套餐 Map，渲染当前套餐 Banner 与可购买卡片。购买点击通过 dataset 查 Map，确认框使用 `ui.showConfirm`；成功后重新加载套餐并调用 `getMerchantProfile({ refresh: true })` 更新壳层余额，不使用原生 confirm。

- [ ] **Step 4: 注册、删除旧实现并更新版本**

注册三个功能，删除原面板以及 `loadMerchantOrderList`、`loadMerchantFinanceLogs`、`loadMerchantPlans`、`buyMerchantPlan`；资源版本更新为 `merchant-modules-v5`。

- [ ] **Step 5: 浏览器验证关键请求与提交**

Playwright 依次进入订单、财务、套餐，确认表格/卡片；点击购买后接受自定义确认框，检查请求方法、Content-Type 和 `plan_id`。然后执行：

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantCenterSourceIntegrityTest.php
Get-ChildItem public/merchant/assets/features -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
php vendor/bin/phpunit --display-warnings
git diff --check
git add -- public/merchant_center.html public/merchant tests/Unit/MerchantCenterModuleContractTest.php
git commit -m "refactor: modularize merchant transactions and plans"
```

---

### Task 8: 迁移通知、删除兼容层并完成最终验收

**Files:**
- Create: `public/merchant/views/alerts.html`
- Create: `public/merchant/assets/features/alerts.js`
- Modify: `public/merchant/assets/app.js`
- Modify: `public/merchant/assets/router.js`
- Modify: `public/merchant/assets/version.js`
- Modify: `public/merchant_center.html`
- Modify: `tests/Fixtures/merchant_center_router.php`
- Modify: `tests/Unit/MerchantCenterModuleContractTest.php`
- Modify: `tests/Unit/MerchantCenterSourceIntegrityTest.php`
- Modify: `docs/superpowers/specs/2026-08-09-merchant-center-modularization-design.md`

**Interfaces:**
- Feature ID: `notice-config`。
- APIs: `/api/merchant/alert/config`、`/api/merchant/alert/config/save`、`/api/merchant/alert/test`。
- Finalizes: 无旧面板、无兼容 `switchTab`、无内联业务函数的十功能运行时。

- [ ] **Step 1: 写入最终壳层和通知功能失败测试**

```php
public function testFinalShellHasNoInlineBusinessCode(): void
{
    $html = (string) file_get_contents(self::MERCHANT_CENTER);
    self::assertLessThanOrEqual(500, substr_count($html, "\n") + 1);
    self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/si', $html);
    self::assertStringNotContainsString('onclick=', $html);
    self::assertStringNotContainsString('onchange=', $html);
}

public function testAllMerchantFeaturesStayWithinSizeLimit(): void
{
    foreach (glob(self::MERCHANT . '/{assets/features,views}/*', GLOB_BRACE) ?: [] as $path) {
        if (is_file($path)) {
            self::assertLessThanOrEqual(400, substr_count((string) file_get_contents($path), "\n") + 1, $path);
        }
    }
}
```

增加 alerts view/module 与三个 API 的契约。Run and confirm：alerts 文件缺失、页面仍有通知面板/内联代码，测试失败。

- [ ] **Step 2: 迁移通知视图与模块**

移动开关、事件矩阵、低余额阈值和三个渠道表单。`mount()` 加载配置并绑定保存、邮箱后缀和测试动作；save 继续使用 urlencoded 字段，test 保持 `{ channel }`。用 Toast 替换当前 `alert()` 时必须保留服务端消息内容和错误可见性。

- [ ] **Step 3: 删除最终混合迁移兼容路径**

十项全部注册后：

- `router.js` 删除 `activateLegacy`；
- `app.js` 在 `#app` 代理 `[data-tab]`、`[data-navigate]` 和 `[data-action="logout-merchant"]`；
- 壳层导航 `onclick` 改为 `data-tab`；
- 删除所有 `.tab-panel`、旧 `switchTab`、旧 Toast/确认/复制/转义、旧业务函数和内联 `<script>`；
- 退出请求失败时仍跳转 `/merchant_login.html`；
- 初始哈希由 app 唯一触发，未知哈希回退 `dashboard`；
- 更新资源版本为 `merchant-modules-v6`。

- [ ] **Step 4: 执行十标签与竞态浏览器验收**

启动：

```powershell
php -S 127.0.0.1:18894 -t public tests/Fixtures/merchant_center_router.php
```

用 Playwright CLI 执行：

1. 打开 `/merchant_center.html#dashboard`，确认资料摘要与 `[data-feature="dashboard"]`；
2. 依次点击 dashboard、profile、notice-config、channel-list、channel-config、poll-group、order-list、finance-log、plan-buy、api-keys；
3. 每步断言当前标题、哈希和唯一 `[data-feature]`；
4. 不等待地调用 channel-list、plan-buy、order-list，最终必须只显示 orders；
5. 拦截 alerts 片段返回 404，确认错误卡片；解除拦截后点击重试并恢复；
6. 检查 console，除刻意制造的 404 外不得出现应用错误；
7. 关闭 Playwright 会话与精确 PHP 监听进程，删除测试产物。

- [ ] **Step 5: 运行最终自动门禁**

```powershell
php vendor/bin/phpunit tests/Unit/MerchantCenterSourceIntegrityTest.php tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantAssetCacheMiddlewareTest.php
Get-ChildItem public/merchant/assets -Recurse -File -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
$phpFiles = @(Get-ChildItem app,config,support,tests -Recurse -File -Filter '*.php' -ErrorAction SilentlyContinue)
foreach ($file in $phpFiles) { php -l $file.FullName | Out-Null; if ($LASTEXITCODE -ne 0) { exit 1 } }
php vendor/bin/phpunit --display-warnings
git diff --check
git status --short
```

Expected: 页面壳不超过 500 行；所有模块/片段不超过 400 行；根测试不少于 293 个且全部通过；只有 `CXPAY.rar` 在主工作区保持未跟踪。

- [ ] **Step 6: 更新设计实施记录并提交**

在设计文档追加实际壳层行数、最大模块行数、十标签浏览器结果、测试总数和未实施的后续范围。

```powershell
git add -- app/middleware/MerchantAssetCacheMiddleware.php config/static.php public/merchant_center.html public/merchant tests/Fixtures/merchant_center_router.php tests/Unit/MerchantCenterSourceIntegrityTest.php tests/Unit/MerchantCenterModuleContractTest.php tests/Unit/MerchantAssetCacheMiddlewareTest.php docs/superpowers/specs/2026-08-09-merchant-center-modularization-design.md
git commit -m "test: verify modular merchant center lifecycle"
```

## Completion Gate

只有同时满足以下条件才进入 `AdminController` 或 `OrderService` 拆分：

- `public/merchant_center.html` 不超过 500 行且只有一个模块入口；
- 十个哈希标签均有独立 view/feature，所有前端生产文件不超过 400 行；
- 页面不存在重复函数、重复静态 ID、内联业务脚本或内联事件；
- 商户会话、API URL、请求编码、响应语义和 `cx_cashier_config` 不变；
- 图表、事件、语音和授权轮询在卸载时释放；
- 十标签、快速切换、404 重试和关键动作浏览器回归通过；
- Node、PHP 语法、契约测试和根 PHPUnit 全部通过；
- 八个任务形成独立提交；
- `CXPAY.rar` 未被修改或提交。
