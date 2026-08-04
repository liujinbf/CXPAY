# Remove Legacy Payment Drivers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permanently remove five placeholder or shared-Token payment drivers from discovery, plugin installation, configuration UIs, active channel data, and package bindings while preserving non-sensitive audit metadata and historical transaction references.

**Architecture:** A single `RemovedPaymentDrivers` policy owns the immutable tombstone list and is enforced by `PaymentManager`, plugin installation, controllers, and migration code. A focused cleanup service performs two-stage schema preparation plus transactional archive/delete work; a CLI wrapper defaults to dry-run and only mutates data with `--apply`. Existing orders and callbills retain their numeric `channel_id`, while active channels and poll-group links are removed.

**Tech Stack:** PHP 8.2, Webman, Illuminate Database/Eloquent, MySQL 5.7+/8.0, PHPUnit 10, HTML/vanilla JavaScript.

## Global Constraints

- Work only on branch `fix/p0-hardening`; keep PR #2 in Draft until verification is complete.
- Permanently remove exactly: `alipay_official`, `wxpay_official`, `alipay_scan_bill`, `wxpay_protocol_cloud`, `qqpay_protocol_cloud`.
- Keep `sandbox_test`; it remains an internal test driver and is outside this cleanup.
- Delete the five driver implementations physically; do not merely hide them with UI flags.
- Reject future built-in or plugin registration using any removed code.
- Archive only non-sensitive channel metadata; never archive `config`, Token, Cookie, private key, or secret values.
- Preserve `cx_order.channel_id`, `cx_callbill.channel_id`, and other historical numeric channel references unchanged.
- Default migration mode is dry-run; data mutation requires the exact `--apply` argument.
- Abort `--apply` when any pending order (`cx_order.status = 0`) references a target channel; never strand an in-flight payment.
- The archive-table DDL runs before the DML transaction because MySQL DDL causes an implicit commit.
- Migration must be idempotent and must roll back all phase-two DML on failure.
- Do not run `git clean -fd`; preserve `CXPAY.rar`, `cxpay-webman.supervisor.conf`, and `install.lock` on the test server.
- Do not claim GitHub Actions is green; use server-side PHPUnit output as the verification evidence unless Actions later produces executable jobs and logs.

---

## File Structure

**Create**

- `app/payment/RemovedPaymentDrivers.php` — immutable tombstone policy and CSV cleanup helper.
- `app/service/LegacyPaymentDriverCleanupService.php` — archive-table creation, preview, pending-order guard, transactional cleanup, and result counters.
- `database/migrations/20260805_remove_legacy_payment_drivers.php` — CLI dry-run/`--apply` entrypoint.
- `tests/Integration/LegacyPaymentDriverCleanupServiceTest.php` — SQLite-backed cleanup, idempotency, history preservation, and rollback tests.
- `tests/Unit/RemovedPaymentDriverFrontendContractTest.php` — prevents stale driver codes from returning to tracked admin UI assets.
- `docs/runbooks/remove-legacy-payment-drivers.md` — exact test-server backup, dry-run, apply, verification, and restart procedure.

**Modify**

- `app/payment/PaymentManager.php` — enforce tombstones in registration, discovery, lookup, instantiation, and listing.
- `app/payment/Plugin/PluginPackageInstaller.php` — reject signed packages that declare a tombstoned driver code before writing files.
- `app/controller/admin/PluginMarketController.php` — suppress tombstoned codes from installed-plugin listings, including stale registry entries.
- `app/controller/admin/AdminController.php` — return an explicit permanent-removal error for admin channel save requests.
- `app/controller/api/MerchantChannelController.php` — return an explicit permanent-removal error for merchant channel save requests.
- `app/controller/admin/PackvipAdminController.php` — reject new package bindings containing removed codes.
- `public/admin/index.html` — remove stale brand/fallback mappings for `alipay_official` and `wxpay_protocol_cloud`.
- `database/install.sql` — add the archive table and stop seeding removed channel examples.
- `tests/Unit/PaymentManagerTest.php` — replace legacy availability expectations with tombstone behavior.
- `tests/Unit/AlipayScanMonitorPluginTest.php` — assert the retired predecessor is absent instead of deprecated-but-runnable.
- `tests/Unit/PluginPackageInstallerTest.php` — verify a validly signed package cannot claim a tombstoned code.

**Delete**

- `app/payment/Drivers/AlipayOfficial/Driver.php`
- `app/payment/Drivers/WxpayOfficial/Driver.php`
- `app/payment/Drivers/AlipayScanBill/Driver.php`
- `app/payment/Drivers/WxpayProtocolCloud/Driver.php`
- `app/payment/Drivers/QqpayProtocolCloud/Driver.php`

---

### Task 1: Add the immutable removed-driver policy and fail-closed manager behavior

**Files:**
- Create: `app/payment/RemovedPaymentDrivers.php`
- Modify: `app/payment/PaymentManager.php`
- Modify: `tests/Unit/PaymentManagerTest.php`
- Modify: `tests/Unit/AlipayScanMonitorPluginTest.php`

**Interfaces:**
- Produces: `RemovedPaymentDrivers::all(): array`, `contains(string): bool`, `assertAllowed(string): void`, `stripCsv(string): string`.
- Produces: `PaymentManager::has($removedCode) === false`; `make/register/registerPluginDriver` throw `InvalidArgumentException` containing `已永久移除`.
- Consumed later by plugin installation, controllers, and database cleanup.

- [ ] **Step 1: Write failing tombstone tests in `PaymentManagerTest`**

Add `setUp()` and a provider containing all five exact codes:

```php
protected function setUp(): void
{
    PaymentManager::flush();
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

Add these tests:

```php
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
```

Remove `alipay_scan_bill`, `wxpay_protocol_cloud`, and `qqpay_protocol_cloud` from `personalQrDriverProvider()`. Replace the old `merchantDriverProvider()` and `testDisabledMerchantDriverFailsClosed()` expectations with the tombstone provider above.

In `AlipayScanMonitorPluginTest::testManifestDeclaresPersonalQrCallbackPlugin()`, replace the deprecated-driver assertion with:

```php
self::assertFalse(PaymentManager::has('alipay_scan_bill'));
self::assertArrayNotHasKey('alipay_scan_bill', PaymentManager::getRegisteredDrivers());
```

- [ ] **Step 2: Run the tests to verify the red state**

Run:

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php
```

Expected: failures because removed directories are still auto-discovered and `PaymentManager` has no permanent tombstone error.

- [ ] **Step 3: Create `RemovedPaymentDrivers.php`**

Implement exactly:

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

- [ ] **Step 4: Enforce the policy in `PaymentManager`**

Make these exact behavioral changes:

```php
public static function register(string $cType, string $class): void
{
    RemovedPaymentDrivers::assertAllowed($cType);
    // existing subclass check and registration
}

public static function registerPluginDriver(string $cType, string $class, string $pluginId): void
{
    RemovedPaymentDrivers::assertAllowed($cType);
    // existing validation and registration
}

public static function make(string $cType): PaymentDriverInterface
{
    RemovedPaymentDrivers::assertAllowed($cType);
    static::discoverDrivers();
    // existing logic
}

public static function has(string $cType): bool
{
    if (RemovedPaymentDrivers::contains($cType)) {
        return false;
    }
    static::discoverDrivers();
    // existing logic
}
```

Inside `discoverDrivers()`, after reading `$cType` from metadata, skip tombstoned values before assigning `static::$drivers[$cType]`. Inside `getRegisteredDrivers()`, defensively skip any tombstoned key before availability checks.

- [ ] **Step 5: Run the focused tests**

Run the same PHPUnit command. Expected: all tests pass, including surviving assistant and EPay driver assertions.

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

### Task 2: Reject retired codes during plugin installation and hide stale registry entries

**Files:**
- Modify: `app/payment/Plugin/PluginPackageInstaller.php`
- Modify: `app/controller/admin/PluginMarketController.php`
- Modify: `tests/Unit/PluginPackageInstallerTest.php`

**Interfaces:**
- Consumes: `RemovedPaymentDrivers::contains(string): bool`.
- Produces: signed packages declaring a retired code fail before staging or registry writes.
- Produces: `getMarketList()` omits removed codes even if an old registry entry remains on disk.

- [ ] **Step 1: Write a failing package-installer test**

Change `createPackage()` to accept a driver code:

```php
private function createPackage(bool $tamper, string $driverCode = 'wxpay_signed_demo'): string
```

Use `$driverCode` for `manifest.drivers[0].code`. Add:

```php
public function testRejectsValidlySignedPackageUsingRemovedDriverCode(): void
{
    $package = $this->createPackage(false, 'wxpay_protocol_cloud');

    $this->expectException(PluginException::class);
    $this->expectExceptionMessage('已永久移除');
    $this->installer()->install($package);
}
```

- [ ] **Step 2: Run the test and verify it fails**

```bash
php vendor/bin/phpunit --colors=never tests/Unit/PluginPackageInstallerTest.php
```

Expected: the package currently installs because signature verification does not enforce the tombstone list.

- [ ] **Step 3: Reject tombstoned manifest codes**

Import `app\payment\RemovedPaymentDrivers`. Immediately after `PluginManifest::fromJson()` and before staging writes, execute:

```php
foreach ($manifest->drivers() as $driver) {
    $code = trim((string)($driver['code'] ?? ''));
    if (RemovedPaymentDrivers::contains($code)) {
        throw new PluginException("插件声明了已永久移除的支付驱动: {$code}");
    }
}
```

Keep signature verification in place; a package must not bypass signature checks merely because its code is retired. The order must be: parse manifest, verify signature, reject removed code, verify declared files, stage.

- [ ] **Step 4: Filter installed registry entries in `PluginMarketController`**

Import `RemovedPaymentDrivers`. In the installed-plugin driver loop, add before appending `$plugins[]`:

```php
$cType = trim((string)($driver['code'] ?? ''));
if ($cType !== '' && RemovedPaymentDrivers::contains($cType)) {
    continue;
}
```

Use `$cType` in the returned array. Built-ins require no separate list because `PaymentManager::getRegisteredDrivers()` is already tombstone-safe.

- [ ] **Step 5: Run plugin and manager tests**

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

### Task 3: Reject removed codes in channel and package configuration APIs

**Files:**
- Modify: `app/controller/admin/AdminController.php` in `saveChannelConfig()`
- Modify: `app/controller/api/MerchantChannelController.php` in `save()`
- Modify: `app/controller/admin/PackvipAdminController.php` in `save()`
- Test: `tests/Unit/PaymentManagerTest.php`

**Interfaces:**
- Consumes: `RemovedPaymentDrivers::contains()` and `stripCsv()`.
- Produces: explicit API message `该支付驱动已永久移除` for channel saves.
- Produces: package save rejects a list containing any exact retired code while preserving category values such as `alipay`, `wxpay`, and `qqpay`.

- [ ] **Step 1: Add unit coverage for CSV behavior**

Add to `PaymentManagerTest` or a new focused test class:

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

Import `RemovedPaymentDrivers`.

- [ ] **Step 2: Run the test to establish the expected policy behavior**

```bash
php vendor/bin/phpunit --colors=never tests/Unit/PaymentManagerTest.php
```

Expected: pass after Task 1; this locks the helper contract before controller use.

- [ ] **Step 3: Add explicit admin and merchant rejection**

In both channel-save methods, immediately after trimming `$cType`, add:

```php
if (RemovedPaymentDrivers::contains($cType)) {
    return json_encode([
        'code' => -1,
        'msg' => '该支付驱动已永久移除，不能创建或修改通道',
    ], JSON_UNESCAPED_UNICODE);
}
```

Use the controller's existing response type: `AdminController` returns encoded strings; `MerchantChannelController` returns `json([...])`. Do not change surrounding response conventions.

- [ ] **Step 4: Normalize and reject package bindings**

In `PackvipAdminController::save()`, replace direct `implode`/string assignment with:

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

Do not reject category values (`alipay`, `wxpay`, `qqpay`) because current plans use them as compatibility categories.

- [ ] **Step 5: Run syntax and focused tests**

```bash
php -l app/controller/admin/AdminController.php
php -l app/controller/api/MerchantChannelController.php
php -l app/controller/admin/PackvipAdminController.php
php vendor/bin/phpunit --colors=never tests/Unit/PaymentManagerTest.php
```

Expected: all syntax checks and tests pass.

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

### Task 4: Delete legacy driver sources and remove stale admin UI mappings

**Files:**
- Delete the five `Driver.php` files listed in File Structure.
- Modify: `public/admin/index.html`
- Create: `tests/Unit/RemovedPaymentDriverFrontendContractTest.php`

**Interfaces:**
- Produces: no auto-discoverable implementation file for any tombstoned code.
- Produces: tracked admin UI contains no removed driver literal.

- [ ] **Step 1: Write the failing frontend/source contract test**

Create:

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
            self::assertStringNotContainsString($code, $html, "后台页面仍引用已移除驱动 {$code}");
        }
    }

    public function testRemovedDriverImplementationFilesDoNotExist(): void
    {
        $paths = [
            'AlipayOfficial',
            'WxpayOfficial',
            'AlipayScanBill',
            'WxpayProtocolCloud',
            'QqpayProtocolCloud',
        ];
        foreach ($paths as $directory) {
            self::assertFileDoesNotExist(
                __DIR__ . "/../../app/payment/Drivers/{$directory}/Driver.php"
            );
        }
    }
}
```

- [ ] **Step 2: Run the test and verify it fails**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
```

Expected: failure because source files exist and `public/admin/index.html` contains `alipay_official` and `wxpay_protocol_cloud` in `brandMap` and `fallbackInputs`.

- [ ] **Step 3: Delete the five driver implementations**

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

- [ ] **Step 4: Remove stale UI literals**

In `public/admin/index.html`:

1. Delete `alipay_official` and `wxpay_protocol_cloud` entries from the legacy `brandMap` object.
2. Delete their complete entries from `fallbackInputs`.
3. Keep `qqpay_app_asst` and generic metadata-driven rendering unchanged.
4. Do not add replacement fake drivers; installed/registered drivers remain the only source of options.

- [ ] **Step 5: Run contract and driver tests**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php
```

Expected: pass.

- [ ] **Step 6: Run a repository reference audit**

```bash
grep -RInE \
  'alipay_official|wxpay_official|alipay_scan_bill|wxpay_protocol_cloud|qqpay_protocol_cloud' \
  app public config tests database \
  --exclude='RemovedPaymentDrivers.php' \
  --exclude='20260805_remove_legacy_payment_drivers.php' \
  --exclude='LegacyPaymentDriverCleanupServiceTest.php' \
  --exclude='PaymentManagerTest.php' \
  --exclude='RemovedPaymentDriverFrontendContractTest.php' \
  --exclude='install.sql' || true
```

Expected: no runtime/UI references. Documentation and migration policy references are allowed; executable references are not.

- [ ] **Step 7: Commit Task 4**

```bash
git add -A -- \
  app/payment/Drivers \
  public/admin/index.html \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php
git commit -m "refactor: remove obsolete payment driver sources"
```

---

### Task 5: Build the transactional archive-and-delete service with integration tests

**Files:**
- Create: `app/service/LegacyPaymentDriverCleanupService.php`
- Create: `tests/Integration/LegacyPaymentDriverCleanupServiceTest.php`

**Interfaces:**
- Constructor: `__construct(Illuminate\Database\Connection $connection)`.
- Produces: `ensureArchiveTable(): void`.
- Produces: `preview(): array{channels:list<array<string,mixed>>,channel_count:int,poll_group_links:int,plans_to_update:int,pending_orders:int}`.
- Produces: `apply(): array{archived:int,poll_group_links_deleted:int,channels_deleted:int,plans_updated:int,remaining:int}`.
- Consumes: `RemovedPaymentDrivers::all()` and `stripCsv()`.

- [ ] **Step 1: Create the SQLite integration-test schema**

In test `setUp()`, create an in-memory Illuminate connection and these tables with only required columns:

```php
$capsule = new \Illuminate\Database\Capsule\Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$this->db = $capsule->getConnection();
$schema = $this->db->getSchemaBuilder();
```

Create `cx_pay_channel`, `cx_poll_group_channel`, `cx_plan`, `cx_order`, and `cx_callbill`. The channel table must include every archive field plus a `config` column so the test can prove secrets are not copied.

- [ ] **Step 2: Write failing preview and apply tests**

Seed:

- channel 10: `alipay_official`, config containing `merchant_private_key`;
- channel 11: `wxpay_protocol_cloud`, config containing `notify_token`;
- channel 12: `qqpay_app_asst`, which must remain active;
- poll-group links for channels 10, 11, and 12;
- plans containing exact removed codes mixed with category and valid driver values;
- paid/closed historical orders and callbills referencing 10 and 11.

Tests must assert:

```php
$preview = $service->preview();
self::assertSame(2, $preview['channel_count']);
self::assertSame(2, $preview['poll_group_links']);
self::assertSame(0, $preview['pending_orders']);
self::assertSame(0, $this->db->table('cx_pay_channel_archive')->count());
```

After `apply()`:

```php
self::assertSame(2, $result['archived']);
self::assertSame(2, $result['channels_deleted']);
self::assertSame(0, $result['remaining']);
self::assertSame(1, $this->db->table('cx_pay_channel')->where('id', 12)->count());
self::assertFalse($schema->hasColumn('cx_pay_channel_archive', 'config'));
self::assertSame('alipay,qqpay_app_asst', $this->db->table('cx_plan')->where('id', 1)->value('allowed_channels'));
```

Capture order/callbill rows before apply and assert the same count, status, and `channel_id` afterward.

- [ ] **Step 3: Write failing idempotency and pending-order tests**

Idempotency:

```php
$service->apply();
$second = $service->apply();
self::assertSame(0, $second['archived']);
self::assertSame(0, $second['channels_deleted']);
self::assertSame(2, $this->db->table('cx_pay_channel_archive')->count());
```

Pending-order guard:

```php
$this->db->table('cx_order')->insert([
    'id' => 99,
    'channel_id' => 10,
    'status' => 0,
]);
$this->expectException(\RuntimeException::class);
$this->expectExceptionMessage('待支付订单');
$service->apply();
```

Assert in a separate catch-based test that channels, links, plans, and archive data remain unchanged when this guard triggers.

- [ ] **Step 4: Write the failing rollback test using an SQLite trigger**

After `ensureArchiveTable()`, create:

```sql
CREATE TRIGGER fail_legacy_channel_delete
BEFORE DELETE ON cx_pay_channel
BEGIN
    SELECT RAISE(ABORT, 'forced cleanup failure');
END;
```

Call `apply()`, catch `QueryException`, then assert archive count is zero and all activity/link/plan data remains unchanged. This proves phase-two DML uses one transaction without adding a production-only test hook.

- [ ] **Step 5: Run integration tests and verify red state**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: class-not-found failure.

- [ ] **Step 6: Implement `LegacyPaymentDriverCleanupService`**

Core shape:

```php
final class LegacyPaymentDriverCleanupService
{
    public function __construct(private readonly Connection $connection) {}

    public function ensureArchiveTable(): void { /* schema phase */ }
    public function preview(): array { /* no DML */ }
    public function apply(): array { /* one transaction */ }
}
```

`ensureArchiveTable()` requirements:

- fail if `cx_pay_channel` does not exist;
- create `cx_pay_channel_archive` if absent;
- use `archive_id` auto-increment primary key and unique `original_channel_id`;
- include all non-sensitive fields from the design;
- omit `config` entirely;
- if the table already exists, verify all required columns exist and throw a descriptive `RuntimeException` when malformed.

`preview()` requirements:

- select target channels ordered by `id`;
- count poll-group links only when `cx_poll_group_channel` exists;
- count plans requiring change only when `cx_plan` and `allowed_channels` exist;
- count pending orders only when `cx_order` exists;
- perform no insert/update/delete.

`apply()` requirements:

```php
return $this->connection->transaction(function (): array {
    $channels = $this->connection->table('cx_pay_channel')
        ->whereIn('c_type', RemovedPaymentDrivers::all())
        ->orderBy('id')
        ->lockForUpdate()
        ->get();

    // derive IDs; fail when pending status=0 orders exist
    // insertOrIgnore archive rows keyed by original_channel_id
    // delete optional poll links
    // clean optional cx_plan.allowed_channels with stripCsv()
    // delete target active channels
    // verify remaining count is zero; throw on mismatch
    // return exact counters
});
```

Use a fixed archive reason such as `removed_placeholder_or_shared_token_driver` and `time()` for `archived_at`. Count `archived` as rows newly inserted, not total archive size.

- [ ] **Step 7: Run integration tests**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: all cleanup, idempotency, pending-order, and rollback tests pass.

- [ ] **Step 8: Commit Task 5**

```bash
git add \
  app/service/LegacyPaymentDriverCleanupService.php \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
git commit -m "feat: archive and remove retired payment channels"
```

---

### Task 6: Add the safe CLI migration, fresh-install schema, and operator runbook

**Files:**
- Create: `database/migrations/20260805_remove_legacy_payment_drivers.php`
- Modify: `database/install.sql`
- Create: `docs/runbooks/remove-legacy-payment-drivers.md`

**Interfaces:**
- CLI default: schema preparation plus preview only; exit 0 when safe, exit 2 when pending orders block apply.
- CLI mutation: exact argument `--apply` calls service `apply()`.
- Fresh installs create `cx_pay_channel_archive` and do not seed removed drivers.

- [ ] **Step 1: Write the CLI migration entrypoint**

Use the repository's existing standalone migration bootstrap pattern:

```php
$baseDir = dirname(__DIR__, 2);
require $baseDir . '/vendor/autoload.php';
$config = require $baseDir . '/config/database.php';
$capsule = new Capsule();
$capsule->addConnection($config['connections']['mysql']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$service = new LegacyPaymentDriverCleanupService($capsule->getConnection());
```

Then:

1. call `ensureArchiveTable()`;
2. call `preview()`;
3. print each target as `id=<id> merchant_id=<id> c_type=<code> title=<title>`;
4. print all preview counters;
5. without `--apply`, print `DRY-RUN: no channel data changed` and exit 0;
6. with `--apply` and `pending_orders > 0`, print an error and exit 2;
7. otherwise call `apply()`, print counters, and exit 0;
8. catch `Throwable`, write only the safe exception message to STDERR, and exit 1.

Do not print decrypted configuration or raw `config` JSON.

- [ ] **Step 2: Update `database/install.sql`**

Immediately after `cx_pay_channel`, add `cx_pay_channel_archive` with the same non-sensitive columns used by the service and:

```sql
PRIMARY KEY (`archive_id`),
UNIQUE KEY `uk_original_channel_id` (`original_channel_id`),
KEY `idx_archive_ctype` (`c_type`),
KEY `idx_archive_merchant` (`merchant_id`)
```

In the disabled example-channel seed, remove these two rows entirely:

```sql
(1, 'alipay', '支付宝官方网页支付（待配置）', 'alipay_official', ...),
(2, 'wxpay', '微信外部账单回调（待配置）', 'wxpay_protocol_cloud', ...),
```

Keep the `qqpay_app_asst` example. Convert the remaining insert to valid single-row SQL with no trailing-comma error.

- [ ] **Step 3: Create the operator runbook**

Document this exact order for `/www/wwwroot/cs.fcwan.cn`:

```bash
cd /www/wwwroot/cs.fcwan.cn
git status --short
git branch --show-current
php start.php status
```

Require a new 宝塔 database backup before any `--apply`. Then:

```bash
php start.php stop
php database/migrations/20260805_remove_legacy_payment_drivers.php
```

The operator must inspect target IDs and `pending_orders=0`, then run:

```bash
php database/migrations/20260805_remove_legacy_payment_drivers.php --apply
php database/migrations/20260805_remove_legacy_payment_drivers.php
```

The second command must report zero targets. Finish with targeted tests, full tests, restart, and browser `Ctrl+F5`. Explicitly state not to delete `install.lock` and not to run `git clean -fd`.

- [ ] **Step 4: Lint migration and inspect SQL diff**

```bash
php -l database/migrations/20260805_remove_legacy_payment_drivers.php
git diff --check
git diff -- database/install.sql docs/runbooks/remove-legacy-payment-drivers.md
```

Expected: no syntax or whitespace errors; no removed driver remains in fresh-install seed data.

- [ ] **Step 5: Run migration service tests again**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: pass.

- [ ] **Step 6: Commit Task 6**

```bash
git add \
  database/migrations/20260805_remove_legacy_payment_drivers.php \
  database/install.sql \
  docs/runbooks/remove-legacy-payment-drivers.md
git commit -m "docs: add retired channel cleanup migration runbook"
```

---

### Task 7: Complete static audit, full regression, and test-server application

**Files:**
- Verify all files from Tasks 1–6.
- Update only files implicated by failed tests or remaining executable references.

**Interfaces:**
- Produces a clean branch, successful targeted/full test output, and a migration result with zero remaining active target channels.

- [ ] **Step 1: Run PHP syntax lint for tracked application code**

```bash
find app config process tests database/migrations -type f -name '*.php' -print0 \
  | xargs -0 -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 2: Run the targeted regression set**

```bash
php vendor/bin/phpunit --colors=never \
  tests/Unit/PaymentManagerTest.php \
  tests/Unit/PluginPackageInstallerTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php \
  tests/Unit/RemovedPaymentDriverFrontendContractTest.php \
  tests/Integration/LegacyPaymentDriverCleanupServiceTest.php
```

Expected: `OK`; no errors or failures.

- [ ] **Step 3: Run the full PHPUnit suite**

```bash
php vendor/bin/phpunit --colors=never
```

Expected: `OK`. Record the exact test and assertion counts; do not reuse the previous `157 tests, 483 assertions` count unless this run prints it.

- [ ] **Step 4: Run final reference and source audits**

```bash
for code in \
  alipay_official wxpay_official alipay_scan_bill \
  wxpay_protocol_cloud qqpay_protocol_cloud
do
  test ! -e "app/payment/Drivers/$(echo "$code" | awk -F_ '{for(i=1;i<=NF;i++) printf toupper(substr($i,1,1)) substr($i,2)}')/Driver.php"
done

grep -RInE \
  'alipay_official|wxpay_official|alipay_scan_bill|wxpay_protocol_cloud|qqpay_protocol_cloud' \
  app public config \
  --exclude='RemovedPaymentDrivers.php' || true
```

Expected: no executable reference outside the tombstone policy, controller rejection messages, cleanup service, and intentional audit text.

- [ ] **Step 5: Verify clean Git scope before deployment**

```bash
git status --short
git diff --check
git log --oneline --decorate -10
```

Expected: no uncommitted tracked changes. On the server, only the preserved untracked files may remain.

- [ ] **Step 6: Back up and apply on the Alibaba Cloud test server**

Follow `docs/runbooks/remove-legacy-payment-drivers.md` exactly. Required evidence:

- 宝塔 database backup completed;
- dry-run target IDs reviewed;
- `pending_orders=0`;
- `--apply` reports archive/delete counters and `remaining=0`;
- second dry-run reports zero targets;
- full PHPUnit passes after migration;
- `php start.php start -d` and `php start.php status` confirm the service is running.

- [ ] **Step 7: Browser verification**

After `Ctrl+F5`, confirm:

1. Admin “已安装支付驱动” does not display any of the five codes.
2. Admin channel add/config UI does not display them.
3. Package allowed-channel selector does not display them.
4. Merchant channel selector does not display them.
5. Existing historical orders remain visible and retain their original channel ID.
6. A manual request using a removed `c_type` returns the permanent-removal error.

- [ ] **Step 8: Commit any verification-only correction, then push**

Only when a test or audit required a correction:

```bash
git add <exact-corrected-files>
git commit -m "test: enforce removed payment driver contracts"
```

Then:

```bash
git push origin fix/p0-hardening
git rev-parse HEAD
git status --short
```

Do not mark PR #2 ready and do not merge it solely from local tests; first re-evaluate its conflict state with current `main` and the availability of GitHub Actions logs.
