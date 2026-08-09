# 管理后台页面模块化 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不改变管理后台 URL、视觉、API 和权限行为的前提下，把 3187 行的 `public/admin/index.html` 拆成不超过 500 行的页面壳、同源 HTML 片段和单职责原生 JavaScript 模块，并清除当前冲突标记、重复 ID 与被覆盖函数。

**Architecture:** `/admin/index.html` 保持唯一入口，`assets/app.js` 初始化公共请求、UI 和混合迁移路由；每个标签页对应一个 `views/*.html` 和一个 `assets/features/*.js`。迁移期间路由只对已迁移功能加载片段，未迁移标签继续使用原内联面板；最后一个任务删除兼容回退和大段内联脚本。

**Tech Stack:** Webman 静态资源、原生 ES Module、浏览器 Fetch/AbortController、Tailwind CDN、Lucide、ECharts、PHPUnit 10、Node.js 22 语法检查。

## Global Constraints

- 不改变 `/admin/index.html` 入口、既有 API URL、HTTP 方法、请求字段、响应结构和 `cx_admin_token` 本地存储键。
- 不改变页面视觉、中文文案、导航顺序和权限行为；只允许修复不可达重复面板、冲突标记和被覆盖实现造成的确定性缺陷。
- 不引入 Vue、React、Vite、Webpack、npm 生产依赖或新的部署构建步骤。
- 页面壳不超过 500 行；单个 JavaScript 模块或 HTML 片段不超过 400 行。
- 公共请求、转义、Toast、确认框和图标刷新只能有一个实现来源。
- 每个功能模块必须实现 `mount(context)` 和 `unmount()`；切换时释放事件、计时器和 ECharts 实例。
- 所有模块和片段使用同一个 `ASSET_VERSION`；修改模块或片段时在同一提交更新版本。
- 根 PHPUnit 测试数量不得低于实施前的 262 个；用户未跟踪文件 `CXPAY.rar` 不得修改或提交。

## Target File Map

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
│     ├─ system-update.js
│     ├─ cloud-monitor.js
│     ├─ channels.js
│     ├─ plugins.js
│     ├─ merchants.js
│     ├─ plans.js
│     ├─ orders.js
│     ├─ callbill.js
│     └─ alerts.js
└─ views/
   ├─ dashboard.html
   ├─ system-update.html
   ├─ cloud-monitor.html
   ├─ channels.html
   ├─ plugins.html
   ├─ merchants.html
   ├─ plans.html
   ├─ orders.html
   ├─ callbill.html
   └─ alerts.html

tests/
├─ Fixtures/admin_page_router.php
└─ Unit/
   ├─ AdminPageSourceIntegrityTest.php
   ├─ AdminPageModuleContractTest.php
   ├─ AdminAssetCacheMiddlewareTest.php
   └─ AdminChannelFrontendContractTest.php

app/middleware/
└─ AdminAssetCacheMiddleware.php
```

---

### Task 1: 恢复确定性的管理页源码基线

**Files:**
- Create: `tests/Unit/AdminPageSourceIntegrityTest.php`
- Modify: `public/admin/index.html`

**Interfaces:**
- Consumes: 当前浏览器实际显示的第一个 `tab-system-update` 面板，以及后声明覆盖前声明的函数语义。
- Produces: 不含 Git 冲突标记、重复静态 ID 和重复函数声明的单文件基线，供后续机械迁移。

- [ ] **Step 1: 编写失败的源码完整性测试**

创建 `tests/Unit/AdminPageSourceIntegrityTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminPageSourceIntegrityTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/public/admin/index.html');
        self::assertIsString($html);
        $this->html = $html;
    }

    public function testSourceContainsNoMergeConflictMarkers(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/^(<<<<<<<|=======|>>>>>>>)\s.*$/m',
            $this->html
        );
    }

    public function testStaticMarkupIdsAreUnique(): void
    {
        $markup = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $this->html);
        self::assertIsString($markup);
        preg_match_all('/\bid="([^"]+)"/', $markup, $matches);
        $counts = array_count_values($matches[1]);
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        self::assertSame([], $duplicates, '静态 DOM id 不得重复');
    }

    public function testNamedBusinessFunctionsAreUnique(): void
    {
        preg_match_all(
            '/\b(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
            $this->html,
            $matches
        );
        $counts = array_count_values($matches[1]);
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        self::assertSame([], $duplicates, '具名业务函数不得依赖后声明覆盖前声明');
    }

    public function testVisibleSystemUpdatePanelUsesGitUpdateActions(): void
    {
        self::assertSame(1, substr_count($this->html, 'id="tab-system-update"'));
        self::assertStringContainsString('checkGitUpdate()', $this->html);
        self::assertStringContainsString('executeGitUpdate()', $this->html);
        self::assertStringContainsString('loadGitVersionHistory()', $this->html);
    }
}
```

- [ ] **Step 2: 运行测试并确认现有损坏被捕获**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageSourceIntegrityTest.php
```

Expected: FAIL，至少报告 6 个冲突标记、重复 `tab-system-update`，以及 `closeChannelConfigEditor`、`submitChannelConfig`、`installPluginPackage`、`uninstallPlugin` 重复声明。

- [ ] **Step 3: 删除冲突标记和不可达系统更新实现**

在 `public/admin/index.html` 执行以下确定性选择：

- 删除 6 行 `<<<<<<< HEAD`、`=======`、`>>>>>>> gitee/main` 标记，保留标记之间的 HEAD 内容；
- 保留浏览器当前实际显示的第一个 Git 更新面板，其按钮调用 `checkGitUpdate()`、`executeGitUpdate()`；
- 删除第二个不可达的 `tab-system-update` 面板；
- 删除只服务第二面板的 `checkSystemUpdate()`、`doSystemUpdate()`、`loadVersionHistory()`；
- 将 `switchTab('system-update')` 的初始化改为 `checkGitUpdate(); loadGitVersionHistory();`；
- 通道保存保留与 `openChannelConfigEditor()` 和 `data-config-key` 匹配、调用 `/api/admin/channel/config/save` 的实现，删除后部不携带 `id` 的覆盖实现；
- 插件页面保留导航实际调用的 `loadInstalledPlugins()` 体系，删除 `loadPluginMarket()` 产生的第二套安装/卸载覆盖实现；保留其中不重复且页面仍使用的启停、回滚动作。

- [ ] **Step 4: 运行完整性与既有前端契约测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageSourceIntegrityTest.php tests/Unit/AdminChannelFrontendContractTest.php tests/Unit/AdminChannelPageTest.php
```

Expected: PASS；管理页只剩一个系统更新面板和每个动作一个实现。

- [ ] **Step 5: 运行根项目回归并提交基线修复**

Run:

```powershell
php vendor/bin/phpunit --display-warnings
git diff --check
```

Expected: 不少于 266 个测试全部通过，`git diff --check` 无错误。

Commit:

```powershell
git add public/admin/index.html tests/Unit/AdminPageSourceIntegrityTest.php
git commit -m "fix: restore deterministic admin page source"
```

---

### Task 2: 建立公共请求、UI 与版本模块

**Files:**
- Create: `public/admin/assets/version.js`
- Create: `public/admin/assets/api.js`
- Create: `public/admin/assets/ui.js`
- Create: `app/middleware/AdminAssetCacheMiddleware.php`
- Create: `tests/Unit/AdminPageModuleContractTest.php`
- Create: `tests/Unit/AdminAssetCacheMiddlewareTest.php`
- Modify: `public/admin/index.html`
- Modify: `config/static.php`

**Interfaces:**
- Produces: `ASSET_VERSION`、`assetUrl(path)`、`adminFetch(url, options)`、`escapeHtml(value)`、`safeCreateIcons()`、`showToast(message, type)`、`showConfirm(message)`。
- Consumes: `localStorage['cx_admin_token']` 和 `/admin_login.html` 登录入口。

- [ ] **Step 1: 编写公共模块失败契约**

创建 `tests/Unit/AdminPageModuleContractTest.php` 的首批测试：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminPageModuleContractTest extends TestCase
{
    private const ADMIN = __DIR__ . '/../../public/admin';

    #[DataProvider('coreModuleProvider')]
    public function testCoreModuleExistsAndIsFocused(string $file, array $exports): void
    {
        $path = self::ADMIN . '/assets/' . $file;
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1);
        foreach ($exports as $export) {
            self::assertStringContainsString('export ' . $export, $source);
        }
    }

    public static function coreModuleProvider(): iterable
    {
        yield 'version' => ['version.js', ['const ASSET_VERSION', 'function assetUrl']];
        yield 'api' => ['api.js', ['async function adminFetch']];
        yield 'ui' => ['ui.js', [
            'function escapeHtml',
            'function safeCreateIcons',
            'function showToast',
            'function showConfirm',
        ]];
    }
}
```

- [ ] **Step 2: 运行契约并确认模块缺失**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageModuleContractTest.php
```

Expected: FAIL，三个模块文件不存在。

- [ ] **Step 3: 实现显式资源版本**

`public/admin/assets/version.js`：

```javascript
export const ASSET_VERSION = 'admin-modules-v1';

export function assetUrl(path) {
    const url = new URL(path, window.location.origin);
    url.searchParams.set('v', ASSET_VERSION);
    return url.href;
}
```

- [ ] **Step 4: 迁移唯一请求实现**

`public/admin/assets/api.js`：

```javascript
export async function adminFetch(url, options = {}) {
    const headers = new Headers(options.headers || {});
    const token = localStorage.getItem('cx_admin_token');
    if (token) headers.set('Authorization', `Bearer ${token}`);

    const response = await fetch(url, { ...options, headers });
    if (response.status === 401) {
        localStorage.removeItem('cx_admin_token');
        window.location.assign('/admin_login.html');
        throw new Error('管理员登录状态已失效');
    }
    return response;
}
```

从 `index.html` 删除原 `adminFetch()` 主体，暂时保留同名兼容包装：

```javascript
function adminFetch(...args) {
    return window.CXAdmin.api.adminFetch(...args);
}
```

- [ ] **Step 5: 迁移唯一 UI 实现**

`ui.js` 导出转义、图标刷新、Toast 和 Promise 确认框。`showConfirm()` 必须复用现有自定义确认弹窗，不改回浏览器原生 `confirm()`：

```javascript
export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
    })[character]);
}

export function safeCreateIcons() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
}
```

`showToast()` 和 `showConfirm()` 移动现有 DOM 逻辑，不改变 CSS class、中文文案或返回语义。

- [ ] **Step 6: 在页面中加载公共模块兼容层**

在现有业务脚本之前增加：

```html
<script type="module">
    import * as api from '/admin/assets/api.js';
    import * as ui from '/admin/assets/ui.js';
    window.CXAdmin = Object.freeze({ api, ui });
</script>
```

旧内联业务函数通过轻量包装调用 `window.CXAdmin`，直到对应功能迁移为模块。

- [ ] **Step 7: 为入口和版本模块设置重新验证缓存策略**

创建只作用于三个管理页入口资源的静态中间件：

```php
<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class AdminAssetCacheMiddleware implements MiddlewareInterface
{
    private const REVALIDATED_PATHS = [
        'admin/index.html',
        'admin/assets/app.js',
        'admin/assets/version.js',
    ];

    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);
        if (in_array(ltrim($request->path(), '/'), self::REVALIDATED_PATHS, true)) {
            $response->withHeader('Cache-Control', 'no-cache, must-revalidate');
        }
        return $response;
    }
}
```

在 `config/static.php` 注册：

```php
'middleware' => [app\middleware\AdminAssetCacheMiddleware::class],
```

`AdminAssetCacheMiddlewareTest` 使用真实 Webman Request/Response 验证入口资源有响应头、其他静态资源不被修改：

```php
$request = new \Webman\Http\Request(
    "GET /admin/assets/app.js HTTP/1.1\r\nHost: localhost\r\n\r\n"
);
$response = (new \app\middleware\AdminAssetCacheMiddleware())->process(
    $request,
    static fn (): \Webman\Http\Response => new \Webman\Http\Response(200, [], 'ok')
);
self::assertSame('no-cache, must-revalidate', $response->getHeader('Cache-Control'));
```

- [ ] **Step 8: 运行契约、语法和完整回归**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageModuleContractTest.php tests/Unit/AdminPageSourceIntegrityTest.php
php vendor/bin/phpunit tests/Unit/AdminAssetCacheMiddlewareTest.php
node --check public/admin/assets/version.js
node --check public/admin/assets/api.js
node --check public/admin/assets/ui.js
php vendor/bin/phpunit --display-warnings
```

Expected: 全部 PASS。

Commit:

```powershell
git add app/middleware/AdminAssetCacheMiddleware.php config/static.php public/admin/index.html public/admin/assets tests/Unit/AdminPageModuleContractTest.php tests/Unit/AdminAssetCacheMiddlewareTest.php
git commit -m "refactor: extract admin page core modules"
```

---

### Task 3: 建立可渐进迁移的标签路由

**Files:**
- Create: `public/admin/assets/router.js`
- Create: `public/admin/assets/app.js`
- Create: `tests/Fixtures/admin_page_router.php`
- Modify: `public/admin/index.html`
- Modify: `tests/Unit/AdminPageModuleContractTest.php`

**Interfaces:**
- Produces: `createRouter({ container, definitions, context, activateLegacy })` 和全局兼容入口 `window.CXAdmin.navigate(tabId)`。
- Feature contract: `export const feature = { id, async mount(context), unmount() }`。

- [ ] **Step 1: 增加路由文件与唯一入口失败测试**

向 `AdminPageModuleContractTest` 增加：

```php
public function testAdminPageLoadsOneApplicationEntry(): void
{
    $html = file_get_contents(self::ADMIN . '/index.html');
    self::assertIsString($html);
    self::assertSame(1, substr_count($html, 'type="module" src="/admin/assets/app.js"'));
    self::assertFileExists(self::ADMIN . '/assets/router.js');
    self::assertFileExists(self::ADMIN . '/assets/app.js');
}
```

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageModuleContractTest.php
```

Expected: FAIL，`router.js`、`app.js` 和应用入口缺失。

- [ ] **Step 2: 实现片段加载与竞态保护**

`router.js` 的核心结构：

```javascript
import { assetUrl } from './version.js';

export function createRouter({ container, definitions, context, activateLegacy }) {
    let activeFeature = null;
    let activeController = null;
    let navigation = 0;

    async function navigate(requestedId) {
        const id = definitions.has(requestedId) ? requestedId : requestedId;
        const definition = definitions.get(id);
        if (!definition) return activateLegacy(id);

        const currentNavigation = ++navigation;
        activeController?.abort();
        activeController = new AbortController();
        activeFeature?.unmount();

        try {
            const [response, module] = await Promise.all([
                fetch(assetUrl(`/admin/views/${definition.view}`), {
                    signal: activeController.signal,
                }),
                import(assetUrl(`/admin/assets/features/${definition.module}`)),
            ]);
            if (!response.ok) throw new Error(`页面片段加载失败（${response.status}）`);
            const html = await response.text();
            if (currentNavigation !== navigation) return;

            container.innerHTML = html;
            activeFeature = module.feature;
            await activeFeature.mount({
                ...context,
                root: container,
                signal: activeController.signal,
                navigate,
            });
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            container.innerHTML = `
                <section class="p-6 text-center" data-feature-error="${id}">
                    <p class="text-rose-600 font-bold">页面加载失败，请稍后重试</p>
                    <button type="button" data-action="retry-fragment">重新加载</button>
                </section>`;
            container.querySelector('[data-action="retry-fragment"]')
                ?.addEventListener('click', () => navigate(id), { once: true });
        }
    }

    return { navigate };
}
```

- [ ] **Step 3: 实现混合迁移应用入口**

`app.js` 只静态导入重新验证的 `version.js`，再通过 `assetUrl()` 动态导入 `api.js`、`ui.js` 和 `router.js`，保证三个公共模块也携带当前版本。`activateLegacy(id)` 继续执行现有 `.tab-panel` 激活与旧加载函数；已注册到 `definitions` 的功能不再执行旧路径。

```javascript
const definitions = new Map();
const router = createRouter({
    container: document.getElementById('admin-feature-root'),
    definitions,
    context: { api, ui },
    activateLegacy,
});

window.CXAdmin = Object.freeze({ api, ui, navigate: router.navigate });
```

- [ ] **Step 4: 把导航改为统一入口**

`index.html` 增加 `<main id="admin-feature-root"></main>`，并把 `switchTab(tabId)` 缩减为：

```javascript
function switchTab(tabId) {
    return window.CXAdmin.navigate(tabId);
}
```

入口改为：

```html
<script type="module" src="/admin/assets/app.js"></script>
```

- [ ] **Step 5: 添加无数据库静态冒烟服务器**

`tests/Fixtures/admin_page_router.php`：

```php
<?php

declare(strict_types=1);

$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/api/admin/')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 0,
        'msg' => '浏览器冒烟测试模拟响应',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

$public = dirname(__DIR__, 2) . '/public';
$publicRoot = realpath($public);
$candidate = realpath($public . $path);
if (
    $publicRoot !== false
    && $candidate !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    return false;
}

http_response_code(404);
echo 'Not Found';
return true;
```

- [ ] **Step 6: 运行路由契约与语法测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageModuleContractTest.php
node --check public/admin/assets/router.js
node --check public/admin/assets/app.js
php -l tests/Fixtures/admin_page_router.php
```

Expected: PASS。

Commit:

```powershell
git add public/admin/index.html public/admin/assets tests/Fixtures tests/Unit/AdminPageModuleContractTest.php
git commit -m "refactor: add progressive admin feature router"
```

---

### Task 4: 迁移仪表盘、系统更新和云监控

**Files:**
- Create: `public/admin/views/dashboard.html`
- Create: `public/admin/views/system-update.html`
- Create: `public/admin/views/cloud-monitor.html`
- Create: `public/admin/assets/features/dashboard.js`
- Create: `public/admin/assets/features/system-update.js`
- Create: `public/admin/assets/features/cloud-monitor.js`
- Modify: `public/admin/assets/app.js`
- Modify: `public/admin/index.html`
- Modify: `tests/Unit/AdminPageModuleContractTest.php`

**Interfaces:**
- Feature IDs: `dashboard`、`system-update`、`cloud-monitor`。
- APIs remain: `/api/admin/dashboard`、`/api/admin/report/trend`、`/api/admin/system/*`、`/api/admin/cloud-monitor/status`。

- [ ] **Step 1: 增加前三个功能的失败契约**

向测试类增加 provider 和测试：

```php
#[DataProvider('firstFeatureProvider')]
public function testFirstFeatureHasViewAndModule(string $id): void
{
    self::assertFileExists(self::ADMIN . '/views/' . $id . '.html');
    self::assertFileExists(self::ADMIN . '/assets/features/' . $id . '.js');
}

public static function firstFeatureProvider(): iterable
{
    yield ['dashboard'];
    yield ['system-update'];
    yield ['cloud-monitor'];
}
```

Run and confirm FAIL because the six files do not exist.

- [ ] **Step 2: 迁移对应静态结构**

从 `index.html` 原样移动三个可见面板的内部结构到对应 view；每个片段使用唯一根节点：

```html
<section data-feature="dashboard" class="space-y-6">
    <!-- 原 tab-dashboard 内部结构，不复制 tab-panel 外壳 -->
</section>
```

所有 `onclick` 改为 `data-action`；功能模块在 `mount()` 中通过根节点事件代理处理动作。

- [ ] **Step 3: 迁移仪表盘逻辑并释放图表**

`dashboard.js` 移动 `loadDashboard`、`loadDashboardRecentOrders`、`initAdminTrendChart`。模块保存 ECharts 实例和 resize handler：

```javascript
let chart = null;
let resizeHandler = null;

export const feature = {
    id: 'dashboard',
    async mount({ root, api, ui, signal, navigate }) {
        // 绑定刷新和跳转动作，执行原 loadDashboard 数据映射。
        resizeHandler = () => chart?.resize();
        window.addEventListener('resize', resizeHandler, { signal });
        await loadDashboard({ root, api, ui });
    },
    unmount() {
        chart?.dispose();
        chart = null;
        resizeHandler = null;
    },
};
```

- [ ] **Step 4: 迁移系统更新和云监控逻辑**

系统更新模块只保留 `checkGitUpdate`、`executeGitUpdate`、`loadGitVersionHistory` 的有效语义。云监控模块移动 `loadCloudMonitorStatus`。两个模块都只在自己的 `root` 内查询 DOM。

- [ ] **Step 5: 注册功能并删除旧实现**

`app.js` 注册：

```javascript
definitions.set('dashboard', { view: 'dashboard.html', module: 'dashboard.js' });
definitions.set('system-update', { view: 'system-update.html', module: 'system-update.js' });
definitions.set('cloud-monitor', { view: 'cloud-monitor.html', module: 'cloud-monitor.js' });
```

从 `index.html` 删除对应面板和函数，不能保留第二份回退实现。

- [ ] **Step 6: 运行契约、语法与浏览器冒烟**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageModuleContractTest.php tests/Unit/AdminPageSourceIntegrityTest.php
Get-ChildItem public/admin/assets/features -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
php -S 127.0.0.1:18891 -t public tests/Fixtures/admin_page_router.php
```

使用 Playwright 工作流打开 `http://127.0.0.1:18891/admin/index.html`，依次点击仪表盘、系统更新、云监控，断言对应 `[data-feature]` 出现；快速连续切换三次，最终只显示最后选择的片段；结束后停止本地 PHP 进程。

Commit:

```powershell
git add public/admin tests/Unit/AdminPageModuleContractTest.php
git commit -m "refactor: modularize admin overview features"
```

---

### Task 5: 迁移支付通道和插件模块

**Files:**
- Create: `public/admin/views/channels.html`
- Create: `public/admin/views/plugins.html`
- Create: `public/admin/assets/features/channels.js`
- Create: `public/admin/assets/features/plugins.js`
- Modify: `public/admin/assets/app.js`
- Modify: `public/admin/index.html`
- Modify: `tests/Unit/AdminChannelFrontendContractTest.php`
- Modify: `tests/Unit/AdminChannelPageTest.php`
- Modify: `tests/Unit/AdminPageModuleContractTest.php`

**Interfaces:**
- Channel APIs: `/api/admin/channel/list`、`/api/admin/channel/get`、`/api/admin/channel/inputs`、`/api/admin/channel/config/save`。
- Plugin APIs: `/api/admin/plugin/market_list`、`install`、`set_enabled`、`rollback`、`uninstall`。

- [ ] **Step 1: 先把既有通道测试指向目标模块**

修改 `AdminChannelFrontendContractTest`，读取 `assets/features/channels.js` 和 `views/channels.html`，断言：

```php
self::assertStringContainsString('/api/admin/channel/config/save', $module);
self::assertStringContainsString('data-config-key', $module);
self::assertStringContainsString('id="channel-stat-driver-count"', $view);
self::assertStringContainsString('id="channel-config-editor"', $view);
self::assertStringNotContainsString('/api/admin/channel/save', $module);
```

Run and confirm FAIL，因为目标文件尚不存在。

- [ ] **Step 2: 迁移通道片段和唯一有效实现**

把通道统计、列表和配置弹窗移入 `channels.html`。`channels.js` 迁移：

- `loadAdminDriverCount`
- `loadAdminChannels`
- `toggleAdminChannel`
- `testAdminChannel`
- `openChannelConfigEditor`
- `closeChannelConfigEditor`
- `submitChannelConfig`

删除 `loadAdminChannelsLegacyCards`、`loadAdminChannelsLegacyReadOnly`、`editAdminChannel` 和所有第二套保存实现。动态按钮改为 `data-action` 与 `data-channel-id`，不得继续拼接 `onclick`。

- [ ] **Step 3: 迁移插件片段和动作**

`plugins.js` 只保留一个加载函数 `loadInstalledPlugins()`，并提供安装、启停、回滚、卸载动作。所有成功操作重新调用同一个加载函数。上传控件通过 `change` 事件代理触发，不暴露全局 `installPluginPackage`。

- [ ] **Step 4: 注册模块并从大页面删除旧代码**

```javascript
definitions.set('channel-config', { view: 'channels.html', module: 'channels.js' });
definitions.set('plugin-market', { view: 'plugins.html', module: 'plugins.js' });
```

- [ ] **Step 5: 运行通道契约、中间件测试和全量回归**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminChannelFrontendContractTest.php tests/Unit/AdminChannelPageTest.php tests/Unit/AdminChannelListContractMiddlewareTest.php tests/Unit/AdminPageModuleContractTest.php
node --check public/admin/assets/features/channels.js
node --check public/admin/assets/features/plugins.js
php vendor/bin/phpunit --display-warnings
```

Expected: PASS，根测试数量不低于当前数量。

Commit:

```powershell
git add public/admin tests/Unit
git commit -m "refactor: modularize admin channels and plugins"
```

---

### Task 6: 迁移商户与套餐管理模块

**Files:**
- Create: `public/admin/views/merchants.html`
- Create: `public/admin/views/plans.html`
- Create: `public/admin/assets/features/merchants.js`
- Create: `public/admin/assets/features/plans.js`
- Modify: `public/admin/assets/app.js`
- Modify: `public/admin/index.html`
- Modify: `tests/Unit/AdminPageModuleContractTest.php`

**Interfaces:**
- Merchant APIs: `/api/admin/merchant/list`、`/api/admin/merchant/save`、`/api/admin/template/save`。
- Plan APIs remain the current `/api/admin/packvip/*` endpoints used by the page.

- [ ] **Step 1: 增加目标文件和 API 路径失败测试**

测试 provider 加入 `merchants`、`plans`，并断言模块包含各自 API 前缀、片段包含原表格和弹窗关键 ID。Run and confirm FAIL。

- [ ] **Step 2: 迁移商户模块**

移动 `merchantRecords`、`loadMerchants`、`openMerchantEditor`、`closeMerchantEditor`、`submitMerchant` 和模板保存动作。缓存 Map 只能存在于模块内部，并在 `unmount()` 时 `clear()`。

- [ ] **Step 3: 迁移套餐模块**

移动 `planCacheMap`、`loadPlans`、套餐编辑、提交和删除动作。确认框统一调用 `ui.showConfirm()`，不保留新的原生 `confirm()` 分支。

- [ ] **Step 4: 注册模块、删除旧面板和运行测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageModuleContractTest.php tests/Unit/AdminPageSourceIntegrityTest.php
node --check public/admin/assets/features/merchants.js
node --check public/admin/assets/features/plans.js
php vendor/bin/phpunit --display-warnings
```

Commit:

```powershell
git add public/admin tests/Unit/AdminPageModuleContractTest.php
git commit -m "refactor: modularize admin merchant management"
```

---

### Task 7: 迁移订单、账单复核和告警模块

**Files:**
- Create: `public/admin/views/orders.html`
- Create: `public/admin/views/callbill.html`
- Create: `public/admin/views/alerts.html`
- Create: `public/admin/assets/features/orders.js`
- Create: `public/admin/assets/features/callbill.js`
- Create: `public/admin/assets/features/alerts.js`
- Modify: `public/admin/assets/app.js`
- Modify: `public/admin/index.html`
- Modify: `tests/Unit/AdminPageModuleContractTest.php`

**Interfaces:**
- Order APIs: `/api/admin/order/list`、`force_notify`、`manual_pay`、`close`。
- Callbill APIs: `/api/admin/callbill/review_list`、`review_match`、`review_ignore`。
- Alert/system config APIs: `/api/admin/alert/*`、`/api/admin/system/config*`。

- [ ] **Step 1: 增加三个模块失败契约**

Provider 加入 `orders`、`callbill`、`alerts`，断言 view/module 成对存在、模块使用既有 API。Run and confirm FAIL。

- [ ] **Step 2: 迁移订单和账单复核**

把 `loadOrders`、`manualPay`、`closeOrder`、`loadCallbillReviews`、`reviewMatchBill`、`ignoreReviewBill` 移入对应模块。动态表格按钮改用 `data-action`，交易号只放入 `dataset`，继续通过 `escapeHtml` 输出文本。

- [ ] **Step 3: 迁移告警与运营配置**

把 `loadAdminAlertConfig`、`applySmtpPreset`、`resetAlertTemplatesToDefault`、`loadSystemOpConfig`、`saveSystemOpConfig`、`saveAdminAlertConfig`、`testAlertChannel` 移入 `alerts.js`。自定义模板状态只存在模块内，卸载时清空。

- [ ] **Step 4: 注册功能并删除对应旧代码**

```javascript
definitions.set('order-list', { view: 'orders.html', module: 'orders.js' });
definitions.set('callbill-review', { view: 'callbill.html', module: 'callbill.js' });
definitions.set('alert-config', { view: 'alerts.html', module: 'alerts.js' });
```

- [ ] **Step 5: 执行语法和全量回归**

Run:

```powershell
Get-ChildItem public/admin/assets -Recurse -File -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
php vendor/bin/phpunit --display-warnings
git diff --check
```

Commit:

```powershell
git add public/admin tests/Unit/AdminPageModuleContractTest.php
git commit -m "refactor: modularize admin transaction features"
```

---

### Task 8: 收紧页面壳并完成浏览器验收

**Files:**
- Modify: `public/admin/index.html`
- Modify: `public/admin/assets/app.js`
- Modify: `public/admin/assets/router.js`
- Modify: `public/admin/assets/version.js`
- Modify: `tests/Unit/AdminPageModuleContractTest.php`
- Modify: `docs/superpowers/specs/2026-08-09-large-file-modularization-design.md`

**Interfaces:**
- Freezes: 十个功能 ID、唯一应用入口、统一资源版本和无旧内联业务实现的最终结构。

- [ ] **Step 1: 增加最终结构失败测试**

```php
public function testFinalAdminShellIsSmallAndContainsNoInlineBusinessScript(): void
{
    $html = file_get_contents(self::ADMIN . '/index.html');
    self::assertIsString($html);
    self::assertLessThanOrEqual(500, substr_count($html, "\n") + 1);
    self::assertDoesNotMatchRegularExpression(
        '/<script(?![^>]*\bsrc=)[^>]*>\s*(?:async\s+)?function\s+/si',
        $html
    );
}

public function testAllAdminFeaturesStayWithinSizeLimit(): void
{
    foreach (glob(self::ADMIN . '/{assets/features,views}/*', GLOB_BRACE) ?: [] as $path) {
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1, $path);
    }
}
```

Run and confirm FAIL until shell and all modules meet limits.

- [ ] **Step 2: 删除混合迁移回退**

所有十个标签已注册后，从 `router.js` 删除 `activateLegacy`；从页面删除旧 `.tab-panel`、旧 `switchTab` 和全部内联业务函数。导航改为 `data-tab`，`app.js` 在根导航容器统一代理点击。

- [ ] **Step 3: 固定资源版本和错误重试**

更新：

```javascript
export const ASSET_VERSION = 'admin-modules-v2';
```

验证所有片段和动态模块 URL 经过 `assetUrl()`。手动使一个片段返回 404，重试按钮必须再次调用当前 `navigate(id)`。

- [ ] **Step 4: 启动冒烟服务器并完成浏览器流程**

Run:

```powershell
php -S 127.0.0.1:18891 -t public tests/Fixtures/admin_page_router.php
```

使用 Playwright 工作流执行：

1. 打开 `/admin/index.html#dashboard`，确认仪表盘片段出现；
2. 依次点击十个导航项，每次确认唯一对应 `[data-feature]`；
3. 连续快速点击通道、插件、订单，确认最终只显示订单；
4. 检查浏览器 console，不得出现 SyntaxError、重复 ID、未定义函数或未处理 Promise；
5. 模拟片段 404，确认错误卡片和重试按钮可见；
6. 停止本地 PHP 服务。

- [ ] **Step 5: 运行最终自动门禁**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/AdminPageSourceIntegrityTest.php tests/Unit/AdminPageModuleContractTest.php tests/Unit/AdminChannelFrontendContractTest.php tests/Unit/AdminChannelPageTest.php
Get-ChildItem public/admin/assets -Recurse -File -Filter '*.js' | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE -ne 0) { exit 1 } }
$phpFiles = Get-ChildItem app,config,support,tests -Recurse -File -Filter '*.php'
foreach ($file in $phpFiles) { php -l $file.FullName | Out-Null; if ($LASTEXITCODE -ne 0) { exit 1 } }
php vendor/bin/phpunit --display-warnings
git diff --check
git status --short
```

Expected: 管理页面壳不超过 500 行；模块和片段不超过 400 行；测试总数不少于 Task 1 后的数量；只有用户原有 `CXPAY.rar` 保持未跟踪。

- [ ] **Step 6: 更新规格验收状态并提交**

在设计规格的实施记录中写明管理后台已完成，商户中心仍按下一份计划实施。

Commit:

```powershell
git add public/admin tests docs/superpowers/specs/2026-08-09-large-file-modularization-design.md
git commit -m "test: verify modular admin page lifecycle"
```

## Completion Gate

只有同时满足以下条件才开始 `merchant_center.html` 的详细实施计划：

- `public/admin/index.html` 不超过 500 行；
- 十个功能均已迁移为 view/module 对，且单文件不超过 400 行；
- 正式管理页不存在冲突标记、重复静态 ID、重复函数或大段内联业务脚本；
- 通道、插件、订单、商户、套餐、告警和系统更新 API 路径未改变；
- Node 语法、PHP 语法、前端契约、浏览器冒烟和根 PHPUnit 全量测试通过；
- 每个任务有独立提交，工作区除 `CXPAY.rar` 外干净。
