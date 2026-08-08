# 云端控制面 M0 安全边界实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将官方云端服务端能力从普通 CXPAY 支付节点撤下，关闭完整授权 Key 与无支付直接授权路径，并为后续独立控制面保留安全、明确的客户端入口。

**Architecture:** 普通 CXPAY 只注册支付业务和本地插件客户端路由；原 `CloudLicenseController` 变为不可执行业务的 HTTP 410 墓碑控制器，并显式关闭 Webman 默认路由。现有超长 `PluginMarketController` 拆出独立的云端客户端控制器，在 Ed25519 实例协议落地前只返回官方工作台跳转或实例激活提示，不再发送 `domain + auth_key` 请求。

**Tech Stack:** PHP 8.2、Webman 2、PHPUnit 10、现有配置助手与 HTTP Response。

## Global Constraints

- 普通 CXPAY 部署不运行官方云端授权服务端接口。
- 控制面与 CXPAY 使用不同数据库、密钥和部署配置。
- 任何查询接口不得返回完整授权 Key。
- 支付插件只能在已安装 CXPAY 的插件商城下载和更新。
- 插件购买、续费和源码下载属于独立云端控制面，不在 CXPAY 本地完成。
- CXPAY 实例最终使用域名与 Ed25519 公钥绑定；M0 不实现临时的第二套鉴权协议。
- M0 停止旧 `domain + auth_key` 商品、购买和下载请求，只保留后续实例协议的路由入口。
- Controller 只处理请求边界；网络客户端和实例签名在后续 M3 里程碑实现。
- 所有生产行为变更严格遵循测试先行：测试必须先因缺失安全边界而失败，再编写最小实现。
- 中文错误消息和文档使用中文；稳定机器错误码使用大写下划线英文。

---

## File Structure

### 新建文件

- `config/cloud.php`：CXPAY 侧唯一的官方云端门户和 API 地址配置，不保存授权 Key。
- `config/deployment.php`：声明 CXPAY 主应用只支持 `payment` 运行角色。
- `app/controller/admin/CloudPluginMarketController.php`：云端插件入口边界，在 M3 前返回迁移/激活状态。
- `tests/Unit/CloudDeploymentBoundaryTest.php`：验证支付节点不注册内嵌云端服务端路由。
- `tests/Unit/LegacyCloudControllerRetirementTest.php`：验证旧控制器所有入口均返回 410 且不泄露密钥。
- `tests/Unit/CloudPluginMarketBoundaryTest.php`：验证本地插件入口不再发起旧协议购买或下载。
- `docs/contracts/cloud-control-plane-instance-v1.md`：固定后续实例激活、插件目录和下载凭证协议。

### 修改文件

- `config/route.php`：移除 `/api/cloud/*` 服务端路由，重定向 `/cloud`，禁用旧控制器默认路由，并将本地云端插件路由指向新控制器。
- `app/controller/api/CloudLicenseController.php`：替换为 HTTP 410 墓碑控制器。
- `app/controller/admin/PluginMarketController.php`：删除云端购买/下载职责，只保留本地插件生命周期管理。
- `.env.example`：增加非敏感云端门户和 API 地址示例。
- `README.md`：说明支付节点与独立云端控制面的运行边界。

---

### Task 1: 支付节点路由与配置边界

**Files:**
- Create: `config/cloud.php`
- Create: `config/deployment.php`
- Create: `tests/Unit/CloudDeploymentBoundaryTest.php`
- Modify: `config/route.php:41-75`

**Interfaces:**
- Produces: `config('cloud.portal_url') : string`
- Produces: `config('cloud.api_url') : string`
- Produces: `config('deployment.role') = payment`
- Produces: `/cloud` 到官方工作台的重定向
- Guarantees: 自定义路由集合不存在 `/api/cloud` 前缀，且 `CloudLicenseController` 默认路由被禁用

- [ ] **Step 1: 编写失败的路由边界测试**

生产变更若重新注册 `/api/cloud/*`，删除默认路由禁用，或误删本地插件客户端入口，本测试必须失败。

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\api\CloudLicenseController;
use PHPUnit\Framework\TestCase;
use Webman\Route;

final class CloudDeploymentBoundaryTest extends TestCase
{
    /** @var list<string> */
    private static array $paths = [];

    public static function setUpBeforeClass(): void
    {
        Route::load([base_path() . '/config/route.php']);
        self::$paths = array_values(array_map(
            static fn($route): string => $route->getPath(),
            Route::getRoutes()
        ));
    }

    public function testPaymentRuntimeDoesNotExposeEmbeddedCloudServerRoutes(): void
    {
        foreach (self::$paths as $path) {
            self::assertFalse(
                str_starts_with($path, '/api/cloud'),
                "支付节点不应注册云端服务端路由：{$path}"
            );
        }

        self::assertTrue(Route::isDefaultRouteDisabled(CloudLicenseController::class));
    }

    public function testPaymentRuntimeKeepsLocalCloudPluginClientRoutes(): void
    {
        self::assertContains('/api/admin/plugin/cloud_market', self::$paths);
        self::assertContains('/api/admin/plugin/cloud_buy', self::$paths);
        self::assertContains('/api/admin/plugin/cloud_download', self::$paths);
    }

    public function testCloudPortalUsesDedicatedConfiguration(): void
    {
        self::assertSame('payment', config('deployment.role'));
        self::assertSame('https://cloud.cxpay.com', config('cloud.portal_url'));
        self::assertSame('https://api.cloud.cxpay.com', config('cloud.api_url'));
    }
}
```

- [ ] **Step 2: 运行测试并确认因旧路由仍存在而失败**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/CloudDeploymentBoundaryTest.php --display-warnings
```

Expected: FAIL，至少报告 `/api/cloud/site_info` 仍被注册，或 `CloudLicenseController` 默认路由尚未禁用。

- [ ] **Step 3: 新建非敏感云端配置**

```php
<?php

declare(strict_types=1);

return [
    'portal_url' => rtrim((string)env('CLOUD_PORTAL_URL', 'https://cloud.cxpay.com'), '/'),
    'api_url' => rtrim((string)env('CLOUD_API_URL', 'https://api.cloud.cxpay.com'), '/'),
];
```

该配置不得加入 `auth_key`、实例私钥、平台签名密钥或云端数据库配置。

同时创建 `config/deployment.php`：

```php
<?php

declare(strict_types=1);

return [
    'role' => strtolower(trim((string)env('CXPAY_RUNTIME_ROLE', 'payment'))),
];
```

- [ ] **Step 4: 修改支付节点路由**

在 `config/route.php` 中：

1. 删除两个 `/api/cloud` 路由组；
2. 将 `/cloud` 改为重定向 `config('cloud.portal_url')`；
3. 在路由文件顶部注册以下默认路由禁用；
4. 在注册任何业务路由前拒绝非 `payment` 运行角色。

```php
if ((string)config('deployment.role', 'payment') !== 'payment') {
    throw new RuntimeException('CXPAY 主应用只支持 payment 运行角色，云端控制面必须独立部署');
}

Route::disableDefaultRoute(app\controller\api\CloudLicenseController::class);

Route::get('/cloud', static function () {
    return redirect((string)config('cloud.portal_url', 'https://cloud.cxpay.com'));
});
```

不得删除 `/api/admin/plugin/cloud_market`、`cloud_buy` 和 `cloud_download`，它们是 CXPAY 本地插件客户端入口。

- [ ] **Step 5: 运行定向测试并确认通过**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/CloudDeploymentBoundaryTest.php --display-warnings
```

Expected: PASS，3 tests，且无 warning/risky。

- [ ] **Step 6: 运行现有路由与系统保护相关测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/SystemUpdateGuardTest.php tests/Unit/CloudDeploymentBoundaryTest.php --display-warnings
```

Expected: PASS。

- [ ] **Step 7: 提交路由边界**

```powershell
git add config/cloud.php config/deployment.php config/route.php tests/Unit/CloudDeploymentBoundaryTest.php
git commit -m "fix: isolate cloud server routes from payment runtime"
```

---

### Task 2: 退役内嵌云端控制器并消除密钥泄露

**Files:**
- Create: `tests/Unit/LegacyCloudControllerRetirementTest.php`
- Modify: `app/controller/api/CloudLicenseController.php`

**Interfaces:**
- Produces: 所有旧控制器方法统一返回 HTTP 410
- Produces: `error_code = CLOUD_CONTROL_PLANE_REQUIRED`
- Produces: `data.portal_url` 指向独立云端工作台
- Guarantees: 响应不包含 `auth_key`、`key_full` 或数据库授权信息

- [ ] **Step 1: 编写失败的旧控制器退役测试**

生产变更若让任一旧入口重新执行授权、购买、下载或返回完整 Key，本测试必须失败。

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\api\CloudLicenseController;
use PHPUnit\Framework\TestCase;
use support\Request;

final class LegacyCloudControllerRetirementTest extends TestCase
{
    public function testEmbeddedProviderActionsAreRetiredWithoutLeakingCredentials(): void
    {
        $controller = new CloudLicenseController();
        $request = new Request("POST / HTTP/1.1\r\nHost: pay.example.com\r\nContent-Length: 0\r\n\r\n");
        $actions = [
            ['getWxLoginQr', []],
            ['pollWxLogin', [$request]],
            ['getQqLoginQr', []],
            ['pollQqLogin', [$request]],
            ['sendEmailCode', [$request]],
            ['bindQq', [$request]],
        ];

        foreach ($actions as [$method, $arguments]) {
            $response = $controller->{$method}(...$arguments);
            $body = $response->rawBody();
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

            self::assertSame(410, $response->getStatusCode(), $method);
            self::assertSame('CLOUD_CONTROL_PLANE_REQUIRED', $payload['error_code'], $method);
            self::assertSame('OPEN_PORTAL', $payload['data']['action'], $method);
            self::assertStringNotContainsString('auth_key', $body, $method);
            self::assertStringNotContainsString('key_full', $body, $method);
        }
    }
}
```

- [ ] **Step 2: 运行测试并确认旧方法仍返回业务响应或访问数据库**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/LegacyCloudControllerRetirementTest.php --display-warnings
```

Expected: FAIL。失败原因应为现有供应商占位响应返回 501 而不是 410；不能是测试语法或数据库错误。

- [ ] **Step 3: 将旧控制器替换为明确的 410 墓碑实现**

保留所有原有公开方法签名，防止旧调用出现不可控异常；每个方法只委托 `retired()`。完整目标结构如下：

```php
<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Request;
use support\Response;

final class CloudLicenseController
{
    public function getWxLoginQr(): Response { return $this->retired(); }
    public function pollWxLogin(Request $request): Response { return $this->retired(); }
    public function getQqLoginQr(): Response { return $this->retired(); }
    public function pollQqLogin(Request $request): Response { return $this->retired(); }
    public function sendEmailCode(Request $request): Response { return $this->retired(); }
    public function bindQq(Request $request): Response { return $this->retired(); }
    public function downloadPackage(Request $request): Response { return $this->retired(); }
    public function traceLeaked(Request $request): Response { return $this->retired(); }
    public function getSiteInfo(Request $request): Response { return $this->retired(); }
    public function renewModule(Request $request): Response { return $this->retired(); }
    public function resetKey(Request $request): Response { return $this->retired(); }
    public function changeDomain(Request $request): Response { return $this->retired(); }
    public function pluginMarketList(Request $request): Response { return $this->retired(); }
    public function pluginBuy(Request $request): Response { return $this->retired(); }
    public function pluginDownload(Request $request): Response { return $this->retired(); }

    private function retired(): Response
    {
        return json([
            'code' => -1,
            'error_code' => 'CLOUD_CONTROL_PLANE_REQUIRED',
            'msg' => '云端授权服务已从 CXPAY 支付节点迁移，请前往独立云端工作台操作',
            'data' => [
                'action' => 'OPEN_PORTAL',
                'portal_url' => (string)config('cloud.portal_url', 'https://cloud.cxpay.com'),
            ],
        ])->withStatus(410);
    }
}
```

未使用的 `$request` 参数是旧公开签名的兼容边界，不读取请求内容，也不连接数据库。

- [ ] **Step 4: 运行退役测试并确认通过**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/LegacyCloudControllerRetirementTest.php --display-warnings
```

Expected: PASS，1 test，所有现有供应商入口均被循环验证；其余旧业务入口由 Task 1 的路由不可达测试保护。

- [ ] **Step 5: 运行云端边界组合测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/CloudDeploymentBoundaryTest.php tests/Unit/LegacyCloudControllerRetirementTest.php --display-warnings
```

Expected: PASS。

- [ ] **Step 6: 提交墓碑控制器**

```powershell
git add app/controller/api/CloudLicenseController.php tests/Unit/LegacyCloudControllerRetirementTest.php
git commit -m "fix: retire embedded cloud control endpoints"
```

---

### Task 3: 拆分插件市场并停止旧授权 Key 协议

**Files:**
- Create: `app/controller/admin/CloudPluginMarketController.php`
- Create: `tests/Unit/CloudPluginMarketBoundaryTest.php`
- Modify: `app/controller/admin/PluginMarketController.php:14,212-436`
- Modify: `config/route.php:189-192`

**Interfaces:**
- Consumes: `config('cloud.portal_url')`
- Produces: `GET /api/admin/plugin/cloud_market` 的实例激活提示
- Produces: `POST /api/admin/plugin/cloud_buy` 的云端工作台跳转提示
- Produces: `POST /api/admin/plugin/cloud_download` 的实例激活提示
- Guarantees: CXPAY 不再通过 URL 查询参数或表单发送旧 `auth_key`

- [ ] **Step 1: 编写失败的云端插件入口测试**

生产变更若恢复本地直接购买、旧 Key 下载，或错误地宣称插件下载成功，本测试必须失败。

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\PluginMarketController;
use PHPUnit\Framework\TestCase;
use support\Request;

final class CloudPluginMarketBoundaryTest extends TestCase
{
    public function testCatalogRequiresActivatedInstanceProtocol(): void
    {
        $response = (new PluginMarketController())->getCloudMarket();
        $payload = json_decode($response->rawBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('CLOUD_INSTANCE_ACTIVATION_REQUIRED', $payload['error_code']);
        self::assertSame('ACTIVATE_INSTANCE', $payload['data']['action']);
    }

    public function testPurchaseMovesToIndependentCloudPortal(): void
    {
        $request = new Request("POST / HTTP/1.1\r\nHost: pay.example.com\r\nContent-Length: 0\r\n\r\n");
        $response = (new PluginMarketController())->buyFromCloud($request);
        $body = $response->rawBody();
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CLOUD_PURCHASE_MOVED_TO_PORTAL', $payload['error_code']);
        self::assertSame('OPEN_PORTAL', $payload['data']['action']);
        self::assertStringEndsWith('/plugins', $payload['data']['portal_url']);
        self::assertStringNotContainsString('auth_key', $body);
    }

    public function testDownloadCannotUseLegacyDomainKeyProtocol(): void
    {
        $request = new Request("POST / HTTP/1.1\r\nHost: pay.example.com\r\nContent-Length: 0\r\n\r\n");
        $response = (new PluginMarketController())->downloadFromCloud($request);
        $body = $response->rawBody();
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('CLOUD_INSTANCE_ACTIVATION_REQUIRED', $payload['error_code']);
        self::assertStringNotContainsString('auth_key', $body);
        self::assertStringNotContainsString('download_url', $body);
    }
}
```

- [ ] **Step 2: 运行测试并确认新控制器不存在**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/CloudPluginMarketBoundaryTest.php --display-warnings
```

Expected: FAIL，现有方法返回 HTTP 200 的旧配置错误，而不是 503/409 安全边界；不能因网络请求或测试语法而报错。

- [ ] **Step 3: 实现最小云端插件边界控制器**

```php
<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Request;
use support\Response;

final class CloudPluginMarketController
{
    public function getCloudMarket(): Response
    {
        return $this->response(
            503,
            'CLOUD_INSTANCE_ACTIVATION_REQUIRED',
            '请先完成 CXPAY 实例激活，再从独立云端获取插件目录',
            'ACTIVATE_INSTANCE'
        );
    }

    public function buyFromCloud(Request $request): Response
    {
        return $this->response(
            409,
            'CLOUD_PURCHASE_MOVED_TO_PORTAL',
            '插件购买和续费已迁移至独立云端工作台',
            'OPEN_PORTAL'
        );
    }

    public function downloadFromCloud(Request $request): Response
    {
        return $this->response(
            503,
            'CLOUD_INSTANCE_ACTIVATION_REQUIRED',
            '旧域名授权 Key 下载协议已停用，请先激活当前 CXPAY 实例',
            'ACTIVATE_INSTANCE'
        );
    }

    private function response(int $status, string $errorCode, string $message, string $action): Response
    {
        return json([
            'code' => -1,
            'error_code' => $errorCode,
            'msg' => $message,
            'data' => [
                'action' => $action,
                'portal_url' => rtrim(
                    (string)config('cloud.portal_url', 'https://cloud.cxpay.com'),
                    '/'
                ) . '/plugins',
            ],
        ])->withStatus($status);
    }
}
```

- [ ] **Step 4: 将云端职责从超长控制器中移除**

在 `PluginMarketController.php` 中：

- 删除未使用的 `use app\service\PluginLicenseService;`；
- 删除旧 `siteAuthParams()`、`cloudGet()`、`cloudPost()`、`cloudRequest()` 以及全部 `auth_key` 和网络下载实现；
- 保留下列三个兼容方法作为薄委托，已有后台调用不会出现方法不存在：

```php
public function getCloudMarket(): Response
{
    return (new CloudPluginMarketController())->getCloudMarket();
}

public function buyFromCloud(Request $request): Response
{
    return (new CloudPluginMarketController())->buyFromCloud($request);
}

public function downloadFromCloud(Request $request): Response
{
    return (new CloudPluginMarketController())->downloadFromCloud($request);
}
```

- 保留类结尾大括号；
- 除三个兼容委托外，该文件只负责本地插件列表、安装、启停、回滚和卸载。

在 `config/route.php` 中将三条路由改为：

```php
Route::get('/plugin/cloud_market', [app\controller\admin\CloudPluginMarketController::class, 'getCloudMarket']);
Route::post('/plugin/cloud_buy', [app\controller\admin\CloudPluginMarketController::class, 'buyFromCloud']);
Route::post('/plugin/cloud_download', [app\controller\admin\CloudPluginMarketController::class, 'downloadFromCloud']);
```

- [ ] **Step 5: 运行插件云端边界测试并确认通过**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/CloudPluginMarketBoundaryTest.php --display-warnings
```

Expected: PASS，3 tests。

- [ ] **Step 6: 运行插件生命周期和路由回归测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/PluginLifecycleManagerTest.php tests/Unit/PluginPackageInstallerTest.php tests/Unit/CloudDeploymentBoundaryTest.php tests/Unit/CloudPluginMarketBoundaryTest.php --display-warnings
```

Expected: PASS。

- [ ] **Step 7: 检查拆分后的文件规模和旧协议残留**

Run:

```powershell
(Get-Content app/controller/admin/PluginMarketController.php).Count
Select-String -Path app/controller/admin/PluginMarketController.php,app/controller/admin/CloudPluginMarketController.php -Pattern 'auth_key|file_get_contents|cloudRequest'
```

Expected:

- `PluginMarketController.php` 不超过 260 行；
- `Select-String` 无匹配；
- 新控制器不执行网络请求。

- [ ] **Step 8: 提交控制器拆分**

```powershell
git add app/controller/admin/PluginMarketController.php app/controller/admin/CloudPluginMarketController.php config/route.php tests/Unit/CloudPluginMarketBoundaryTest.php
git commit -m "refactor: isolate cloud plugin client boundary"
```

---

### Task 4: 固定实例 API 契约并更新部署说明

**Files:**
- Create: `docs/contracts/cloud-control-plane-instance-v1.md`
- Modify: `.env.example`
- Modify: `README.md:20-25`

**Interfaces:**
- Documents: CXPAY 与独立控制面的实例激活、目录、更新和下载凭证协议
- Documents: Ed25519 规范串、请求头、时间窗、随机数和错误码
- Configures: `CLOUD_PORTAL_URL` 与 `CLOUD_API_URL`

- [ ] **Step 1: 编写 v1 实例协议文档**

文档必须定义以下请求，不使用未实现的临时 Key 协议：

```text
POST /api/instance/v1/activations/exchange-legacy
POST /api/instance/v1/activations/confirm
GET  /api/instance/v1/plugins/catalog
GET  /api/instance/v1/plugins/{plugin_id}/updates
POST /api/instance/v1/plugins/{plugin_id}/download-grants
```

除首次 `exchange-legacy` 外，实例请求头固定为：

```text
X-CXPAY-Instance: ins_...
X-CXPAY-Timestamp: Unix 秒
X-CXPAY-Nonce: 至少 16 字节随机值的 Base64URL
X-CXPAY-Signature: Ed25519 签名的 Base64URL
Idempotency-Key: 写请求必填
```

规范串固定为：

```text
HTTP_METHOD\n
REQUEST_PATH\n
TIMESTAMP\n
NONCE\n
BODY_SHA256\n
INSTANCE_ID
```

下载凭证响应只包含 `grant_token`、`expires_at`、`sha256`、`size` 和平台签名元数据，不返回永久对象存储地址。文档明确 300 秒请求时间窗、随机数持久化、5 分钟一次性下载凭证和稳定错误码：

```text
CLOUD_INSTANCE_NOT_FOUND
CLOUD_INSTANCE_REVOKED
CLOUD_SIGNATURE_INVALID
CLOUD_REQUEST_EXPIRED
CLOUD_NONCE_REPLAYED
CLOUD_SITE_LICENSE_INACTIVE
CLOUD_PLUGIN_NOT_ENTITLED
CLOUD_PLUGIN_VERSION_INCOMPATIBLE
CLOUD_ARTIFACT_NOT_AVAILABLE
```

- [ ] **Step 2: 更新环境变量示例**

在 `.env.example` 的 `APP_URL` 后加入：

```dotenv
# CXPAY 主应用固定为独立支付系统；云端控制面使用单独服务和配置
CXPAY_RUNTIME_ROLE=payment

# 独立云端控制面仅配置公开地址；不要在环境文件中配置云端数据库或平台签名私钥
CLOUD_PORTAL_URL=https://cloud.cxpay.com
CLOUD_API_URL=https://api.cloud.cxpay.com
```

- [ ] **Step 3: 更新 README 部署边界**

在现有限制说明后明确：

- CXPAY 是独立支付系统，不承载官方云端控制面；
- `/cloud` 只跳转独立工作台；
- 源码、更新包购买下载在云端完成；
- 插件购买续费在云端完成，插件文件只允许激活后的 CXPAY 插件商城下载；
- M0 阶段旧 Key 插件下载关闭，实例协议将在 M3 实现。

- [ ] **Step 4: 检查文档和配置差异**

Run:

```powershell
git diff --check
Select-String -Path .env.example,README.md,docs/contracts/cloud-control-plane-instance-v1.md -Pattern 'CLOUD_PORTAL_URL|CLOUD_API_URL|Ed25519|download-grants'
```

Expected: `git diff --check` 退出码 0，四个关键内容均可定位。

- [ ] **Step 5: 提交契约文档**

```powershell
git add .env.example README.md docs/contracts/cloud-control-plane-instance-v1.md
git commit -m "docs: define cloud instance api boundary"
```

---

### Task 5: M0 完整验证

**Files:**
- Verify only; no production changes expected

**Interfaces:**
- Verifies: M0 安全边界、现有支付功能和插件生命周期没有回归

- [ ] **Step 1: 检查所有 PHP 文件语法**

Run:

```powershell
$failed = $false
Get-ChildItem app,support,config,tests -Recurse -File -Filter '*.php' | ForEach-Object {
    php -l $_.FullName
    if ($LASTEXITCODE -ne 0) { $failed = $true }
}
if ($failed) { exit 1 }
```

Expected: 所有文件 `No syntax errors detected`。

- [ ] **Step 2: 运行 M0 定向测试**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/CloudDeploymentBoundaryTest.php tests/Unit/LegacyCloudControllerRetirementTest.php tests/Unit/CloudPluginMarketBoundaryTest.php --display-warnings
```

Expected: PASS，7 tests，且无 warning/risky。

- [ ] **Step 3: 运行完整测试套件**

Run:

```powershell
php vendor/bin/phpunit --display-warnings
```

Expected: 现有 254 tests 加 M0 新增 7 tests，共 261 tests 全部通过；断言数以实际输出为准。

- [ ] **Step 4: 验证 Composer 和差异质量**

Run:

```powershell
composer validate --no-check-publish
git diff --check main...HEAD
git status --short --branch
```

Expected:

- Composer metadata valid；
- 无空白错误；
- 工作区干净；
- 分支为 `codex/cloud-control-plane`。

- [ ] **Step 5: 检查安全边界最终状态**

Run:

```powershell
Select-String -Path config/route.php -Pattern "Route::group\('/api/cloud'"
Select-String -Path app/controller/admin/PluginMarketController.php,app/controller/admin/CloudPluginMarketController.php -Pattern 'auth_key|key_full|cloudRequest'
```

Expected: 两条命令均无匹配。若存在匹配，停止交付并修复对应边界。

## M0 Completion Gate

只有同时满足以下条件才能开始 M1：

- 支付节点不注册 `/api/cloud/*`；
- 旧控制器所有方法统一返回 410；
- 完整授权 Key 不出现在响应；
- 本地插件入口不发送旧 Key，不直接购买，不直接下载；
- `PluginMarketController.php` 已降至 260 行以内；
- 实例 v1 契约已经固定；
- M0 定向测试和完整测试全部通过；
- 每个任务形成独立提交，工作区干净。
