# AdminController 模块化拆分 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在不改变管理员 API 契约的前提下删除 959 行的 `AdminController`，将有效接口迁移到单一业务资源 Controller。

**Architecture:** 通过 Webman 的真实路由注册表冻结 URL、HTTP 方法、回调和中间件，通过真实 `Request` 与内存 SQLite 冻结关键响应，再按认证、仪表盘、平台通道、商户、安全与模板、订单六个边界逐组迁移。最后删除旧类及无路由的旧 Git 更新实现，不保留门面或 Trait。

**Tech Stack:** PHP 8.1、Webman 2.1、Illuminate Database 10、SQLite、PHPUnit 10、PowerShell、Git。

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

- `tests/Unit/AdminControllerRouteContractTest.php`：加载 Webman 真实路由表，验证路径、HTTP 方法、回调和中间件。
- `tests/Unit/AdminControllerApiContractTest.php`：以真实 HTTP 请求和内存数据库验证关键错误及降级响应。

### 更新文档

- `docs/superpowers/specs/2026-08-09-admin-controller-modularization-design.md`：记录实际文件规模、测试数和提交结果。

---

### Task 1: 建立真实路由测试并迁移管理员认证

**Files:**
- Create: `tests/Unit/AdminControllerRouteContractTest.php`
- Create: `tests/Unit/AdminControllerApiContractTest.php`
- Create: `app/controller/admin/AdminAuthController.php`
- Modify: `config/route.php:101-103`

**Interfaces:**
- Consumes: Webman 路由注册表、`support\Request`、`Authcode`、`LoginRateLimiter`、`AlertNotificationService` 和 Session API。
- Produces: `AdminAuthController::login(Request): string`、`verifyLoginCode(Request): string`、`logout(Request): string`；私有 `issueAdminToken(Request, string): string`。

- [ ] **Step 1: 写入真实路由注册表测试基类和认证期望**

创建 `tests/Unit/AdminControllerRouteContractTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\AdminAuthController;
use app\middleware\AdminAuthMiddleware;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Webman\Route;
use Webman\Route\Route as RouteObject;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminControllerRouteContractTest extends TestCase
{
    protected function setUp(): void
    {
        Route::load([dirname(__DIR__, 2) . '/config']);
    }

    public function testPublicAuthenticationRoutesUseDedicatedController(): void
    {
        $this->assertRoute('POST', '/api/admin/login', [AdminAuthController::class, 'login']);
        $this->assertRoute('POST', '/api/admin/login/verify', [AdminAuthController::class, 'verifyLoginCode']);
        $this->assertRoute('POST', '/api/admin/logout', [AdminAuthController::class, 'logout']);
    }

    private function assertRoute(
        string $method,
        string $path,
        array $callback,
        array $middleware = []
    ): RouteObject {
        $route = $this->route($method, $path);
        self::assertSame($callback, $route->getCallback());
        self::assertSame($middleware, $route->getMiddleware());

        return $route;
    }

    private function route(string $method, string $path): RouteObject
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->getPath() === $path && in_array($method, $route->getMethods(), true)) {
                return $route;
            }
        }

        self::fail("未注册路由 {$method} {$path}");
    }

    private function adminMiddleware(): array
    {
        return [AdminAuthMiddleware::class];
    }
}
```

该测试的具体破坏模型是：URL 仍存在，但回调类、动作、HTTP 方法或中间件发生错误。

- [ ] **Step 2: 写入真实空凭据响应测试**

创建 `tests/Unit/AdminControllerApiContractTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\AdminAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use support\Request;

final class AdminControllerApiContractTest extends TestCase
{
    public function testAuthenticationRejectsEmptyCredentials(): void
    {
        self::assertTrue(class_exists(AdminAuthController::class), '认证控制器尚未迁移');
        $payload = $this->decode((new AdminAuthController())->login($this->postRequest([])));

        self::assertSame(-1, $payload['code']);
        self::assertSame('管理员账号与密码不能为空', $payload['msg']);
    }

    private function postRequest(array $data): Request
    {
        $body = http_build_query($data);
        return new Request(
            "POST / HTTP/1.1\r\n"
            . "Host: pay.example.com\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }
}
```

该测试的具体破坏模型是：拆分时遗漏空输入校验或改变错误码、中文文案。

- [ ] **Step 3: 运行两项测试并观察正确红灯**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php`

Expected: FAIL；路由回调仍是 `AdminController`，API 测试显示“认证控制器尚未迁移”，不是语法或环境错误。

- [ ] **Step 4: 创建认证 Controller 并迁移完整安全边界**

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

从旧类原样迁移 `login()`（原 45-117 行）、`verifyLoginCode()`（124-190）、`issueAdminToken()`（196-236）和 `logout()`（242-248）。不得改变账号迁移、限频、pending Session、Token HMAC、Token 版本、Session ID 更新和登录告警顺序。

- [ ] **Step 5: 只替换三条公开认证路由**

```php
Route::post('/api/admin/login',        [app\controller\admin\AdminAuthController::class, 'login']);
Route::post('/api/admin/login/verify', [app\controller\admin\AdminAuthController::class, 'verifyLoginCode']);
Route::post('/api/admin/logout',       [app\controller\admin\AdminAuthController::class, 'logout']);
```

- [ ] **Step 6: 验证绿灯并提交**

Run: `php -l app/controller/admin/AdminAuthController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php`

Expected: PASS。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php app/controller/admin/AdminAuthController.php config/route.php
git commit -m "refactor: extract admin authentication controller"
```

### Task 2: 迁移仪表盘统计与稳定降级

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Modify: `tests/Unit/AdminControllerApiContractTest.php`
- Create: `app/controller/admin/AdminDashboardController.php`
- Modify: `config/route.php:141`

**Interfaces:**
- Consumes: `MonitorService::getMetrics()`、Redis `cx:dashboard_stats`、`cx_order`、`cx_merchant`、`cx_channel`。
- Produces: `AdminDashboardController::dashboard(Request): string`；私有 `getDashboardStats(): array`。

- [ ] **Step 1: 增加路由与真实降级响应测试**

路由测试增加：

```php
public function testDashboardRouteUsesDedicatedController(): void
{
    $route = $this->assertRoute(
        'GET',
        '/api/admin/dashboard',
        [\app\controller\admin\AdminDashboardController::class, 'dashboard'],
        $this->adminMiddleware()
    );
    self::assertSame(
        ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'],
        $route->getMethods()
    );
}
```

API 测试增加：

```php
#[RunInSeparateProcess]
#[PreserveGlobalState(false)]
public function testDashboardReturnsStableFallbackWhenInfrastructureIsUnavailable(): void
{
    $class = \app\controller\admin\AdminDashboardController::class;
    self::assertTrue(class_exists($class), '仪表盘控制器尚未迁移');
    $payload = $this->decode((new $class())->dashboard($this->postRequest([])));

    self::assertSame(1, $payload['code']);
    self::assertSame('0.00', $payload['data']['total_amount']);
    self::assertSame(0, $payload['data']['total_orders']);
    self::assertSame('100.00%', $payload['data']['success_rate']);
    self::assertSame('HEALTHY', $payload['data']['metrics']['db_pool']);
}
```

具体破坏模型是：迁移遗漏外层异常降级，导致后台首页在无数据库或 Redis 时抛异常。

- [ ] **Step 2: 运行专项测试并观察红灯**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php --filter Dashboard`

Expected: FAIL，目标 Controller 尚不存在且路由仍指向旧类。

- [ ] **Step 3: 创建仪表盘 Controller**

文件使用 `MonitorService` 和 `Illuminate\Database\Capsule\Manager as DB`，构造器只创建 `MonitorService`。原样迁移 `dashboard()`（原 253-285）和 `getDashboardStats()`（291-359），不得改变 Redis TTL、聚合 SQL、成功率公式或零值响应。

- [ ] **Step 4: 替换路由、验证并提交**

```php
Route::any('/dashboard', [app\controller\admin\AdminDashboardController::class, 'dashboard']);
```

Run: `php -l app/controller/admin/AdminDashboardController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php`

Expected: PASS。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php app/controller/admin/AdminDashboardController.php config/route.php
git commit -m "refactor: extract admin dashboard controller"
```

### Task 3: 迁移平台通道配置

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Modify: `tests/Unit/AdminControllerApiContractTest.php`
- Create: `app/controller/admin/AdminChannelConfigController.php`
- Modify: `config/route.php:142-146`

**Interfaces:**
- Consumes: `Channel`、`PaymentManager`、`RemovedPaymentDrivers`、`Authcode` 和支付驱动 `getMeta()/upchannel()`。
- Produces: `getChannelConfig(Request): string`、`listChannels(): string`、`saveChannelConfig(Request): string`；私有 `isSensitiveConfigName(string): bool`。

- [ ] **Step 1: 增加四条真实路由断言和永久移除驱动响应测试**

路由测试增加一个表驱动方法，逐项调用 `assertRoute()`：

```php
public function testPlatformChannelRoutesUseConfigController(): void
{
    $class = \app\controller\admin\AdminChannelConfigController::class;
    $middleware = $this->adminMiddleware();
    $routes = [
        ['GET', '/api/admin/channel/list', [$class, 'listChannels']],
        ['POST', '/api/admin/channel/save', [$class, 'saveChannelConfig']],
        ['GET', '/api/admin/channel/get', [$class, 'getChannelConfig']],
        ['POST', '/api/admin/channel/config/save', [$class, 'saveChannelConfig']],
        ['GET', '/api/admin/channel/inputs', [\app\controller\admin\ChannelAdminController::class, 'getConfigInputs']],
    ];

    foreach ($routes as [$method, $path, $callback]) {
        $this->assertRoute($method, $path, $callback, $middleware);
    }
}
```

API 测试增加：

```php
public function testChannelSaveRejectsPermanentlyRemovedDriver(): void
{
    $class = \app\controller\admin\AdminChannelConfigController::class;
    self::assertTrue(class_exists($class), '平台通道配置控制器尚未迁移');
    $payload = $this->decode((new $class())->saveChannelConfig(
        $this->postRequest(['c_type' => 'alipay_official'])
    ));

    self::assertSame(-1, $payload['code']);
    self::assertSame('该支付驱动已永久移除，不能创建或修改通道', $payload['msg']);
}
```

具体破坏模型是：拆分后旧驱动绕过永久移除检查，或两个保存入口映射到不同实现。

- [ ] **Step 2: 运行专项测试并观察红灯**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php --filter 'PlatformChannel|ChannelSave'`

Expected: FAIL，目标类尚不存在。

- [ ] **Step 3: 创建平台通道配置 Controller**

文件只导入 `Channel`、`PaymentManager`、`RemovedPaymentDrivers`、`Authcode`。构造器只创建 `Authcode`。原样迁移 `getChannelConfig()`（397-435）、`listChannels()`（441-488）、`saveChannelConfig()`（494-650）和 `isSensitiveConfigName()`（896-899）。

不得改变敏感字段空值、`configured` 标记、备用通道校验、驱动字段白名单、`upchannel()` 调用或加密顺序。

- [ ] **Step 4: 替换四条平台通道实例路由**

`/channel/inputs` 继续指向 `ChannelAdminController::getConfigInputs`；其余四条改为 `AdminChannelConfigController`。

- [ ] **Step 5: 验证文件规模、绿灯并提交**

Run: `php -l app/controller/admin/AdminChannelConfigController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php tests/Unit/AdminChannelFrontendContractTest.php`

Run: `(Get-Content -LiteralPath 'app/controller/admin/AdminChannelConfigController.php' -ReadCount 0).Count`

Expected: 全部 PASS；文件不超过 300 行。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php app/controller/admin/AdminChannelConfigController.php config/route.php
git commit -m "refactor: extract admin channel config controller"
```

### Task 4: 迁移商户管理

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Modify: `tests/Unit/AdminControllerApiContractTest.php`
- Create: `app/controller/admin/AdminMerchantController.php`
- Modify: `config/route.php:149-150`

**Interfaces:**
- Consumes: `Merchant` 和 `IpWhitelist::normalize()`。
- Produces: `listMerchants(Request): string`、`saveMerchant(Request): string`。

- [ ] **Step 1: 增加真实路由和非法商户输入测试**

路由测试增加：

```php
public function testMerchantRoutesUseDedicatedController(): void
{
    $class = \app\controller\admin\AdminMerchantController::class;
    $middleware = $this->adminMiddleware();
    $this->assertRoute('GET', '/api/admin/merchant/list', [$class, 'listMerchants'], $middleware);
    $this->assertRoute('POST', '/api/admin/merchant/save', [$class, 'saveMerchant'], $middleware);
}
```

API 测试增加：

```php
public function testMerchantSaveRejectsInvalidNameAndRate(): void
{
    $class = \app\controller\admin\AdminMerchantController::class;
    self::assertTrue(class_exists($class), '商户管理控制器尚未迁移');
    $payload = $this->decode((new $class())->saveMerchant(
        $this->postRequest(['name' => ' ', 'rate' => '2'])
    ));

    self::assertSame(-1, $payload['code']);
    self::assertSame('商户名称、密钥或费率格式不合法', $payload['msg']);
}
```

具体破坏模型是：迁移遗漏商户名称和费率边界，非法数据进入数据库。

- [ ] **Step 2: 运行测试并观察红灯**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php --filter Merchant`

Expected: FAIL。

- [ ] **Step 3: 创建商户 Controller 并迁移**

文件只导入 `Merchant` 和 `IpWhitelist`，原样迁移 `listMerchants()`（368-391）与 `saveMerchant()`（656-727）。不得下发 `key`、`password_hash`，不得改变 PID、密钥、初始密码和 bcrypt cost 12。

- [ ] **Step 4: 替换两条路由、验证并提交**

Run: `php -l app/controller/admin/AdminMerchantController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php`

Expected: PASS。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php app/controller/admin/AdminMerchantController.php config/route.php
git commit -m "refactor: extract admin merchant controller"
```

### Task 5: 迁移安全设置与主页模板

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Modify: `tests/Unit/AdminControllerApiContractTest.php`
- Create: `app/controller/admin/AdminSecurityController.php`
- Create: `app/controller/admin/MerchantTemplateController.php`
- Modify: `config/route.php:152,207-208`

**Interfaces:**
- Consumes: `Authcode`、`DB`、管理员 Session 和 `base_path()`。
- Produces: `getSecurityConfig(Request): string`、`saveSecurityConfig(Request): string`、`saveTemplate(Request): string`。

- [ ] **Step 1: 增加三条真实路由和两个独立错误响应测试**

路由测试增加：

```php
public function testSecurityAndTemplateRoutesUseDedicatedControllers(): void
{
    $middleware = $this->adminMiddleware();
    $this->assertRoute(
        'POST',
        '/api/admin/template/save',
        [\app\controller\admin\MerchantTemplateController::class, 'saveTemplate'],
        $middleware
    );
    $this->assertRoute(
        'GET',
        '/api/admin/security/config',
        [\app\controller\admin\AdminSecurityController::class, 'getSecurityConfig'],
        $middleware
    );
    $this->assertRoute(
        'POST',
        '/api/admin/security/config/save',
        [\app\controller\admin\AdminSecurityController::class, 'saveSecurityConfig'],
        $middleware
    );
}
```

API 测试分别增加：

```php
public function testSecuritySaveRejectsShortVerificationCode(): void
{
    $class = \app\controller\admin\AdminSecurityController::class;
    self::assertTrue(class_exists($class), '安全设置控制器尚未迁移');
    $payload = $this->decode((new $class())->saveSecurityConfig(
        $this->postRequest(['verify_code' => '123'])
    ));

    self::assertSame(-1, $payload['code']);
    self::assertSame('验证码长度须在4至32位之间', $payload['msg']);
}

public function testTemplateSaveRejectsTraversalName(): void
{
    $class = \app\controller\admin\MerchantTemplateController::class;
    self::assertTrue(class_exists($class), '主页模板控制器尚未迁移');
    $payload = $this->decode((new $class())->saveTemplate(
        $this->postRequest(['template' => '../bad'])
    ));

    self::assertSame(-1, $payload['code']);
    self::assertSame('主页模板不存在或名称不合法', $payload['msg']);
}
```

具体破坏模型分别是：短验证码被保存、模板名称可越界访问文件。

- [ ] **Step 2: 运行测试并观察红灯**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php --filter 'Security|Template'`

Expected: FAIL。

- [ ] **Step 3: 创建安全设置与模板 Controller**

`AdminSecurityController` 只导入 `DB` 和 `Authcode`，构造器创建 `Authcode`，原样迁移 `getSecurityConfig()`（803-826）与 `saveSecurityConfig()`（834-891）。

`MerchantTemplateController` 只导入 `DB`，原样迁移 `saveTemplate()`（779-797）。

- [ ] **Step 4: 替换三条路由、验证并提交**

Run: `php -l app/controller/admin/AdminSecurityController.php`

Run: `php -l app/controller/admin/MerchantTemplateController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php`

Expected: PASS。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php app/controller/admin/AdminSecurityController.php app/controller/admin/MerchantTemplateController.php config/route.php
git commit -m "refactor: extract admin security and template controllers"
```

### Task 6: 合并人工补单并删除旧控制器

**Files:**
- Modify: `tests/Unit/AdminControllerRouteContractTest.php`
- Modify: `tests/Unit/AdminControllerApiContractTest.php`
- Modify: `app/controller/admin/OrderAdminController.php`
- Modify: `config/route.php:151`
- Delete: `app/controller/admin/AdminController.php`

**Interfaces:**
- Consumes: `OrderService::markAsPaid()`、`resendNotify()`、`AuditLog` 和内存 SQLite。
- Produces: `OrderAdminController::forceNotifyOrder(Request): string`；真实路由表中不再存在旧类回调。

- [ ] **Step 1: 增加补单路由和内存数据库响应测试**

路由测试增加：

```php
public function testForceNotifyUsesExistingOrderController(): void
{
    $this->assertRoute(
        'POST',
        '/api/admin/order/force_notify',
        [\app\controller\admin\OrderAdminController::class, 'forceNotifyOrder'],
        $this->adminMiddleware()
    );
}

public function testNoRegisteredRouteUsesLegacyAdminController(): void
{
    foreach (Route::getRoutes() as $route) {
        $callback = $route->getCallback();
        if (!is_array($callback)) {
            continue;
        }
        self::assertNotSame(
            'app\\controller\\admin\\AdminController',
            $callback[0] ?? null,
            $route->getPath() . ' 仍回调旧控制器'
        );
    }
}
```

API 测试导入 `Illuminate\Database\Capsule\Manager as DB` 和 `Illuminate\Database\Schema\Blueprint`，增加：

```php
#[RunInSeparateProcess]
#[PreserveGlobalState(false)]
public function testForceNotifyReturnsNotFoundForUnknownOrder(): void
{
    $class = \app\controller\admin\OrderAdminController::class;
    self::assertTrue(method_exists($class, 'forceNotifyOrder'), '人工补单尚未迁移');

    $db = new DB();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $db->setAsGlobal();
    $db->bootEloquent();
    $db->schema()->create('cx_order', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('trade_no')->unique();
        $table->unsignedTinyInteger('status')->default(0);
        $table->decimal('price', 12, 2)->default(0);
        $table->unsignedInteger('channel_id')->default(0);
    });
    $db->schema()->create('cx_audit_log', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('operator');
        $table->string('action');
        $table->text('context');
        $table->string('result');
        $table->string('ip');
        $table->unsignedInteger('created_at');
    });

    $payload = $this->decode((new $class())->forceNotifyOrder(
        $this->postRequest(['trade_no' => 'ADMIN-MISSING'])
    ));

    self::assertSame(-1, $payload['code']);
    self::assertSame('订单不存在', $payload['msg']);
    self::assertSame('force_pay', $db->table('cx_audit_log')->value('action'));
    self::assertSame('fail', $db->table('cx_audit_log')->value('result'));
}
```

具体破坏模型是：补单路由未迁移、未知订单未拒绝，或失败审计丢失。

- [ ] **Step 2: 运行测试并观察红灯**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php --filter 'ForceNotify|Legacy'`

Expected: FAIL，`OrderAdminController` 尚无补单方法且路由仍指向旧类。

- [ ] **Step 3: 将人工补单原样迁入现有订单 Controller**

增加 `use support\AuditLog;`，把旧类 `forceNotifyOrder()`（734-772）完整迁移到 `close()` 后。不得改变审计动作名、`MANUAL_` 外部单号前缀、结算参数或响应。

- [ ] **Step 4: 替换补单路由并删除旧类**

```php
Route::post('/order/force_notify', [app\controller\admin\OrderAdminController::class, 'forceNotifyOrder']);
```

确认真实路由测试覆盖的 14 条路由全部已迁移后，删除 `app/controller/admin/AdminController.php`。无路由的 `getSystemUpdateStatus()` 和 `executeSystemUpdate()` 不迁移。

- [ ] **Step 5: 验证绿灯、文件规模并提交**

Run: `php -l app/controller/admin/OrderAdminController.php`

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php`

Run: `Get-ChildItem app/controller/admin/*Controller.php | Where-Object Name -in @('AdminAuthController.php','AdminDashboardController.php','AdminChannelConfigController.php','AdminMerchantController.php','AdminSecurityController.php','MerchantTemplateController.php','OrderAdminController.php') | ForEach-Object { [PSCustomObject]@{File=$_.Name;Lines=(Get-Content -LiteralPath $_.FullName -ReadCount 0).Count} }`

Expected: PASS；目标文件均不超过 300 行；路由表不存在旧类回调。

```powershell
git add -- tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php app/controller/admin/OrderAdminController.php config/route.php
git add -u -- app/controller/admin/AdminController.php
git commit -m "refactor: retire monolithic admin controller"
```

### Task 7: 全量验证与实施记录

**Files:**
- Modify: `docs/superpowers/specs/2026-08-09-admin-controller-modularization-design.md`

**Interfaces:**
- Consumes: 前六个任务的全部生产代码和测试提交。
- Produces: 可审计的最终文件规模、测试数量和合并前验证记录。

- [ ] **Step 1: 对全部目标 PHP 文件执行语法检查**

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
  'tests/Unit/AdminControllerRouteContractTest.php',
  'tests/Unit/AdminControllerApiContractTest.php'
)
foreach ($file in $files) {
  php -l $file
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
```

Expected: 每个文件均输出 `No syntax errors detected`。

- [ ] **Step 2: 运行相关契约测试**

Run: `php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php tests/Unit/AdminControllerApiContractTest.php tests/Unit/AdminChannelFrontendContractTest.php tests/Unit/AdminPageModuleContractTest.php`

Expected: PASS，0 failure，0 error。

- [ ] **Step 3: 运行根项目全量测试**

Run: `php vendor/bin/phpunit`

Expected: 不少于 321 tests，0 failure，0 error。

- [ ] **Step 4: 检查文件规模、旧路由回调和差异质量**

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
php vendor/bin/phpunit tests/Unit/AdminControllerRouteContractTest.php
git diff --check
git status --short
```

Expected: 每个目标文件不超过 300 行；真实路由测试通过；`git diff --check` 无输出；`CXPAY.rar` 没有变更或暂存记录。

- [ ] **Step 5: 更新设计文档实施记录并提交**

在设计文档末尾新增“实施记录”，写入 Step 1-4 真实输出中的七个文件行数、根 PHPUnit 测试数、断言数、语法检查和路由测试结果。

```powershell
git add -- docs/superpowers/specs/2026-08-09-admin-controller-modularization-design.md
git diff --cached --check
git commit -m "test: verify modular admin controllers"
```

- [ ] **Step 6: 按分支完成集成**

使用 `superpowers:verification-before-completion` 复核证据，再使用 `superpowers:finishing-a-development-branch` 选择本地合并。按用户要求先确保功能分支全部提交，再快进合并到 `main`；合并后在 `main` 重跑 `php vendor/bin/phpunit`、`git diff --check` 和 `git status --short`，最后只移除本轮新建的工作树和临时分支。
