# Remove Legacy Payment Drivers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permanently remove five placeholder or shared-Token payment drivers from discovery, installation, configuration UIs, active channel data, and package bindings while preserving non-sensitive audit metadata and historical transaction references.

**Architecture:** `RemovedPaymentDrivers` is the single immutable tombstone policy. `PaymentManager`, plugin installation, channel/package controllers, UI contracts, and the cleanup migration all consume that policy. Database cleanup is split into idempotent archive-table DDL followed by one DML transaction; the CLI defaults to dry-run and requires `--apply`.

**Tech Stack:** PHP 8.2, Webman, Illuminate Database/Eloquent, MySQL 5.7+/8.0, SQLite for integration tests, PHPUnit 10, HTML/vanilla JavaScript.

## Global Constraints

- Work only on `fix/p0-hardening`; keep PR #2 Draft during implementation.
- Permanently remove exactly `alipay_official`, `wxpay_official`, `alipay_scan_bill`, `wxpay_protocol_cloud`, and `qqpay_protocol_cloud`.
- Keep `sandbox_test` unchanged.
- Delete the five driver implementation files; hiding them only in JavaScript is insufficient.
- Reject future built-in or plugin registration using a removed code.
- Never archive `cx_pay_channel.config` or any Token, Cookie, private key, or secret.
- Preserve `cx_order.channel_id`, `cx_callbill.channel_id`, and historical statuses unchanged.
- Abort cleanup while any `cx_order.status = 0` row references a target channel.
- Run archive-table DDL before transactional DML because MySQL DDL implicitly commits.
- Migration defaults to dry-run; data changes require the exact `--apply` argument.
- The DML phase must be idempotent and must fully roll back on failure.
- On the test server, preserve `CXPAY.rar`, `cxpay-webman.supervisor.conf`, and `install.lock`; never run `git clean -fd`.
- Do not claim GitHub Actions is green without executable jobs and logs.

## File Map

**Create**

- `app/payment/RemovedPaymentDrivers.php`
- `app/service/LegacyPaymentDriverCleanupService.php`
- `database/migrations/20260805_remove_legacy_payment_drivers.php`
- `tests/Integration/LegacyPaymentDriverCleanupServiceTest.php`
- `tests/Unit/RemovedPaymentDriverFrontendContractTest.php`
- `docs/runbooks/remove-legacy-payment-drivers.md`

**Modify**

- `app/payment/PaymentManager.php`
- `app/payment/Plugin/PluginPackageInstaller.php`
- `app/controller/admin/PluginMarketController.php`
- `app/controller/admin/AdminController.php`
- `app/controller/api/MerchantChannelController.php`
- `app/controller/admin/PackvipAdminController.php`
- `public/admin/index.html`
- `database/install.sql`
- `tests/Unit/PaymentManagerTest.php`
- `tests/Unit/AlipayScanMonitorPluginTest.php`
- `tests/Unit/PluginPackageInstallerTest.php`

**Delete**

- `app/payment/Drivers/AlipayOfficial/Driver.php`
- `app/payment/Drivers/WxpayOfficial/Driver.php`
- `app/payment/Drivers/AlipayScanBill/Driver.php`
- `app/payment/Drivers/WxpayProtocolCloud/Driver.php`
- `app/payment/Drivers/QqpayProtocolCloud/Driver.php`

---

### Task 1: Add the tombstone policy and fail-closed driver manager

**Files:**
- Create: `app/payment/RemovedPaymentDrivers.php`
- Modify: `app/payment/PaymentManager.php`
- Modify: `tests/Unit/PaymentManagerTest.php`
- Modify: `tests/Unit/AlipayScanMonitorPluginTest.php`

**Interfaces:**
- Produces `RemovedPaymentDrivers::all(): array`, `contains(string): bool`, `assertAllowed(string): void`, and `stripCsv(string): string`.
- Produces `PaymentManager::has($removedCode) === false`.
- `make()`, `register()`, and `registerPluginDriver()` throw `InvalidArgumentException` containing `已永久移除` for removed codes.

- [ ] **Step 1: Write failing manager tests**

Add to `PaymentManagerTest`:

```php
protected function setUp(): void
{
    PaymentManager::flush();
}

#[DataProvider('removedDriverProvider')]
public function testRemovedDriverIsPermanentlyUnavailable(string $driverName): void
{
    self::assertFalse(PaymentManager::has($driverName));
    self::assertArrayNotHasKey($driverName, PaymentManager::getRegisteredDrivers());

    try {
        PaymentManager::make($driverName);
        self::fail('Removed driver must not be instantiated');
    } catch (InvalidArgumentException $e) {
        self::assertStringContainsString('已永久移除', $e->getMessage());
    }
}

public function testRemovedDriverCannotBeRegisteredAsBuiltin(): void
{
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('已永久移除');
    PaymentManager::register('alipay_official', FakePluginPaymentDriver::class);
}

public function testRemovedDriverCannotBeRegisteredByPlugin(): void
{
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('已永久移除');
    PaymentManager::registerPluginDriver(
        'wxpay_protocol_cloud',
        FakePluginPaymentDriver::class,
        'cxpay.wxpay.retired'
    );
}

public static function removedDriverProvider(): array
{
    return [
        '支付宝官方占位' => ['alipay_official'],
        '微信官方占位' => ['wxpay_official'],
        '支付宝旧共享Token' => ['alipay_scan_bill'],
        '微信旧共享Token' => ['wxpay_protocol_cloud'],
        'QQ旧共享Token' => ['qqpay_protocol_cloud'],
    ];
}
```

Remove the three shared-Token drivers from `personalQrDriverProvider()`. Remove `merchantDriverProvider()`, `testMerchantPaymentDriverCannotBeSelected()`, and the single-driver `testDisabledMerchantDriverFailsClosed()` because all five behaviors are now covered by one provider.

In `AlipayScanMonitorPluginTest::testManifestDeclaresPersonalQrCallbackPlugin()`, replace the deprecated-driver assertion with:

```php
self::assertFalse(PaymentManager::has('alipay_scan_bill'));
self::assertArrayNotHasKey('alipay_scan_bill', PaymentManager::getRegisteredDrivers());
```

- [ ] **Step 2: Verify the tests fail before implementation**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php
```

Expected: removed drivers are still discoverable or do not produce the permanent-removal exception.

- [ ] **Step 3: Create `RemovedPaymentDrivers.php`**

```php
<?php

declare(strict_types=1);

namespace app\payment;

use InvalidArgumentException;

final class RemovedPaymentDrivers
{
    /** @var list<string> */
    private const CODES = [
        'alipay_official',
        'wxpay_official',
        'alipay_scan_bill',
        'wxpay_protocol_cloud',
        'qqpay_protocol_cloud',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::CODES;
    }

    public static function contains(string $cType): bool
    {
        return in_array(trim($cType), self::CODES, true);
    }

    public static function assertAllowed(string $cType): void
    {
        if (self::contains($cType)) {
            throw new InvalidArgumentException("支付驱动已永久移除: {$cType}");
        }
    }

    public static function stripCsv(string $csv): string
    {
        $kept = [];
        foreach (explode(',', $csv) as $value) {
            $code = trim($value);
            if ($code !== '' && !self::contains($code)) {
                $kept[] = $code;
            }
        }
        return implode(',', $kept);
    }
}
```

- [ ] **Step 4: Enforce the policy in every `PaymentManager` entrypoint**

Make these exact changes:

- Call `RemovedPaymentDrivers::assertAllowed($cType)` as the first statement in `register()`, `registerPluginDriver()`, and `make()`.
- Return `false` before discovery in `has()` when `contains($cType)` is true.
- In `discoverDrivers()`, do not add metadata whose `name` is empty or removed.
- In `getRegisteredDrivers()`, skip a removed key before plugin-enabled and class-availability checks.
- Keep `flush()`, normal plugin conflict checks, and non-removed driver behavior unchanged.

- [ ] **Step 5: Run focused tests**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php
```

Expected: pass.

- [ ] **Step 6: Commit Task 1**

```bash
git add \
  app/payment/RemovedPaymentDrivers.php \
  app/payment/PaymentManager.php \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php
git commit -m "refactor: tombstone removed payment drivers"
```

---

### Task 2: Reject removed codes in signed plugin packages and listings

**Files:**
- Modify: `app/payment/Plugin/PluginPackageInstaller.php`
- Modify: `app/controller/admin/PluginMarketController.php`
- Modify: `tests/Unit/PluginPackageInstallerTest.php`

**Interfaces:**
- Consumes `RemovedPaymentDrivers::contains()`.
- A validly signed package using a removed driver code fails before staging or registry writes.
- Stale installed-plugin registry entries using a removed code are omitted from `getMarketList()`.

- [ ] **Step 1: Write the failing installer test**

Change the helper signature to:

```php
private function createPackage(bool $tamper, string $driverCode = 'wxpay_signed_demo'): string
```

Use `$driverCode` for `manifest.drivers[0].code`, then add:

```php
public function testRejectsValidlySignedPackageUsingRemovedDriverCode(): void
{
    $package = $this->createPackage(false, 'wxpay_protocol_cloud');

    $this->expectException(PluginException::class);
    $this->expectExceptionMessage('已永久移除');
    $this->installer()->install($package);
}
```

- [ ] **Step 2: Verify red state**

```bash
php vendor/bin/phpunit --colors=never tests/Unit/PluginPackageInstallerTest.php
```

Expected: the signed package installs because no tombstone check exists.

- [ ] **Step 3: Enforce the policy after signature verification**

Import `app\payment\RemovedPaymentDrivers`. Preserve this order in `install()`:

```php
$manifest = PluginManifest::fromJson($files['manifest.json']);
$this->verifySignature($manifest, $files['signature.json'], $files);
foreach ($manifest->drivers() as $driver) {
    $code = trim((string)($driver['code'] ?? ''));
    if (RemovedPaymentDrivers::contains($code)) {
        throw new PluginException("插件声明了已永久移除的支付驱动: {$code}");
    }
}
$this->assertDriverFilesDeclared($manifest, $files);
```

- [ ] **Step 4: Filter stale installed-plugin entries**

Import `RemovedPaymentDrivers` in `PluginMarketController`. In the installed-plugin driver loop:

```php
$cType = trim((string)($driver['code'] ?? ''));
if ($cType !== '' && RemovedPaymentDrivers::contains($cType)) {
    continue;
}
```

Assign `'c_type' => $cType` in the response entry.

- [ ] **Step 5: Run focused tests**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PluginPackageInstallerTest.php \
  tests/Unit/PaymentManagerTest.php
```

Expected: pass.

- [ ] **Step 6: Commit Task 2**

```bash
git add \
  app/payment/Plugin/PluginPackageInstaller.php \
  app/controller/admin/PluginMarketController.php \
  tests/Unit/PluginPackageInstallerTest.php
git commit -m "fix: block retired payment plugin codes"
```

---

### Task 3: Reject removed codes in channel and package saves

**Files:**
- Modify: `app/controller/admin/AdminController.php`
- Modify: `app/controller/api/MerchantChannelController.php`
- Modify: `app/controller/admin/PackvipAdminController.php`
- Modify: `tests/Unit/PaymentManagerTest.php`

**Interfaces:**
- Channel save returns an explicit permanent-removal error.
- Package save rejects exact removed codes but keeps category values such as `alipay`, `wxpay`, and `qqpay` valid.

- [ ] **Step 1: Lock CSV cleanup behavior**

Import `RemovedPaymentDrivers` and add:

```php
public function testRemovedDriverCsvCleanupPreservesOtherValuesAndOrder(): void
{
    self::assertSame(
        'alipay,qqpay_app_asst,wxpay',
        RemovedPaymentDrivers::stripCsv(
            'alipay,alipay_official,qqpay_app_asst,wxpay_protocol_cloud,wxpay'
        )
    );
}
```

Run:

```bash
php vendor/bin/phpunit --colors=never tests/Unit/PaymentManagerTest.php
```

Expected: pass, fixing the helper contract before controller use.

- [ ] **Step 2: Add explicit admin rejection**

Import `RemovedPaymentDrivers` in `AdminController`. Immediately after `$cType` is trimmed in `saveChannelConfig()`:

```php
if (RemovedPaymentDrivers::contains($cType)) {
    return json_encode([
        'code' => -1,
        'msg' => '该支付驱动已永久移除，不能创建或修改通道',
    ], JSON_UNESCAPED_UNICODE);
}
```

- [ ] **Step 3: Add explicit merchant rejection**

Import `RemovedPaymentDrivers` in `MerchantChannelController`. Immediately after `$cType` is trimmed in `save()`:

```php
if (RemovedPaymentDrivers::contains($cType)) {
    return json([
        'code' => -1,
        'msg' => '该支付驱动已永久移除，不能创建或修改通道',
    ]);
}
```

- [ ] **Step 4: Normalize and reject package bindings**

Import `RemovedPaymentDrivers` in `PackvipAdminController`. Replace the existing `$allowedCh` assignment with:

```php
$submittedAllowed = $request->post('allowed_channels');
$allowedList = is_array($submittedAllowed)
    ? $submittedAllowed
    : explode(',', (string)$submittedAllowed);
$allowedList = array_values(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    $allowedList
), static fn(string $value): bool => $value !== ''));

$removed = array_values(array_filter(
    $allowedList,
    static fn(string $code): bool => RemovedPaymentDrivers::contains($code)
));
if ($removed !== []) {
    return json([
        'code' => -1,
        'msg' => '套餐包含已永久移除的支付驱动：' . implode(', ', $removed),
    ]);
}
$allowedCh = implode(',', $allowedList);
```

Do not require `PaymentManager::has()` for category values because current plans store category-level compatibility values.

- [ ] **Step 5: Lint and test**

```bash
php -l app/controller/admin/AdminController.php
php -l app/controller/api/MerchantChannelController.php
php -l app/controller/admin/PackvipAdminController.php
php vendor/bin/phpunit --colors=never tests/Unit/PaymentManagerTest.php
```

Expected: pass.

- [ ] **Step 6: Commit Task 3**

```bash
git add \
  app/controller/admin/AdminController.php \
  app/controller/api/MerchantChannelController.php \
  app/controller/admin/PackvipAdminController.php \
  tests/Unit/PaymentManagerTest.php
git commit -m "fix: reject removed drivers in channel settings"
```

---

### Task 4: Delete source implementations and stale admin UI literals

**Files:**
- Delete the five driver files listed in File Map.
- Modify: `public/admin/index.html`
- Create: `tests/Unit/RemovedPaymentDriverFrontendContractTest.php`

**Interfaces:**
- No removed driver implementation remains auto-discoverable.
- Tracked admin HTML contains none of the five codes.

- [ ] **Step 1: Write the failing source/UI contract**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\RemovedPaymentDrivers;
use PHPUnit\Framework\TestCase;

final class RemovedPaymentDriverFrontendContractTest extends TestCase
{
    public function testAdminFrontendContainsNoRemovedDriverCode(): void
    {
        $html = (string)file_get_contents(__DIR__ . '/../../public/admin/index.html');
        foreach (RemovedPaymentDrivers::all() as $code) {
            self::assertStringNotContainsString($code, $html);
        }
    }

    public function testRemovedDriverImplementationFilesDoNotExist(): void
    {
        foreach ([
            'AlipayOfficial',
            'WxpayOfficial',
            'AlipayScanBill',
            'WxpayProtocolCloud',
            'QqpayProtocolCloud',
        ] as $directory) {
            self::assertFileDoesNotExist(
                __DIR__ . "/../../app/payment/Drivers/{$directory}/Driver.php"
            );
        }
    }
}
```

Run:

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
```

Expected: failure because files and two admin literals remain.

- [ ] **Step 2: Delete all five implementation files and empty directories**

```bash
rm -- \
  app/payment/Drivers/AlipayOfficial/Driver.php \
  app/payment/Drivers/WxpayOfficial/Driver.php \
  app/payment/Drivers/AlipayScanBill/Driver.php \
  app/payment/Drivers/WxpayProtocolCloud/Driver.php \
  app/payment/Drivers/QqpayProtocolCloud/Driver.php
rmdir -- \
  app/payment/Drivers/AlipayOfficial \
  app/payment/Drivers/WxpayOfficial \
  app/payment/Drivers/AlipayScanBill \
  app/payment/Drivers/WxpayProtocolCloud \
  app/payment/Drivers/QqpayProtocolCloud
```

- [ ] **Step 3: Remove exact stale UI entries**

In `public/admin/index.html`:

- delete `alipay_official` and `wxpay_protocol_cloud` from the legacy `brandMap` object;
- delete both complete entries from `fallbackInputs`;
- retain `qqpay_app_asst` and generic metadata-driven rendering.

- [ ] **Step 4: Run source/UI and surviving-driver tests**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php
```

Expected: pass.

- [ ] **Step 5: Audit runtime/UI references**

```bash
grep -RInE \
  'alipay_official|wxpay_official|alipay_scan_bill|wxpay_protocol_cloud|qqpay_protocol_cloud' \
  app public config \
  --exclude='RemovedPaymentDrivers.php' || true
```

Expected remaining hits are limited to explicit controller error handling or migration-related code added later; no driver class, hard-coded option, brand mapping, or fallback form remains.

- [ ] **Step 6: Commit Task 4**

```bash
git add -A -- \
  app/payment/Drivers \
  public/admin/index.html \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
git commit -m "refactor: remove obsolete payment driver sources"
```

---

### Task 5: Implement archive/delete service with transaction tests

**Files:**
- Create: `app/service/LegacyPaymentDriverCleanupService.php`
- Create: `tests/Integration/LegacyPaymentDriverCleanupServiceTest.php`

**Interfaces:**
- Constructor: `__construct(Illuminate\Database\Connection $connection)`.
- `ensureArchiveTable(): void` performs schema phase only.
- `preview(): array` returns `channels`, `channel_count`, `poll_group_links`, `plans_to_update`, and `pending_orders`.
- `apply(): array` returns `archived`, `poll_group_links_deleted`, `channels_deleted`, `plans_updated`, and `remaining`.

- [ ] **Step 1: Build the exact SQLite test schema**

In `setUp()` create an in-memory connection, then create:

```php
$schema->create('cx_pay_channel', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('merchant_id')->default(0);
    $table->string('pay_category', 32);
    $table->string('title', 100);
    $table->string('c_type', 50);
    $table->string('remark', 255)->default('');
    $table->text('config')->nullable();
    $table->decimal('today_money', 10, 2)->default(0);
    $table->integer('today_count')->default(0);
    $table->decimal('total_money', 10, 2)->default(0);
    $table->integer('weight')->default(50);
    $table->decimal('single_min', 10, 2)->default(0);
    $table->decimal('single_max', 10, 2)->default(0);
    $table->decimal('day_max', 10, 2)->default(0);
    $table->integer('online_status')->default(0);
    $table->integer('status')->default(0);
});
$schema->create('cx_poll_group_channel', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('group_id');
    $table->integer('channel_id');
    $table->integer('weight')->default(50);
});
$schema->create('cx_plan', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('allowed_channels', 255)->default('');
});
$schema->create('cx_order', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('channel_id');
    $table->integer('status');
});
$schema->create('cx_callbill', function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('channel_id');
    $table->integer('status');
});
```

Import `Illuminate\Database\Schema\Blueprint` and retain the `Connection` in `$this->db`.

- [ ] **Step 2: Seed two removed channels and one valid channel**

Use IDs 10, 11, and 12:

- 10: `alipay_official`, config `{"merchant_private_key":"secret-a"}`;
- 11: `wxpay_protocol_cloud`, config `{"notify_token":"secret-b"}`;
- 12: `qqpay_app_asst`, config `{"device_id":"ANDROID_device-01"}`.

Add poll links for all three, a plan value `alipay,alipay_official,qqpay_app_asst,wxpay_protocol_cloud`, one paid order referencing 10, one closed order referencing 11, and callbills referencing both removed channels.

- [ ] **Step 3: Write failing preview/apply assertions**

After `ensureArchiveTable()`:

```php
$preview = $service->preview();
self::assertSame(2, $preview['channel_count']);
self::assertSame(2, $preview['poll_group_links']);
self::assertSame(1, $preview['plans_to_update']);
self::assertSame(0, $preview['pending_orders']);
self::assertSame(0, $this->db->table('cx_pay_channel_archive')->count());
```

After `apply()`:

```php
self::assertSame(2, $result['archived']);
self::assertSame(2, $result['poll_group_links_deleted']);
self::assertSame(2, $result['channels_deleted']);
self::assertSame(1, $result['plans_updated']);
self::assertSame(0, $result['remaining']);
self::assertSame(1, $this->db->table('cx_pay_channel')->where('id', 12)->count());
self::assertFalse($schema->hasColumn('cx_pay_channel_archive', 'config'));
self::assertSame(
    'alipay,qqpay_app_asst',
    $this->db->table('cx_plan')->where('id', 1)->value('allowed_channels')
);
```

Snapshot order and callbill rows before apply; assert all IDs, statuses, and channel IDs are identical afterward.

- [ ] **Step 4: Write idempotency, pending-order, and rollback tests**

Idempotency:

```php
$service->apply();
$second = $service->apply();
self::assertSame(0, $second['archived']);
self::assertSame(0, $second['channels_deleted']);
self::assertSame(2, $this->db->table('cx_pay_channel_archive')->count());
```

Pending-order guard: insert `['id' => 99, 'channel_id' => 10, 'status' => 0]`, call `apply()`, and assert a `RuntimeException` containing `待支付订单`. After catching it, assert archive, links, plans, and channels are unchanged.

Rollback: after `ensureArchiveTable()`, run:

```sql
CREATE TRIGGER fail_legacy_channel_delete
BEFORE DELETE ON cx_pay_channel
BEGIN
    SELECT RAISE(ABORT, 'forced cleanup failure');
END;
```

Call `apply()`, catch `Illuminate\Database\QueryException`, and assert archive count is zero and all links, plans, and active channels retain their original values.

- [ ] **Step 5: Verify red state**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: class-not-found failure.

- [ ] **Step 6: Implement `ensureArchiveTable()`**

The method must:

1. throw when `cx_pay_channel` is absent;
2. create `cx_pay_channel_archive` when absent;
3. define `archive_id`, unique `original_channel_id`, every non-sensitive channel field in the design, `archive_reason`, and `archived_at`;
4. omit `config`;
5. when the table already exists, call `hasColumns()` for every required column and throw `RuntimeException('归档表结构不完整')` if any are missing.

- [ ] **Step 7: Implement `preview()`**

Query target channels using `whereIn('c_type', RemovedPaymentDrivers::all())`, ordered by ID. Derive channel IDs and:

- count matching poll links only when `cx_poll_group_channel` exists;
- scan `cx_plan.allowed_channels` only when that table and column exist, counting rows where `stripCsv($value) !== $value`;
- count pending orders only when `cx_order` exists;
- return channel rows converted to arrays without decrypting or returning `config`.

- [ ] **Step 8: Implement `apply()` as one DML transaction**

Inside `Connection::transaction()`:

1. select and `lockForUpdate()` target channels;
2. return all-zero counters when none remain;
3. query pending orders for the target IDs and throw before any insert/update/delete when count is nonzero;
4. archive each row with `insertOrIgnore`, fixed reason `removed_placeholder_or_shared_token_driver`, and `time()`;
5. delete target poll links when the table exists;
6. update each changed plan with `RemovedPaymentDrivers::stripCsv()`;
7. delete target active channels;
8. count remaining target channels and throw if nonzero;
9. return exact affected-row counters.

Determine `archived` by summing each `insertOrIgnore()` return value so a second run reports zero.

- [ ] **Step 9: Run integration tests**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: all preview, apply, history, idempotency, pending-order, and rollback tests pass.

- [ ] **Step 10: Commit Task 5**

```bash
git add \
  app/service/LegacyPaymentDriverCleanupService.php \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
git commit -m "feat: archive and remove retired payment channels"
```

---

### Task 6: Add CLI migration, fresh-install schema, and runbook

**Files:**
- Create: `database/migrations/20260805_remove_legacy_payment_drivers.php`
- Modify: `database/install.sql`
- Create: `docs/runbooks/remove-legacy-payment-drivers.md`

**Interfaces:**
- Default CLI execution prepares schema and previews only.
- `--apply` performs cleanup after the pending-order guard.
- Fresh installations create the archive table and seed no removed driver.

- [ ] **Step 1: Create the standalone CLI bootstrap**

```php
<?php

declare(strict_types=1);

use app\service\LegacyPaymentDriverCleanupService;
use Illuminate\Database\Capsule\Manager as Capsule;

$baseDir = dirname(__DIR__, 2);
require $baseDir . '/vendor/autoload.php';
$config = require $baseDir . '/config/database.php';

$capsule = new Capsule();
$capsule->addConnection($config['connections']['mysql']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

try {
    $service = new LegacyPaymentDriverCleanupService($capsule->getConnection());
    $service->ensureArchiveTable();
    $preview = $service->preview();

    foreach ($preview['channels'] as $channel) {
        printf(
            "id=%d merchant_id=%d c_type=%s title=%s\n",
            $channel['id'],
            $channel['merchant_id'],
            $channel['c_type'],
            $channel['title']
        );
    }
    printf(
        "channel_count=%d poll_group_links=%d plans_to_update=%d pending_orders=%d\n",
        $preview['channel_count'],
        $preview['poll_group_links'],
        $preview['plans_to_update'],
        $preview['pending_orders']
    );

    if (!in_array('--apply', $argv, true)) {
        echo "DRY-RUN: no channel data changed\n";
        exit(0);
    }
    if ($preview['pending_orders'] > 0) {
        fwrite(STDERR, "Cleanup blocked: pending orders still reference removed channels\n");
        exit(2);
    }

    $result = $service->apply();
    printf(
        "archived=%d poll_group_links_deleted=%d channels_deleted=%d plans_updated=%d remaining=%d\n",
        $result['archived'],
        $result['poll_group_links_deleted'],
        $result['channels_deleted'],
        $result['plans_updated'],
        $result['remaining']
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
```

The script must never print `config`.

- [ ] **Step 2: Add archive schema to `database/install.sql`**

Immediately after `cx_pay_channel`, add the same archive columns and indexes used by the service:

```sql
PRIMARY KEY (`archive_id`),
UNIQUE KEY `uk_original_channel_id` (`original_channel_id`),
KEY `idx_archive_ctype` (`c_type`),
KEY `idx_archive_merchant` (`merchant_id`)
```

Remove these full seed rows:

```sql
(1, 'alipay', '支付宝官方网页支付（待配置）', 'alipay_official', '{}', 100, 0, 0),
(2, 'wxpay', '微信外部账单回调（待配置）', 'wxpay_protocol_cloud', '{}', 80, 0, 0),
```

Keep this row and make the statement syntactically valid as a single-row insert:

```sql
(3, 'qqpay', 'QQ 钱包 App 助手（待配置）', 'qqpay_app_asst', '{}', 50, 0, 0)
```

- [ ] **Step 3: Write the operator runbook**

Document exactly:

```bash
cd /www/wwwroot/cs.fcwan.cn
git status --short
git branch --show-current
php start.php status
```

Require a fresh 宝塔 database backup before data mutation. Then document:

```bash
php start.php stop
php database/migrations/20260805_remove_legacy_payment_drivers.php
```

The operator must verify `pending_orders=0` and inspect all listed IDs before:

```bash
php database/migrations/20260805_remove_legacy_payment_drivers.php --apply
php database/migrations/20260805_remove_legacy_payment_drivers.php
```

The second run must show zero targets. Include targeted tests, full tests, `php start.php start -d`, `php start.php status`, browser `Ctrl+F5`, and explicit warnings not to remove `install.lock` or run `git clean -fd`.

- [ ] **Step 4: Lint and inspect**

```bash
php -l database/migrations/20260805_remove_legacy_payment_drivers.php
git diff --check
git diff -- database/install.sql docs/runbooks/remove-legacy-payment-drivers.md
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: pass; fresh-install seed contains no removed code.

- [ ] **Step 5: Commit Task 6**

```bash
git add \
  database/migrations/20260805_remove_legacy_payment_drivers.php \
  database/install.sql \
  docs/runbooks/remove-legacy-payment-drivers.md
git commit -m "docs: add retired channel cleanup migration runbook"
```

---

### Task 7: Run full verification and apply on the test server

**Files:**
- Verify all Task 1–6 files.
- Correct only files named by a failing test, syntax check, or reference audit.

**Interfaces:**
- Produces clean Git state, passing tests, zero active target channels, and preserved historical references.

- [ ] **Step 1: Lint all PHP files in scope**

```bash
find app config process tests database/migrations -type f -name '*.php' -print0 \
  | xargs -0 -n1 php -l
```

Expected: every file reports no syntax errors.

- [ ] **Step 2: Run targeted regression**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/PluginPackageInstallerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: `OK`.

- [ ] **Step 3: Run the full suite**

```bash
php vendor/bin/phpunit --colors=never
```

Expected: `OK`. Record the exact test and assertion counts printed by this run.

- [ ] **Step 4: Audit executable references**

```bash
grep -RInE \
  'alipay_official|wxpay_official|alipay_scan_bill|wxpay_protocol_cloud|qqpay_protocol_cloud' \
  app public config \
  --exclude='RemovedPaymentDrivers.php' || true
```

Review every hit. Allowed hits are explicit permanent-removal guards and cleanup policy use. No implementation class, hard-coded driver option, fallback form, or brand mapping may remain.

Verify deleted files directly:

```bash
test ! -e app/payment/Drivers/AlipayOfficial/Driver.php
test ! -e app/payment/Drivers/WxpayOfficial/Driver.php
test ! -e app/payment/Drivers/AlipayScanBill/Driver.php
test ! -e app/payment/Drivers/WxpayProtocolCloud/Driver.php
test ! -e app/payment/Drivers/QqpayProtocolCloud/Driver.php
```

- [ ] **Step 5: Verify clean Git scope**

```bash
git status --short
git diff --check
git log --oneline --decorate -10
```

Expected: no uncommitted tracked changes. Server-only untracked files remain preserved.

- [ ] **Step 6: Back up and apply on `/www/wwwroot/cs.fcwan.cn`**

Follow the runbook. Required evidence:

- 宝塔 database backup completed;
- dry-run target IDs reviewed;
- `pending_orders=0`;
- `--apply` reports `remaining=0`;
- second dry-run reports zero targets;
- targeted and full PHPUnit pass after migration;
- service restarts with `php start.php start -d` and reports running status.

- [ ] **Step 7: Browser verification**

After `Ctrl+F5`, confirm all six outcomes:

1. Admin installed-driver list shows none of the five codes.
2. Admin channel form shows none of them.
3. Package allowed-channel selector shows none of them.
4. Merchant channel selector shows none of them.
5. Historical orders retain original channel IDs.
6. A manually submitted removed `c_type` returns the permanent-removal message.

- [ ] **Step 8: Commit corrections only when verification changed tracked files**

Run `git status --short`, list each modified tracked path, stage those paths explicitly, then commit:

```bash
git commit -m "test: enforce removed payment driver contracts"
```

Do not create an empty commit.

- [ ] **Step 9: Push and re-check PR state**

```bash
git push origin fix/p0-hardening
git rev-parse HEAD
git status --short
```

Re-check PR #2 conflict state and workflow availability. Do not mark it ready or merge solely from local tests.
