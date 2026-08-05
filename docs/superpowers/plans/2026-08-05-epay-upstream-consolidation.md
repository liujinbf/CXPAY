# 外部易支付上游能力收敛实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 永久移除重复的 `qqpay_epay`，保留并重新定位 `epay_generic`，同时在配置保存和实际下单阶段严格阻止外部易支付上游指向当前 CXPAY 实例。

**Architecture:** `RemovedPaymentDrivers` 继续作为不可逆驱动墓碑；`EpayUpstreamGuard` 负责 URL 规范化、可注入 DNS 解析、非公网拒绝和本站回环识别；`EpayGeneric\Driver` 在保存与下单时复用同一安全策略，并把已经验证的解析结果直接绑定到 cURL。现有 `LegacyPaymentDriverCleanupService` 继续负责 dry-run、待支付订单阻断、非敏感归档和事务删除，但按驱动代码写入不同归档原因。

**Tech Stack:** PHP 8.2、Webman 2、PHPUnit 10、Illuminate Database、MySQL 5.7+/8.0、SQLite 集成测试、cURL、HTML/原生 JavaScript。

## Global Constraints

- 只在 `fix/p0-hardening` 上实施，PR #2 在完整验证前保持 Draft。
- 永久移除 `qqpay_epay`，不得仅在前端隐藏。
- `epay_generic` 继续作为系统内置驱动，不在本轮插件化。
- `epay_generic` 默认不创建通道实例、不自动启用。
- `epay_generic` 继续支持 `alipay`、`wxpay`、`qqpay`、`submit` 和 `mapi`。
- 回环保护必须严格拒绝且无绕过开关。
- 保存配置和实际下单必须各自执行一次回环保护。
- 安全校验失败不得回退到 submit；只有 MAPI 普通网络失败可以回退。
- DNS 单元测试只能使用可注入解析器，不得访问真实公网 DNS。
- MAPI 请求必须使用校验得到的 IP 设置 `CURLOPT_RESOLVE`，不得二次解析后接受其他地址。
- 迁移默认 dry-run，只有精确 `--apply` 才能修改活动数据。
- 存在 `cx_order.status = 0` 引用目标通道时，迁移必须停止且不产生任何 DML。
- 不归档 `cx_pay_channel.config`、PID、KEY、Token、Cookie、私钥或其他秘密。
- 保留 `cx_order.channel_id`、`cx_callbill.channel_id` 和历史状态。
- 当前服务器必须保留 `CXPAY.rar`、`cxpay-webman.supervisor.conf`、`install.lock`；严禁执行 `git clean -fd`。
- 不得以定向测试替代完整 PHPUnit 回归。

## File Map

**Create**

- `app/payment/EpayUpstreamException.php`：区分安全拒绝与普通网络错误。
- `app/payment/EpayUpstreamGuard.php`：验证外部易支付上游 URL，并返回固定请求目标。
- `tests/Unit/EpayUpstreamGuardTest.php`：覆盖规范化、DNS、非公网和本站回环规则。
- `tests/Unit/EpayGenericDriverTest.php`：覆盖元数据、三支付类型、submit/mapi、安全拒绝与网络回退。

**Modify**

- `app/payment/RemovedPaymentDrivers.php`：加入 `qqpay_epay` 墓碑。
- `app/payment/Drivers/EpayGeneric/Driver.php`：改名、接入安全防护和可测试 HTTP 传输。
- `app/service/LegacyPaymentDriverCleanupService.php`：按驱动代码选择归档原因。
- `tests/Unit/PaymentManagerTest.php`：把 `qqpay_epay` 纳入永久移除契约。
- `tests/Unit/PluginPackageInstallerTest.php`：阻止签名插件重新声明 `qqpay_epay`。
- `tests/Unit/RemovedPaymentDriverFrontendContractTest.php`：验证重复驱动源码不存在。
- `tests/Integration/LegacyPaymentDriverCleanupServiceTest.php`：验证替代驱动归档原因和迁移安全性。
- `docs/runbooks/remove-legacy-payment-drivers.md`：加入 `qqpay_epay` 和再次执行迁移的操作步骤。

**Delete**

- `app/payment/Drivers/QqpayEpay/Driver.php`
- 空目录 `app/payment/Drivers/QqpayEpay/`

**Verify without modification unless a test fails**

- `app/payment/PaymentManager.php`
- `app/payment/Plugin/PluginPackageInstaller.php`
- `app/controller/admin/PluginMarketController.php`
- `app/controller/admin/AdminController.php`
- `app/controller/api/MerchantChannelController.php`
- `app/controller/admin/PackvipAdminController.php`
- `database/migrations/20260805_remove_legacy_payment_drivers.php`
- `database/install.sql`
- `public/admin/index.html`

---

### Task 1: 墓碑并物理删除 `qqpay_epay`

**Files:**
- Modify: `app/payment/RemovedPaymentDrivers.php`
- Modify: `tests/Unit/PaymentManagerTest.php`
- Modify: `tests/Unit/PluginPackageInstallerTest.php`
- Modify: `tests/Unit/RemovedPaymentDriverFrontendContractTest.php`
- Delete: `app/payment/Drivers/QqpayEpay/Driver.php`

**Interfaces:**
- Produces: `RemovedPaymentDrivers::contains('qqpay_epay') === true`。
- Produces: `PaymentManager::has('qqpay_epay') === false`。
- Produces: `PaymentManager::make('qqpay_epay')` 抛出包含“已永久移除”的 `InvalidArgumentException`。
- Produces: 内置注册、插件注册和签名安装包均不能恢复该代码。

- [ ] **Step 1: 修正易支付驱动分类并扩展红灯测试**

先从 `PaymentManagerTest::epayDriverProvider()` 删除 `'QQ 钱包易支付驱动' => ['qqpay_epay']`，再在 `PaymentManagerTest::removedDriverProvider()` 末尾加入：

```php
'QQ易支付重复上游' => ['qqpay_epay'],
```

现有数据提供器会同时验证 `has()`、公开列表和 `make()`。

- [ ] **Step 2: 扩展签名插件红灯测试**

在 `tests/Unit/PluginPackageInstallerTest.php` 新增：

```php
public function testRejectsSupersededQqpayEpayDriverCode(): void
{
    $package = $this->createPackage(false, 'qqpay_epay');

    $this->expectException(PluginException::class);
    $this->expectExceptionMessage('已永久移除');

    $this->installer()->install($package);
}
```

- [ ] **Step 3: 扩展源码删除契约**

在 `RemovedPaymentDriverFrontendContractTest::testRemovedDriverImplementationFilesDoNotExist()` 的目录数组中加入：

```php
'QqpayEpay',
```

该测试必须在源码删除前失败。

- [ ] **Step 4: 运行红灯测试**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/PluginPackageInstallerTest.php \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
```

Expected: 至少出现 `qqpay_epay` 仍可发现、签名包未被拒绝或源码文件仍存在的失败。

- [ ] **Step 5: 加入墓碑**

在 `RemovedPaymentDrivers::CODES` 末尾加入：

```php
'qqpay_epay',
```

不得增加特殊分支；所有现有注册、保存、列表和套餐保护继续消费统一名单。

- [ ] **Step 6: 删除重复实现**

```bash
rm -- app/payment/Drivers/QqpayEpay/Driver.php
rmdir -- app/payment/Drivers/QqpayEpay
```

- [ ] **Step 7: 运行定向测试**

```bash
php -l app/payment/RemovedPaymentDrivers.php
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/PluginPackageInstallerTest.php \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
```

Expected: PASS。

- [ ] **Step 8: 审计生产引用**

```bash
grep -RIn --exclude-dir=.git --exclude='*.md' \
  'qqpay_epay\|QqpayEpay' app public config database tests || true
```

Expected: 允许的命中仅限墓碑和明确测试数据；`app/payment/Drivers/QqpayEpay` 不得存在。

- [ ] **Step 9: 提交 Task 1**

```bash
git add \
  app/payment/RemovedPaymentDrivers.php \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/PluginPackageInstallerTest.php \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
git add -A -- app/payment/Drivers/QqpayEpay
git diff --cached --check
git commit -m "refactor: retire duplicate qqpay epay driver"
```

---

### Task 2: 实现严格的外部易支付上游地址防护

**Files:**
- Create: `app/payment/EpayUpstreamException.php`
- Create: `app/payment/EpayUpstreamGuard.php`
- Create: `tests/Unit/EpayUpstreamGuardTest.php`

**Interfaces:**
- Produces: `EpayUpstreamGuard::__construct(?Closure $resolver = null, ?string $appUrl = null, ?string $serviceHost = null, ?int $servicePort = null)`。
- Produces: `EpayUpstreamGuard::validate(string $apiUrl): array{scheme:string,host:string,port:int,ip:string}`。
- Throws: `EpayUpstreamException`，公开消息固定为 `外部易支付上游不能指向当前 CXPAY 实例`。
- Resolver contract: `Closure(string $host): list<string>`。

- [ ] **Step 1: 创建异常红灯测试入口**

新建 `tests/Unit/EpayUpstreamGuardTest.php`，先写测试辅助方法：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\EpayUpstreamException;
use app\payment\EpayUpstreamGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EpayUpstreamGuardTest extends TestCase
{
    /** @param array<string, list<string>> $records */
    private function guard(
        array $records,
        string $appUrl = 'https://pay.example.test',
        string $serviceHost = '127.0.0.1',
        int $servicePort = 8787
    ): EpayUpstreamGuard {
        $resolver = static fn(string $host): array => $records[$host] ?? [];

        return new EpayUpstreamGuard(
            $resolver,
            $appUrl,
            $serviceHost,
            $servicePort
        );
    }
}
```

- [ ] **Step 2: 写合法上游与规范化红灯测试**

加入：

```php
public function testReturnsNormalizedValidatedTarget(): void
{
    $guard = $this->guard([
        'pay.example.test' => ['8.8.8.8'],
        'upstream.example.test' => ['1.1.1.1'],
    ]);

    self::assertSame([
        'scheme' => 'https',
        'host' => 'upstream.example.test',
        'port' => 443,
        'ip' => '1.1.1.1',
    ], $guard->validate('HTTPS://Upstream.Example.Test./gateway'));
}

public function testSameIpOnDifferentEffectivePortIsAllowed(): void
{
    $guard = $this->guard([
        'pay.example.test' => ['8.8.8.8'],
        'upstream.example.test' => ['8.8.8.8'],
    ], 'http://pay.example.test');

    self::assertSame(
        443,
        $guard->validate('https://upstream.example.test')['port']
    );
}
```

- [ ] **Step 3: 写同站回环红灯测试**

加入：

```php
#[DataProvider('sameHostProvider')]
public function testRejectsSameHostAfterNormalization(string $url): void
{
    $guard = $this->guard([
        'pay.example.test' => ['8.8.8.8'],
    ]);

    $this->expectException(EpayUpstreamException::class);
    $this->expectExceptionMessage(
        '外部易支付上游不能指向当前 CXPAY 实例'
    );

    $guard->validate($url);
}

public static function sameHostProvider(): array
{
    return [
        '相同域名' => ['https://pay.example.test'],
        '大小写差异' => ['https://PAY.EXAMPLE.TEST/path'],
        '尾点差异' => ['https://pay.example.test./path'],
    ];
}

public function testRejectsDifferentHostWithSameIpAndPort(): void
{
    $guard = $this->guard([
        'pay.example.test' => ['8.8.8.8'],
        'alias.example.test' => ['8.8.8.8'],
    ]);

    $this->expectException(EpayUpstreamException::class);
    $guard->validate('https://alias.example.test');
}

public function testRejectsDirectServiceHostAndPort(): void
{
    $guard = $this->guard([
        'pay.example.test' => ['1.1.1.1'],
    ], 'https://pay.example.test', '8.8.8.8', 443);

    $this->expectException(EpayUpstreamException::class);
    $guard->validate('https://8.8.8.8');
}
```

- [ ] **Step 4: 写非公网、认证信息、端口和 DNS 红灯测试**

加入：

```php
#[DataProvider('unsafeTargetProvider')]
public function testRejectsUnsafeTarget(
    string $url,
    array $records
): void {
    $guard = $this->guard(array_merge([
        'pay.example.test' => ['8.8.8.8'],
    ], $records));

    $this->expectException(EpayUpstreamException::class);
    $guard->validate($url);
}

public static function unsafeTargetProvider(): array
{
    return [
        'localhost' => ['http://localhost', []],
        'IPv4环回' => ['http://127.0.0.1', []],
        'IPv6环回' => ['http://[::1]', []],
        'RFC1918' => ['http://10.0.0.8', []],
        '链路本地' => ['http://169.254.10.20', []],
        '保留地址' => ['http://192.0.2.10', []],
        'URL认证信息' => [
            'https://user:pass@upstream.example.test',
            ['upstream.example.test' => ['1.1.1.1']],
        ],
        '非标准端口' => [
            'https://upstream.example.test:8443',
            ['upstream.example.test' => ['1.1.1.1']],
        ],
        'DNS无结果' => ['https://missing.example.test', []],
        'DNS混合公网私网' => [
            'https://mixed.example.test',
            ['mixed.example.test' => ['1.1.1.1', '10.0.0.1']],
        ],
    ];
}

#[DataProvider('invalidAppUrlProvider')]
public function testFailsClosedWhenAppUrlIsMissingOrInvalid(
    string $appUrl
): void {
    $guard = $this->guard([
        'upstream.example.test' => ['1.1.1.1'],
    ], $appUrl);

    $this->expectException(EpayUpstreamException::class);
    $guard->validate('https://upstream.example.test');
}

public static function invalidAppUrlProvider(): array
{
    return [
        '空值' => [''],
        '非URL' => ['pay.example.test'],
        '不支持协议' => ['ftp://pay.example.test'],
    ];
}
```

- [ ] **Step 5: 运行红灯测试**

```bash
php vendor/bin/phpunit --colors=never tests/Unit/EpayUpstreamGuardTest.php
```

Expected: class-not-found。

- [ ] **Step 6: 创建领域异常**

新建 `app/payment/EpayUpstreamException.php`：

```php
<?php

declare(strict_types=1);

namespace app\payment;

use RuntimeException;

final class EpayUpstreamException extends RuntimeException
{
}
```

- [ ] **Step 7: 创建 Guard 的公共结构**

新建 `app/payment/EpayUpstreamGuard.php`，使用以下结构：

```php
<?php

declare(strict_types=1);

namespace app\payment;

use Closure;

final class EpayUpstreamGuard
{
    public const REJECTED_MESSAGE =
        '外部易支付上游不能指向当前 CXPAY 实例';

    /** @var Closure(string):list<string> */
    private Closure $resolver;

    /**
     * @param null|Closure(string):list<string> $resolver
     */
    public function __construct(
        ?Closure $resolver = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $serviceHost = null,
        private readonly ?int $servicePort = null
    ) {
        $this->resolver = $resolver
            ?? static fn(string $host): array => self::resolveDns($host);
    }

    /**
     * @return array{scheme:string,host:string,port:int,ip:string}
     */
    public function validate(string $apiUrl): array
    {
        $target = $this->parseEndpoint($apiUrl);
        $current = $this->parseEndpoint($this->currentAppUrl());

        if ($target['host'] === $current['host']) {
            $this->reject();
        }

        $targetIps = $this->publicAddresses($target['host']);
        $currentIps = $this->publicAddresses($current['host']);

        if ($target['port'] === $current['port']
            && array_intersect($targetIps, $currentIps) !== []
        ) {
            $this->reject();
        }

        $this->assertNotServiceEndpoint(
            $target['host'],
            $target['port'],
            $targetIps
        );

        return [
            'scheme' => $target['scheme'],
            'host' => $target['host'],
            'port' => $target['port'],
            'ip' => $targetIps[0],
        ];
    }
}
```

- [ ] **Step 8: 实现 URL 解析和环境默认值**

在同一类中加入：

```php
/** @return array{scheme:string,host:string,port:int} */
private function parseEndpoint(string $url): array
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        $this->reject();
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = rtrim(strtolower((string)($parts['host'] ?? '')), '.');
    if (!in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || $host === 'localhost'
    ) {
        $this->reject();
    }

    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443], true)) {
        $this->reject();
    }

    return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
}

private function currentAppUrl(): string
{
    return $this->appUrl ?? (string)config('app.url', '');
}

private function currentServiceHost(): string
{
    return rtrim(strtolower(
        $this->serviceHost ?? (string)env('HOST', '127.0.0.1')
    ), '.');
}

private function currentServicePort(): int
{
    return $this->servicePort ?? (int)env('PORT', 8787);
}
```

- [ ] **Step 9: 实现 DNS、公网和服务端点规则**

在同一类中加入：

```php
/** @return list<string> */
private function publicAddresses(string $host): array
{
    $addresses = filter_var($host, FILTER_VALIDATE_IP)
        ? [$host]
        : ($this->resolver)($host);

    $normalized = [];
    foreach ($addresses as $address) {
        $packed = @inet_pton((string)$address);
        if ($packed === false) {
            $this->reject();
        }

        $ip = inet_ntop($packed);
        if (!is_string($ip) || !$this->isPublicIp($ip)) {
            $this->reject();
        }

        $normalized[] = strtolower($ip);
    }

    $normalized = array_values(array_unique($normalized));
    if ($normalized === []) {
        $this->reject();
    }

    return $normalized;
}

private function assertNotServiceEndpoint(
    string $targetHost,
    int $targetPort,
    array $targetIps
): void {
    $serviceHost = $this->currentServiceHost();
    $servicePort = $this->currentServicePort();

    if ($targetPort !== $servicePort) {
        return;
    }

    if ($serviceHost !== '' && $targetHost === $serviceHost) {
        $this->reject();
    }

    if (filter_var($serviceHost, FILTER_VALIDATE_IP)) {
        $packed = @inet_pton($serviceHost);
        $normalized = $packed === false ? false : inet_ntop($packed);
        if (is_string($normalized)
            && in_array(strtolower($normalized), $targetIps, true)
        ) {
            $this->reject();
        }
    }
}

private function isPublicIp(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/** @return list<string> */
private static function resolveDns(string $host): array
{
    $addresses = [];
    foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
        $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip !== '') {
            $addresses[] = $ip;
        }
    }

    return array_values(array_unique($addresses));
}

private function reject(): never
{
    throw new EpayUpstreamException(self::REJECTED_MESSAGE);
}
```

严格处理混合 DNS：只要返回集合中出现无效、私网或保留地址，整个目标都拒绝。

- [ ] **Step 10: 运行 Guard 测试**

```bash
php -l app/payment/EpayUpstreamException.php
php -l app/payment/EpayUpstreamGuard.php
php vendor/bin/phpunit --colors=never tests/Unit/EpayUpstreamGuardTest.php
```

Expected: PASS。

- [ ] **Step 11: 提交 Task 2**

```bash
git add \
  app/payment/EpayUpstreamException.php \
  app/payment/EpayUpstreamGuard.php \
  tests/Unit/EpayUpstreamGuardTest.php
git diff --cached --check
git commit -m "feat: block epay upstream self routing"
```

---

### Task 3: 接入 `epay_generic` 并明确外部上游定位

**Files:**
- Modify: `app/payment/Drivers/EpayGeneric/Driver.php`
- Create: `tests/Unit/EpayGenericDriverTest.php`

**Interfaces:**
- Consumes: `EpayUpstreamGuard::validate()`。
- Produces: `Driver::__construct(?EpayUpstreamGuard $guard = null, ?Closure $httpPost = null)`。
- HTTP closure contract: `Closure(string $url, array $data, array $target): string|false`。
- Produces metadata title: `外部易支付上游（可选）`。
- Produces metadata description: `将 CXPAY 订单转发给第三方易支付兼容平台；不是 CXPAY 对下游商户提供的易支付接口。`

- [ ] **Step 1: 创建驱动测试辅助方法**

新建 `tests/Unit/EpayGenericDriverTest.php`：

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Drivers\EpayGeneric\Driver;
use app\payment\EpayUpstreamException;
use app\payment\EpayUpstreamGuard;
use Closure;
use PHPUnit\Framework\TestCase;
use support\Sign;

final class EpayGenericDriverTest extends TestCase
{
    private function guard(): EpayUpstreamGuard
    {
        $records = [
            'pay.example.test' => ['8.8.8.8'],
            'upstream.example.test' => ['1.1.1.1'],
        ];

        return new EpayUpstreamGuard(
            static fn(string $host): array => $records[$host] ?? [],
            'https://pay.example.test',
            '127.0.0.1',
            8787
        );
    }

    private function params(string $type = 'alipay'): array
    {
        return [
            'type' => $type,
            'trade_no' => 'CX202608050001',
            'out_trade_no' => 'MERCHANT-001',
            'notify_url' => 'https://merchant.example.test/notify',
            'return_url' => 'https://merchant.example.test/return',
            'name' => '测试订单',
            'money' => '0.01',
        ];
    }

    private function config(string $mode = 'submit'): array
    {
        return [
            'api_url' => 'https://upstream.example.test',
            'pid' => '10001',
            'key' => 'test-secret',
            'mode' => $mode,
        ];
    }
}
```

- [ ] **Step 2: 写元数据和三支付类型红灯测试**

加入：

```php
public function testMetadataClarifiesOptionalExternalRole(): void
{
    $meta = (new Driver($this->guard()))->getMeta();

    self::assertSame('epay_generic', $meta['name']);
    self::assertSame('外部易支付上游（可选）', $meta['title']);
    self::assertStringContainsString('第三方', $meta['description']);
    self::assertStringContainsString('不是 CXPAY 对下游商户', $meta['description']);
}

public function testSubmitModeSupportsAllThreePaymentTypes(): void
{
    $driver = new Driver($this->guard());

    foreach (['alipay', 'wxpay', 'qqpay'] as $type) {
        $result = $driver->pay($this->params($type), $this->config());
        self::assertSame('url', $result['type']);

        $query = [];
        parse_str((string)parse_url($result['pay_url'], PHP_URL_QUERY), $query);
        self::assertSame($type, $query['type']);
        self::assertTrue(Sign::verifySign($query, 'test-secret'));
    }
}
```

- [ ] **Step 3: 写保存和运行期安全拒绝红灯测试**

加入：

```php
public function testUpchannelRejectsCurrentCxpayInstance(): void
{
    $driver = new Driver($this->guard());
    $config = $this->config();
    $config['api_url'] = 'https://pay.example.test';

    self::assertSame([
        'code' => -1,
        'msg' => '外部易支付上游不能指向当前 CXPAY 实例',
    ], $driver->upchannel([], $config));
}

public function testPaySafetyFailureIsNotConvertedToSubmitUrl(): void
{
    $driver = new Driver($this->guard());
    $config = $this->config('mapi');
    $config['api_url'] = 'https://pay.example.test';

    $this->expectException(EpayUpstreamException::class);
    $driver->pay($this->params(), $config);
}
```

- [ ] **Step 4: 写 MAPI 固定目标与普通网络回退红灯测试**

加入：

```php
public function testMapiUsesValidatedTargetAndReturnsQrcode(): void
{
    $captured = null;
    $transport = static function (
        string $url,
        array $data,
        array $target
    ) use (&$captured): string {
        $captured = compact('url', 'data', 'target');
        return json_encode([
            'code' => 1,
            'qrcode' => 'https://qr.example.test/code',
        ], JSON_THROW_ON_ERROR);
    };

    $driver = new Driver($this->guard(), $transport);
    $result = $driver->pay($this->params('qqpay'), $this->config('mapi'));

    self::assertSame('qrcode', $result['type']);
    self::assertSame('https://qr.example.test/code', $result['pay_url']);
    self::assertSame([
        'scheme' => 'https',
        'host' => 'upstream.example.test',
        'port' => 443,
        'ip' => '1.1.1.1',
    ], $captured['target']);
    self::assertSame(
        'https://upstream.example.test/mapi.php',
        $captured['url']
    );
}

public function testMapiOrdinaryNetworkFailureFallsBackToValidatedSubmit(): void
{
    $transport = static function (): string|false {
        throw new \RuntimeException('temporary network failure');
    };

    $driver = new Driver($this->guard(), $transport);
    $result = $driver->pay($this->params(), $this->config('mapi'));

    self::assertSame('url', $result['type']);
    self::assertStringStartsWith(
        'https://upstream.example.test/submit.php?',
        $result['pay_url']
    );
}
```

- [ ] **Step 5: 写回调验签不回归测试**

加入：

```php
public function testNotifyMd5VerificationRemainsCompatible(): void
{
    $params = [
        'pid' => '10001',
        'out_trade_no' => 'CX202608050001',
        'trade_no' => 'UPSTREAM-001',
        'money' => '0.01',
        'trade_status' => 'TRADE_SUCCESS',
    ];
    $params['sign'] = Sign::makeSign($params, 'test-secret');
    $params['sign_type'] = 'MD5';

    $result = (new Driver($this->guard()))->notify(
        $params,
        $this->config()
    );

    self::assertTrue($result['success']);
    self::assertSame('CX202608050001', $result['out_trade_no']);
    self::assertSame(0.01, $result['amount']);
}
```

- [ ] **Step 6: 运行红灯测试**

```bash
php vendor/bin/phpunit --colors=never tests/Unit/EpayGenericDriverTest.php
```

Expected: 当前构造器不接受依赖，元数据标题不匹配，且运行期没有严格回环保护。

- [ ] **Step 7: 增加构造器与 Guard 访问器**

在 `Driver` 中增加 import：

```php
use app\payment\EpayUpstreamException;
use app\payment\EpayUpstreamGuard;
use Closure;
```

删除：

```php
use support\UrlGuard;
```

在类开头增加：

```php
/**
 * @param null|Closure(string,array,array):string|false $httpPost
 */
public function __construct(
    private readonly ?EpayUpstreamGuard $guard = null,
    private readonly ?Closure $httpPost = null
) {
}

private function upstreamGuard(): EpayUpstreamGuard
{
    return $this->guard ?? new EpayUpstreamGuard();
}
```

无参实例化必须继续可用，以保持 `PaymentManager` 自动发现。

- [ ] **Step 8: 在 pay() 最前面验证目标**

在读取 `$apiUrl`、`$pid`、`$key`、`$mode`、`$type` 后，在生成任何 URL 前加入：

```php
$target = $this->upstreamGuard()->validate($apiUrl);
```

构建签名数据和 `$submitUrl` 的现有逻辑保持不变。

MAPI 分支替换为：

```php
if ($mode === 'mapi') {
    try {
        $res = $this->httpPost !== null
            ? ($this->httpPost)(
                $apiUrl . '/mapi.php',
                $payData,
                $target
            )
            : $this->postForm(
                $apiUrl . '/mapi.php',
                $payData,
                $target
            );

        if ($res) {
            $json = json_decode($res, true);
            if (($json['code'] ?? 0) == 1) {
                return [
                    'type' => !empty($json['qrcode']) ? 'qrcode' : 'url',
                    'trade_no' => $params['trade_no'],
                    'out_trade_no' => $params['out_trade_no'],
                    'amount' => $params['money'],
                    'pay_url' =>
                        $json['qrcode'] ?? $json['payurl'] ?? $submitUrl,
                ];
            }
        }
    } catch (EpayUpstreamException $e) {
        throw $e;
    } catch (\Throwable) {
        // 只有普通网络或上游响应异常允许回退 submit。
    }
}
```

Guard 在 try 之前执行，因此安全拒绝不会落入普通回退路径。

- [ ] **Step 9: 让 postForm() 只使用已验证目标**

修改签名：

```php
/**
 * @param array{scheme:string,host:string,port:int,ip:string} $target
 */
private function postForm(
    string $url,
    array $data,
    array $target
): string|false
```

删除内部的 `UrlGuard::resolve($url)`。保留 cURL 参数，并改用传入目标：

```php
$resolvedIp = str_contains($target['ip'], ':')
    ? '[' . $target['ip'] . ']'
    : $target['ip'];

CURLOPT_RESOLVE => [
    "{$target['host']}:{$target['port']}:{$resolvedIp}",
],
```

继续保持：

```php
CURLOPT_FOLLOWLOCATION => false,
CURLOPT_SSL_VERIFYPEER => true,
CURLOPT_SSL_VERIFYHOST => 2,
```

- [ ] **Step 10: 在 upchannel() 保存前执行 Guard**

保留现有必填、URL scheme 和 mode 检查。通过这些基础检查后加入：

```php
try {
    $this->upstreamGuard()->validate((string)$config['api_url']);
} catch (EpayUpstreamException) {
    return [
        'code' => -1,
        'msg' => EpayUpstreamGuard::REJECTED_MESSAGE,
    ];
}
```

成功时仍返回原 `$config`。

- [ ] **Step 11: 修改公开元数据**

`getMeta()` 中精确修改：

```php
'title' => '外部易支付上游（可选）',
'description' =>
    '将 CXPAY 订单转发给第三方易支付兼容平台；'
    . '不是 CXPAY 对下游商户提供的易支付接口。',
```

`name` 保持 `epay_generic`，inputs 和 available 保持不变。

- [ ] **Step 12: 运行驱动与公开列表测试**

```bash
php -l app/payment/Drivers/EpayGeneric/Driver.php
php vendor/bin/phpunit --colors=never \
  tests/Unit/EpayGenericDriverTest.php \
  tests/Unit/EpayUpstreamGuardTest.php \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AdminChannelPageTest.php
```

Expected: PASS。

- [ ] **Step 13: 提交 Task 3**

```bash
git add \
  app/payment/Drivers/EpayGeneric/Driver.php \
  tests/Unit/EpayGenericDriverTest.php
git diff --cached --check
git commit -m "feat: secure optional external epay upstream"
```

---

### Task 4: 扩展安全迁移并记录替代归档原因

**Files:**
- Modify: `app/service/LegacyPaymentDriverCleanupService.php`
- Modify: `tests/Integration/LegacyPaymentDriverCleanupServiceTest.php`
- Modify: `docs/runbooks/remove-legacy-payment-drivers.md`

**Interfaces:**
- Consumes: `RemovedPaymentDrivers::all()` 自动包含 `qqpay_epay`。
- Produces: `qqpay_epay` 的 `archive_reason` 为 `superseded_by_epay_generic`。
- Produces: 其他五个旧驱动继续使用 `removed_placeholder_or_shared_token_driver`。
- Preserves: dry-run、待支付订单阻断、事务回滚和幂等行为。

- [ ] **Step 1: 写归档原因红灯测试**

在 `LegacyPaymentDriverCleanupServiceTest` 新增：

```php
public function testQqpayEpayUsesReplacementArchiveReason(): void
{
    $this->db->table('cx_pay_channel')->insert([
        'id' => 13,
        'merchant_id' => 0,
        'pay_category' => 'qqpay',
        'title' => '重复QQ易支付上游',
        'c_type' => 'qqpay_epay',
        'remark' => '',
        'config' => '{"pid":"secret","key":"must-not-archive"}',
        'weight' => 50,
        'single_min' => 0,
        'single_max' => 5000,
        'day_max' => 10000,
        'today_money' => 0,
        'today_count' => 0,
        'total_money' => 0,
        'online_status' => 0,
        'status' => 0,
    ]);

    $service = new LegacyPaymentDriverCleanupService($this->db);
    $service->ensureArchiveTable();
    $result = $service->apply();

    self::assertSame(3, $result['archived']);
    self::assertSame(
        'superseded_by_epay_generic',
        $this->db->table('cx_pay_channel_archive')
            ->where('original_channel_id', 13)
            ->value('archive_reason')
    );
    self::assertFalse(
        $this->db->getSchemaBuilder()
            ->hasColumn('cx_pay_channel_archive', 'config')
    );
}
```

现有 seed 中两个目标也会在同一次 apply 中被归档，所以预期 `archived=3`。

- [ ] **Step 2: 写旧原因保持不变测试**

在现有主流程测试归档后加入：

```php
self::assertSame(
    ['removed_placeholder_or_shared_token_driver'],
    $this->db->table('cx_pay_channel_archive')
        ->whereIn('original_channel_id', [10, 11])
        ->distinct()
        ->pluck('archive_reason')
        ->values()
        ->all()
);
```

- [ ] **Step 3: 运行红灯测试**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: `qqpay_epay` 当前会写入旧的统一原因。

- [ ] **Step 4: 按代码选择归档原因**

把服务中的常量替换为：

```php
private const DEFAULT_ARCHIVE_REASON =
    'removed_placeholder_or_shared_token_driver';

/** @var array<string,string> */
private const ARCHIVE_REASONS = [
    'qqpay_epay' => 'superseded_by_epay_generic',
];
```

增加：

```php
private function archiveReason(string $cType): string
{
    return self::ARCHIVE_REASONS[$cType]
        ?? self::DEFAULT_ARCHIVE_REASON;
}
```

归档 insert 中改为：

```php
'archive_reason' => $this->archiveReason(
    (string)$channel['c_type']
),
```

其余事务顺序和计数不得改变。

- [ ] **Step 5: 更新操作手册驱动清单与原因**

在 `docs/runbooks/remove-legacy-payment-drivers.md` 的永久移除列表加入：

```text
- `qqpay_epay`（由 `epay_generic` 替代）
```

更新 SQL 残留检查的 `IN (...)`，加入：

```sql
'qqpay_epay'
```

新增说明：

```text
`qqpay_epay` 的归档原因应为 `superseded_by_epay_generic`；
其他五个旧驱动继续使用
`removed_placeholder_or_shared_token_driver`。
```

明确说明迁移曾执行过也必须再次 dry-run，因为墓碑名单已扩展。

- [ ] **Step 6: 运行迁移与文档定向验证**

```bash
php -l app/service/LegacyPaymentDriverCleanupService.php
php -l database/migrations/20260805_remove_legacy_payment_drivers.php
git diff --check
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php \
  tests/Unit/PaymentManagerTest.php
```

Expected: PASS。

- [ ] **Step 7: 提交 Task 4**

```bash
git add \
  app/service/LegacyPaymentDriverCleanupService.php \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php \
  docs/runbooks/remove-legacy-payment-drivers.md
git diff --cached --check
git commit -m "feat: archive superseded qqpay epay channels"
```

---

### Task 5: 完整验证、生产清理和浏览器验收

**Files:**
- Verify all files from Tasks 1–4.
- Modify only a file named by a failing test, syntax check, reference audit, or browser acceptance failure.

**Interfaces:**
- Produces: 完整 PHPUnit 通过。
- Produces: 生产活动表中没有 `qqpay_epay`。
- Produces: 公开驱动总数为 5。
- Produces: `epay_generic` 显示新名称，且默认没有活动通道实例。
- Produces: Workerman 进程正常。

- [ ] **Step 1: 同步并确认精确分支状态**

```bash
cd /www/wwwroot/cs.fcwan.cn
git branch --show-current
git status --short
git log -8 --oneline
```

Expected: 分支为 `fix/p0-hardening`。允许的未跟踪文件仅为：

```text
CXPAY.rar
cxpay-webman.supervisor.conf
install.lock
```

- [ ] **Step 2: 检查全部 PHP 语法和差异格式**

```bash
find app config process support tests database/migrations \
  -type f -name '*.php' -print0 \
  | xargs -0 -n1 php -l
git diff --check
```

Expected: 全部无语法错误，无空白错误。

- [ ] **Step 3: 运行定向测试**

```bash
target_tests=(
  tests/Unit/EpayUpstreamGuardTest.php
  tests/Unit/EpayGenericDriverTest.php
  tests/Unit/PaymentManagerTest.php
  tests/Unit/PluginPackageInstallerTest.php
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
  tests/Unit/AdminChannelPageTest.php
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
)
php vendor/bin/phpunit --colors=never "${target_tests[@]}"
```

Expected: PASS。

- [ ] **Step 4: 运行完整回归**

```bash
php vendor/bin/phpunit --colors=never
```

Expected: `OK`。记录本次实际 tests 和 assertions 数量，不沿用旧数字。

- [ ] **Step 5: 审计驱动和防护引用**

```bash
test ! -e app/payment/Drivers/QqpayEpay/Driver.php

grep -RIn --exclude-dir=.git --exclude='*.md' \
  'qqpay_epay\|QqpayEpay' app public config database tests || true

grep -RIn \
  '外部易支付上游（可选）\|EpayUpstreamGuard\|CURLOPT_RESOLVE' \
  app tests
```

Expected:

- `qqpay_epay` 只出现在墓碑、迁移测试和明确契约测试中；
- 不存在重复驱动实现；
- `epay_generic` 同时在保存和下单路径调用 Guard；
- cURL 继续使用 `CURLOPT_RESOLVE`。

- [ ] **Step 6: 创建生产数据库备份**

在宝塔面板创建当前数据库完整备份并确认备份文件存在。记录：

```bash
git rev-parse HEAD
date '+%F %T %z'
```

不得在没有备份证据时执行 `--apply`。

- [ ] **Step 7: 执行迁移 dry-run**

```bash
php database/migrations/20260805_remove_legacy_payment_drivers.php
```

核对：

- 目标列表中只能出现墓碑名单内代码；
- 重点检查 `qqpay_epay` 的 ID、merchant_id 和标题；
- `pending_orders=0` 才能继续；
- 当前部署预期很可能 `channel_count=0`，但必须以实际输出为准。

- [ ] **Step 8: 有目标时停止服务并应用；无目标时跳过 DML**

当 dry-run 的 `channel_count` 大于零且 `pending_orders=0`：

```bash
php start.php stop
php start.php status
php database/migrations/20260805_remove_legacy_payment_drivers.php --apply
php database/migrations/20260805_remove_legacy_payment_drivers.php
php start.php start -d
php start.php status
```

成功条件：

```text
APPLY completed successfully
remaining=0
```

第二次 dry-run 必须显示：

```text
channel_count=0
poll_group_links=0
plans_to_update=0
pending_orders=0
```

当第一次 dry-run 已经 `channel_count=0` 时，不执行 `--apply`，只需记录零结果。

- [ ] **Step 9: 重启服务加载新 PHP 代码**

即使迁移无目标，也要重启 Workerman：

```bash
php start.php restart -d
php start.php status
```

若当前 Workerman 版本不接受 `restart -d`，使用：

```bash
php start.php stop
php start.php start -d
php start.php status
```

Expected: `CXPAY` 4 个进程、`channel_timer` 1 个进程，`exit_status=0`。

- [ ] **Step 10: 浏览器强制刷新验收**

使用 `Ctrl+F5` 检查：

1. “已安装支付驱动”不显示 `qqpay_epay`。
2. 页面公开驱动总数显示 5。
3. `epay_generic` 显示“外部易支付上游（可选）”。
4. 描述明确“第三方上游”且“不是下游接口”。
5. 收款通道配置页不会自动出现 `epay_generic` 通道实例。
6. 手工保存本站 `APP_URL` 为 `api_url` 时返回拒绝消息。
7. 合法外部测试域名配置在测试环境中能够保存；生产环境没有真实外部上游时保持未创建、未启用。

不得为了浏览器测试把生产 CXPAY 自身域名配置为外部上游。

- [ ] **Step 11: 验证最终 Git 状态并提交必要修正**

```bash
git status --short
git diff --check
```

若验证没有产生跟踪文件修改，不创建空提交。

若测试或验收产生了必要修正，逐个列出并精确暂存，然后：

```bash
git commit -m "test: enforce external epay upstream contracts"
```

- [ ] **Step 12: 推送并核对远端**

```bash
git push origin fix/p0-hardening
git rev-parse HEAD
git status --short
```

Expected: 本地与 `origin/fix/p0-hardening` 指向同一提交；工作区只剩三个预期未跟踪文件。

- [ ] **Step 13: 更新 PR 验证记录**

在 PR #2 中记录：

- `qqpay_epay` 已永久移除；
- `epay_generic` 已重新定位；
- 回环保护规则；
- 定向测试结果；
- 完整测试的实际 tests/assertions 数量；
- 迁移 dry-run/apply/第二次 dry-run 输出；
- 浏览器公开驱动数量为 5；
- Workerman 进程状态。

只有全部证据齐全后，才评估把 PR 从 Draft 改为 Ready for review。
