# AdminController 模块化拆分 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不改变管理员 API 契约的前提下删除 959 行的 `AdminController`，将有效接口迁移到单一业务资源 Controller。

**Architecture:** 先用方法源码哈希冻结当前有效实现，再按认证、仪表盘、平台通道、商户、安全与模板、订单六个边界逐组迁移路由。迁移阶段保留旧类作为尚未迁移方法的来源，最后一个任务一次性删除旧类及无路由的旧 Git 更新实现，不保留门面或 Trait。

**Tech Stack:** PHP 8.1、Webman 2.1、Illuminate Database 10、PHPUnit 10、PowerShell、Git。

## Global Constraints

- 保持全部现有 URL、HTTP 方法、中间件、请求字段、Session 行为和 JSON 响应语义不变。
- 不修改管理员登录协议、Token 格式、二次验证码规则或登录限频策略。
- 不改变平台通道校验、敏感配置掩码、加密格式或驱动调用方式。
- 不改变商户开户、密码哈希、API 密钥、费率和 IP 白名单规则。
- 不重构 `OrderService`、`MerchantApiController`、安装流程或云监控服务。
- 不保留新的 `AdminController` 兼容门面，也不使用 Trait 隐藏原有大类。
- 本阶段新增或扩展的生产 PHP 文件原则上不超过 300 行。
- PHPUnit 测试数量不得低于拆分前基线 321 个，且必须 0 failure、0 error。
- 不修改、不提交用户未跟踪文件 `CXPAY.rar`。
- 实施必须位于新的隔离工作树和 `codex/` 前缀分支；先提交分支，再合并到 `main`。

---

## File Structure

### 新增生产文件

- `app/controller/admin/AdminAuthController.php`：管理员登录、二次验证、Token 签发和退出。
- `app/controller/admin/AdminDashboardController.php`：统计缓存、系统指标和仪表盘降级。
- `app/controller/admin/AdminChannelConfigController.php`：平台通道实例的列表、读取和保存。
- `app/controller/admin/AdminMerchantController.php`：商户查询、开户和资料更新。
- `app/controller/admin/AdminSecurityController.php`：二次验证码和管理员密码设置。
- `app/controller/admin/MerchantTemplateController.php`：官网主页模板选择。

### 修改生产文件

- `app/controller/admin/OrderAdminController.php`：增加统一结算链路的人工补单入口。
- `config/route.php`：将 14 条既有 URL 映射到资源 Controller。

### 删除生产文件

- `app/controller/admin/AdminController.php`：所有有效方法迁移后删除；其中无路由的 `getSystemUpdateStatus()` 和 `executeSystemUpdate()` 不迁移。

### 新增测试文件

- `tests/Unit/AdminControllerBehaviorContractTest.php`：用标准化方法源码 SHA-256 冻结 16 个有效方法，确保迁移只改变所属类。
- `tests/Unit/AdminControllerRouteContractTest.php`：冻结公开路由、管理员认证组路由、方法所有权、旧类删除和文件规模。

### 更新文档

- `docs/superpowers/specs/2026-08-09-admin-controller-modularization-design.md`：记录实际文件规模、测试数和提交结果。

---

### Task 1: 冻结方法行为并迁移管理员认证

**Files:**
- Create: `tests/Unit/AdminControllerBehaviorContractTest.php`
- Create: `tests/Unit/AdminControllerRouteContractTest.php`
- Create: `app/controller/admin/AdminAuthController.php`
- Modify: `config/route.php:101-103`

**Interfaces:**
- Consumes: `support\Request`、`support\Authcode`、`support\LoginRateLimiter`、`app\service\AlertNotificationService` 和现有 Session API。
- Produces: `AdminAuthController::login(Request): string`、`verifyLoginCode(Request): string`、`logout(Request): string`；私有 `issueAdminToken(Request, string): string`。

- [ ] **Step 1: 写入方法源码冻结测试**

创建 `tests/Unit/AdminControllerBehaviorContractTest.php`。候选类顺序固定为“目标类优先、旧类兜底”，因此每组方法一旦迁移，测试会立即校验新文件，而不会被旧实现掩盖：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AdminControllerBehaviorContractTest extends TestCase
{
    private const CONTRACTS = [
        'login' => ['app\\controller\\admin\\AdminAuthController', 'c9683d20f46dd27caf6f879eff73319541a3cdba458fcae6ee60247151c8b51f'],
        'verifyLoginCode' => ['app\\controller\\admin\\AdminAuthController', '5269ccdf9bcd0278f4896597a0f7d531df5b0ce5ecb7086c7838fe0197b34a42'],
        'issueAdminToken' => ['app\\controller\\admin\\AdminAuthController', '90e5d83e7b6165342bacee51341095e71da2cf270bdea4179f377c7ec9ee092e'],
        'logout' => ['app\\controller\\admin\\AdminAuthController', 'eff041e8bfd17a0247f63abcacf8cdc3b6312ee010b1feb6a4da01944bdb5a38'],
        'dashboard' => ['app\\controller\\admin\\AdminDashboardController', 'ee4ff2ef10877fca15446f1f51b7432771e96d8aa9cf895730d95a50ff638236'],
        'getDashboardStats' => ['app\\controller\\admin\\AdminDashboardController', 'd13a143de2e93f39c5e93e8777728e0d626696c1051e407a4a14867cf3711164'],
        'getChannelConfig' => ['app\\controller\\admin\\AdminChannelConfigController', 'ccb5e183c59d33d462e1b598d42e94589f976921070d9f6bdb6a9e1c5e782024'],
        'listChannels' => ['app\\controller\\admin\\AdminChannelConfigController', '35a7a9eb3931e08e3e877cd0bce0d29a4a548d036bfde9575b0ae2371b86c562'],
        'saveChannelConfig' => ['app\\controller\\admin\\AdminChannelConfigController', '9df9fd12b1b39d88575ce964898d44442e61e4fa97be5ca0975499be9cc76f2b'],
        'isSensitiveConfigName' => ['app\\controller\\admin\\AdminChannelConfigController', '36898c06146a10cb2e1b0f13f6ccfaa47bfd8741294191345d372ff4a15a4dc8'],
        'listMerchants' => ['app\\controller\\admin\\AdminMerchantController', 'dce010fe66551412253c22dc33c8265d3b2c62cf05456ab8780e4c408a0a1c12'],
        'saveMerchant' => ['app\\controller\\admin\\AdminMerchantController', '0e56fb6fa890c0a26b58f7ddd941bc30148ef1d24a97c0ee765f9c91da90dafe'],
        'getSecurityConfig' => ['app\\controller\\admin\\AdminSecurityController', 'c16ded961f21bc8ed26206201dd5a9120dac36763c01cf412c03c3829e31bde0'],
        'saveSecurityConfig' => ['app\\controller\\admin\\AdminSecurityController', 'a4036ae3cccd5e1b5664d268362738179d2c38c840ec29629cf06e417195ae25'],
        'saveTemplate' => ['app\\controller\\admin\\MerchantTemplateController', '88f11895f2418adaf5cbc07222a6e5a81d7f7e17f925bb69dcaa58a5dd2a5b4a'],
        'forceNotifyOrder' => ['app\\controller\\admin\\OrderAdminController', '005dacd74288906a81e9f870dfb8fc1498261bd6dc8f79fa7ef41d4c600b40ef'],
    ];

    public function testMigratedMethodsRetainFrozenSourceSemantics(): void
    {
        foreach (self::CONTRACTS as $method => [$targetClass, $expectedHash]) {
            $reflection = $this->findMethod($targetClass, $method);
            self::assertSame(
                $expectedHash,
                hash('sha256', $this->normalizedSource($reflection)),
                "{$targetClass}::{$method} 的实现发生了非等价修改"
            );
        }
    }

    private function findMethod(string $targetClass, string $method): ReflectionMethod
    {
        foreach ([$targetClass, 'app\\controller\\admin\\AdminController'] as $candidate) {
            if (!class_exists($candidate)) {
                continue;
            }
            $class = new ReflectionClass($candidate);
            if (!$class->hasMethod($method)) {
                continue;
            }
            $reflection = $class->getMethod($method);
            if ($reflection->getDeclaringClass()->getName() === $candidate) {
                return $reflection;
            }
        }

        self::fail("找不到冻结方法 {$method}");
    }

    private function normalizedSource(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        return str_replace(["\r\n", "\r"], "\n", $source);
    }
}
```

- [ ] **Step 2: 运行源码冻结测试并确认旧实现基线通过**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php`

Expected: PASS，1 test，16 组方法哈希全部匹配当前 `AdminController`。

- [ ] **Step 3: 写入认证路由的失败测试**

创建 `tests/Unit/AdminControllerRouteContractTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminControllerRouteContractTest extends TestCase
{
    private string $routes;

    protected function setUp(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/config/route.php');
        self::assertIsString($routes);
        $this->routes = $routes;
    }

    public function testPublicAuthenticationRoutesUseDedicatedController(): void
    {
        $expected = [
            "Route::post('/api/admin/login',        [app\\controller\\admin\\AdminAuthController::class, 'login']);",
            "Route::post('/api/admin/login/verify', [app\\controller\\admin\\AdminAuthController::class, 'verifyLoginCode']);",
            "Route::post('/api/admin/logout',       [app\\controller\\admin\\AdminAuthController::class, 'logout']);",
        ];

        foreach ($expected as $route) {
            self::assertStringContainsString($route, $this->routes);
        }
    }

    private function adminGroup(): string
    {
        $start = strpos($this->routes, "Route::group('/api/admin'");
        $endMarker = '})->middleware([app\\middleware\\AdminAuthMiddleware::class]);';
        $end = strpos($this->routes, $endMarker, $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($this->routes, $start, $end - $start + strlen($endMarker));
    }
}
```

- [ ] **Step 4: 运行认证路由测试并确认按预期失败**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php --filter PublicAuthenticationRoutes`

Expected: FAIL，路由仍指向 `AdminController`。

- [ ] **Step 5: 创建认证 Controller 并迁移冻结方法**

创建文件头和唯一依赖：

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\AlertNotificationService;
use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;
use support\LoginRateLimiter;

final class AdminAuthController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }
}
```

从 `AdminController.php` 原样剪切以下完整方法，不调整空白、变量、异常捕获或返回值：

- `login()`：原 45-117 行；
- `verifyLoginCode()`：原 124-190 行；
- `issueAdminToken()`：原 196-236 行；
- `logout()`：原 242-248 行。

将它们插入 `AdminAuthController` 构造器后。方法源码哈希必须继续等于 Step 1 中的四个固定值。

- [ ] **Step 6: 只替换三条公开认证路由的类名**

```php
Route::post('/api/admin/login',        [app\controller\admin\AdminAuthController::class, 'login']);
Route::post('/api/admin/login/verify', [app\controller\admin\AdminAuthController::class, 'verifyLoginCode']);
Route::post('/api/admin/logout',       [app\controller\admin\AdminAuthController::class, 'logout']);
```

- [ ] **Step 7: 验证认证迁移**

Run: `php -l app/controller/admin/AdminAuthController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php`

Expected: PASS；认证方法由目标类提供且源码哈希不变。

- [ ] **Step 8: 提交认证边界**

```powershell
git add -- tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php app/controller/admin/AdminAuthController.php config/route.php
git commit -m "refactor: extract admin authentication controller"
```

### Task 2: 迁移仪表盘统计与降级

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Create: `app/controller/admin/AdminDashboardController.php`
- Modify: `config/route.php:141`

**Interfaces:**
- Consumes: `MonitorService::getMetrics()`、Redis `cx:dashboard_stats`、`cx_order`、`cx_merchant`、`cx_channel`。
- Produces: `AdminDashboardController::dashboard(Request): string`；私有 `getDashboardStats(): array`。

- [ ] **Step 1: 增加受保护仪表盘路由测试**

在路由测试类增加：

```php
public function testDashboardRouteUsesDedicatedControllerInsideAdminGroup(): void
{
    self::assertStringContainsString(
        "Route::any('/dashboard', [app\\controller\\admin\\AdminDashboardController::class, 'dashboard']);",
        $this->adminGroup()
    );
}
```

- [ ] **Step 2: 运行测试并确认失败**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php --filter DashboardRoute`

Expected: FAIL，仪表盘仍由旧类提供。

- [ ] **Step 3: 创建仪表盘 Controller**

文件头固定为：

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\MonitorService;
use Illuminate\Database\Capsule\Manager as DB;

final class AdminDashboardController
{
    protected MonitorService $monitorService;

    public function __construct()
    {
        $this->monitorService = new MonitorService();
    }
}
```

从旧类原样剪切 `dashboard()`（原 253-285 行）和 `getDashboardStats()`（原 291-359 行），插入构造器后。不得改变 Redis TTL、聚合 SQL、成功率公式、监控降级或外层零值响应。

- [ ] **Step 4: 替换并验证仪表盘路由**

```php
Route::any('/dashboard', [app\controller\admin\AdminDashboardController::class, 'dashboard']);
```

Run: `php -l app/controller/admin/AdminDashboardController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php`

Expected: PASS。

- [ ] **Step 5: 提交仪表盘边界**

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php app/controller/admin/AdminDashboardController.php config/route.php
git commit -m "refactor: extract admin dashboard controller"
```

### Task 3: 迁移平台通道配置

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Create: `app/controller/admin/AdminChannelConfigController.php`
- Modify: `config/route.php:142-146`

**Interfaces:**
- Consumes: `Channel`、`PaymentManager`、`RemovedPaymentDrivers`、`Authcode` 和支付驱动 `getMeta()/upchannel()`。
- Produces: `getChannelConfig(Request): string`、`listChannels(): string`、`saveChannelConfig(Request): string`；私有 `isSensitiveConfigName(string): bool`。

- [ ] **Step 1: 增加四条通道实例路由测试**

```php
public function testPlatformChannelRoutesUseConfigControllerInsideAdminGroup(): void
{
    $group = $this->adminGroup();
    $expected = [
        "Route::get('/channel/list', [app\\controller\\admin\\AdminChannelConfigController::class, 'listChannels']);",
        "Route::post('/channel/save', [app\\controller\\admin\\AdminChannelConfigController::class, 'saveChannelConfig']);",
        "Route::get('/channel/get', [app\\controller\\admin\\AdminChannelConfigController::class, 'getChannelConfig']);",
        "Route::post('/channel/config/save', [app\\controller\\admin\\AdminChannelConfigController::class, 'saveChannelConfig']);",
        "Route::get('/channel/inputs', [app\\controller\\admin\\ChannelAdminController::class, 'getConfigInputs']);",
    ];

    foreach ($expected as $route) {
        self::assertStringContainsString($route, $group);
    }
}
```

- [ ] **Step 2: 运行测试并确认失败**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php --filter PlatformChannelRoutes`

Expected: FAIL，四条实例配置路由仍指向旧类；`/channel/inputs` 断言继续通过。

- [ ] **Step 3: 创建平台通道配置 Controller**

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\payment\PaymentManager;
use app\payment\RemovedPaymentDrivers;
use support\Authcode;

final class AdminChannelConfigController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }
}
```

原样迁移：

- `getChannelConfig()`：原 397-435 行；
- `listChannels()`：原 441-488 行；
- `saveChannelConfig()`：原 494-650 行；
- `isSensitiveConfigName()`：原 896-899 行。

不得合并两个保存路由，不得修改敏感字段空值语义、备用通道校验、驱动白名单或 Authcode 加密顺序。

- [ ] **Step 4: 替换四条实例配置路由并验证**

Run: `php -l app/controller/admin/AdminChannelConfigController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminChannelFrontendContractTest.php`

Run: `(Get-Content -LiteralPath 'app/controller/admin/AdminChannelConfigController.php' -ReadCount 0).Count`

Expected: 全部 PASS；通道 Controller 不超过 300 行。

- [ ] **Step 5: 提交通道边界**

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php app/controller/admin/AdminChannelConfigController.php config/route.php
git commit -m "refactor: extract admin channel config controller"
```

### Task 4: 迁移商户管理

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Create: `app/controller/admin/AdminMerchantController.php`
- Modify: `config/route.php:149-150`

**Interfaces:**
- Consumes: `Merchant` 和 `IpWhitelist::normalize()`。
- Produces: `listMerchants(Request): string`、`saveMerchant(Request): string`。

- [ ] **Step 1: 增加商户路由失败测试**

```php
public function testMerchantRoutesUseDedicatedControllerInsideAdminGroup(): void
{
    $group = $this->adminGroup();
    self::assertStringContainsString(
        "Route::get('/merchant/list', [app\\controller\\admin\\AdminMerchantController::class, 'listMerchants']);",
        $group
    );
    self::assertStringContainsString(
        "Route::post('/merchant/save', [app\\controller\\admin\\AdminMerchantController::class, 'saveMerchant']);",
        $group
    );
}
```

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php --filter MerchantRoutes`

Expected: FAIL。

- [ ] **Step 2: 创建商户 Controller 并原样迁移方法**

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Merchant;
use support\IpWhitelist;

final class AdminMerchantController
{
}
```

原样迁移 `listMerchants()`（原 368-391 行）和 `saveMerchant()`（原 656-727 行）。不得下发 `key`、`password_hash`，不得改变 PID、密钥、初始密码和 bcrypt cost 12 的生成规则。

- [ ] **Step 3: 替换路由、验证并提交**

Run: `php -l app/controller/admin/AdminMerchantController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php`

Expected: PASS。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php app/controller/admin/AdminMerchantController.php config/route.php
git commit -m "refactor: extract admin merchant controller"
```

### Task 5: 迁移安全设置与主页模板

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Create: `app/controller/admin/AdminSecurityController.php`
- Create: `app/controller/admin/MerchantTemplateController.php`
- Modify: `config/route.php:152,207-208`

**Interfaces:**
- Consumes: `Authcode`、`DB`、管理员 Session 和 `base_path()`。
- Produces: `getSecurityConfig(Request): string`、`saveSecurityConfig(Request): string`、`saveTemplate(Request): string`。

- [ ] **Step 1: 增加安全与模板路由失败测试**

```php
public function testSecurityAndTemplateRoutesUseDedicatedControllers(): void
{
    $group = $this->adminGroup();
    $expected = [
        "Route::post('/template/save', [app\\controller\\admin\\MerchantTemplateController::class, 'saveTemplate']);",
        "Route::get('/security/config',       [app\\controller\\admin\\AdminSecurityController::class, 'getSecurityConfig']);",
        "Route::post('/security/config/save', [app\\controller\\admin\\AdminSecurityController::class, 'saveSecurityConfig']);",
    ];

    foreach ($expected as $route) {
        self::assertStringContainsString($route, $group);
    }
}
```

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php --filter SecurityAndTemplateRoutes`

Expected: FAIL。

- [ ] **Step 2: 创建安全设置 Controller**

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;

final class AdminSecurityController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }
}
```

原样迁移 `getSecurityConfig()`（原 803-826 行）和 `saveSecurityConfig()`（原 834-891 行）。当前密码校验、Token 版本递增和 Session 清除顺序不得改变。

- [ ] **Step 3: 创建主页模板 Controller**

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;

final class MerchantTemplateController
{
}
```

原样迁移 `saveTemplate()`（原 779-797 行），继续同时验证模板名称正则和实际文件存在。

- [ ] **Step 4: 替换三条路由并验证**

Run: `php -l app/controller/admin/AdminSecurityController.php`

Run: `php -l app/controller/admin/MerchantTemplateController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php`

Expected: PASS。

- [ ] **Step 5: 提交安全与模板边界**

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php app/controller/admin/AdminSecurityController.php app/controller/admin/MerchantTemplateController.php config/route.php
git commit -m "refactor: extract admin security and template controllers"
```

### Task 6: 合并人工补单并删除旧控制器

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Modify: `app/controller/admin/OrderAdminController.php`
- Modify: `config/route.php:151`
- Delete: `app/controller/admin/AdminController.php`

**Interfaces:**
- Consumes: `OrderService::markAsPaid()`、`resendNotify()` 和 `AuditLog`。
- Produces: `OrderAdminController::forceNotifyOrder(Request): string`；删除全部 `AdminController` 类引用。

- [ ] **Step 1: 增加人工补单和旧类退休失败测试**

```php
public function testForceNotifyUsesExistingOrderController(): void
{
    self::assertStringContainsString(
        "Route::post('/order/force_notify', [app\\controller\\admin\\OrderAdminController::class, 'forceNotifyOrder']);",
        $this->adminGroup()
    );
}

public function testLegacyAdminControllerIsRetired(): void
{
    $root = dirname(__DIR__, 2);
    self::assertFileDoesNotExist($root . '/app/controller/admin/AdminController.php');
    self::assertStringNotContainsString(
        'app\\controller\\admin\\AdminController::class',
        $this->routes
    );
    self::assertStringNotContainsString('getSystemUpdateStatus', $this->productionAdminSources());
    self::assertStringNotContainsString('executeSystemUpdate', $this->productionAdminSources());
}

public function testTargetControllersStayWithinSizeLimit(): void
{
    $root = dirname(__DIR__, 2);
    $files = [
        'AdminAuthController.php',
        'AdminDashboardController.php',
        'AdminChannelConfigController.php',
        'AdminMerchantController.php',
        'AdminSecurityController.php',
        'MerchantTemplateController.php',
        'OrderAdminController.php',
    ];

    foreach ($files as $file) {
        $lines = file($root . '/app/controller/admin/' . $file);
        self::assertIsArray($lines);
        self::assertLessThanOrEqual(300, count($lines), "{$file} 超过 300 行");
    }
}

private function productionAdminSources(): string
{
    $directory = dirname(__DIR__, 2) . '/app/controller/admin';
    $sources = '';
    foreach (glob($directory . '/*.php') ?: [] as $file) {
        $source = file_get_contents($file);
        self::assertIsString($source);
        $sources .= $source;
    }

    return $sources;
}
```

- [ ] **Step 2: 运行退休测试并确认失败**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php --filter 'ForceNotify|LegacyAdminController|SizeLimit'`

Expected: FAIL，补单仍属于旧类且旧文件仍存在。

- [ ] **Step 3: 将人工补单原样迁入现有订单 Controller**

在 `OrderAdminController.php` 增加：

```php
use support\AuditLog;
```

把旧类的 `forceNotifyOrder()`（原 734-772 行）完整复制到 `close()` 后，不修改审计动作名、外部订单号前缀、结算参数或响应。

- [ ] **Step 4: 替换补单路由并删除旧类**

```php
Route::post('/order/force_notify', [app\controller\admin\OrderAdminController::class, 'forceNotifyOrder']);
```

确认 `config/route.php` 已没有 `AdminController::class` 后，删除 `app/controller/admin/AdminController.php`。其中 `getSystemUpdateStatus()` 和 `executeSystemUpdate()` 没有路由且已有独立 `SystemUpdateController`，不得迁移到其他类。

- [ ] **Step 5: 验证全部迁移方法、路由和文件规模**

Run: `php -l app/controller/admin/OrderAdminController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php`

Expected: PASS；16 个有效方法全部从目标类读取，旧类和旧更新方法不存在。

- [ ] **Step 6: 提交旧类退休**

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php app/controller/admin/OrderAdminController.php config/route.php
git add -u -- app/controller/admin/AdminController.php
git commit -m "refactor: retire monolithic admin controller"
```

### Task 7: 全量验证与实施记录

**Files:**
- Modify: `docs/superpowers/specs/2026-08-09-admin-controller-modularization-design.md`

**Interfaces:**
- Consumes: 前六个任务的全部生产代码和测试提交。
- Produces: 可审计的最终文件规模、测试数量和合并前验证记录。

- [ ] **Step 1: 对所有目标 PHP 文件执行语法检查**

Run:

```powershell
$files = @(
  'app/controller/admin/AdminAuthController.php',
  'app/controller/admin/AdminDashboardController.php',
  'app/controller/admin/AdminChannelConfigController.php',
  'app/controller/admin/AdminMerchantController.php',
  'app/controller/admin/AdminSecurityController.php',
  'app/controller/admin/MerchantTemplateController.php',
  'app/controller/admin/OrderAdminController.php',
  'config/route.php',
  'tests/Unit/AdminControllerBehaviorContractTest.php',
  'tests/Unit/AdminControllerRouteContractTest.php'
)
foreach ($file in $files) {
  php -l $file
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
```

Expected: 每个文件均输出 `No syntax errors detected`。

- [ ] **Step 2: 运行相关契约测试**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerBehaviorContractTest.php tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminChannelFrontendContractTest.php tests/Unit/AdminPageModuleContractTest.php`

Expected: PASS，0 failure，0 error。

- [ ] **Step 3: 运行根项目全量测试**

Run: `php vendor/bin/phpunit`

Expected: 不少于 321 tests，0 failure，0 error。

- [ ] **Step 4: 检查文件规模、旧引用和差异质量**

Run:

```powershell
Get-ChildItem -LiteralPath 'app/controller/admin' -File -Filter '*.php' |
  Where-Object { $_.Name -in @(
    'AdminAuthController.php',
    'AdminDashboardController.php',
    'AdminChannelConfigController.php',
    'AdminMerchantController.php',
    'AdminSecurityController.php',
    'MerchantTemplateController.php',
    'OrderAdminController.php'
  ) } |
  ForEach-Object {
    [PSCustomObject]@{ File = $_.Name; Lines = (Get-Content -LiteralPath $_.FullName -ReadCount 0).Count }
  } |
  Sort-Object Lines -Descending
git grep -n -F 'app\controller\admin\AdminController::class' -- config app tests
git diff --check
git status --short
```

Expected: 每个目标文件不超过 300 行；`git grep` 无匹配；`git diff --check` 无输出；状态中不包含 `CXPAY.rar` 的变更或暂存记录。

- [ ] **Step 5: 更新设计文档实施记录**

在设计文档末尾新增“实施记录”，写入实际七个文件行数、根 PHPUnit 测试数、断言数、语法检查和旧引用扫描结果。所有数字必须来自 Step 1-4 的真实输出。

- [ ] **Step 6: 提交最终验证记录**

```powershell
git add -- docs/superpowers/specs/2026-08-09-admin-controller-modularization-design.md
git diff --cached --check
git commit -m "test: verify modular admin controllers"
```

- [ ] **Step 7: 按分支完成规范执行集成**

使用 `superpowers:verification-before-completion` 复核证据，再使用 `superpowers:finishing-a-development-branch` 选择本地合并。按用户既定要求先确保功能分支全部提交，再快进合并到 `main`；合并后在 `main` 重跑 `php vendor/bin/phpunit`、`git diff --check` 和 `git status --short`，最后只移除本轮新建的工作树和临时分支。
