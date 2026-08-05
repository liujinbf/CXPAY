# Cloud Connector Credential Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce the rule that payment-account cookies and reusable web-login credentials remain in the isolated cloud service while CXPAY only installs a signed local connector and stores opaque account references.

**Architecture:** Extend the plugin manifest with executable cloud-connector security declarations, validate driver metadata before enablement, route all connector HTTP traffic through one hardened client, and refactor `alipay-scan-monitor` so it never accepts or transports Cookie data. Add replay protection, decimal-string money handling, remote order query, a dry-run cleanup utility, and CI regression tests.

**Tech Stack:** PHP 8.1+, Webman 2.1, PHPUnit 10, Illuminate Database/Redis, Guzzle 7.8, OpenSSL, existing `.cxpay-plugin` RSA signing.

## Global Constraints

- `runtime_type` for these plugins is exactly `cloud_connector`.
- `credential_boundary` is exactly `cloud_only`.
- The protocol version is exactly `cxpay-cloud-payment-v1`.
- CXPAY must not receive, store, display, forward, log, cache, or migrate payment-account Cookie or reusable web-login credentials.
- `account_id` must remain an opaque cloud-side reference that cannot log in to the payment platform.
- Plugins remain executable PHP in the CXPAY process; only trusted publisher keys are accepted and install remains disabled by default.
- Connector traffic must use HTTPS on port 443, no redirects, no URL credentials, no IP-literal endpoint, and a manifest-declared host allowlist.
- Money is represented as normalized two-decimal strings and compared with `bc*` functions.
- No production database changes, `.env` edits, service restarts, or `install.lock` changes are performed by this implementation plan.
- Use TDD and commit after every task.

---

## File Structure

**Create**

- `app/payment/Plugin/CloudConnectorPolicy.php` — validates manifest and runtime metadata against the credential-isolation ADR.
- `app/payment/Plugin/CloudConnectorHttpClient.php` — performs allowlisted, signed, size-limited connector requests.
- `app/payment/Plugin/CloudCallbackReplayGuard.php` — rejects repeated callback event IDs and nonces.
- `app/service/CloudCredentialCleanupService.php` — detects and removes forbidden connector config keys without printing values.
- `tools/migrations/remove_cloud_connector_credentials.php` — dry-run/apply wrapper for cleanup.
- `tests/Unit/CloudConnectorPolicyTest.php`
- `tests/Unit/CloudConnectorHttpClientTest.php`
- `tests/Unit/CloudCallbackReplayGuardTest.php`
- `tests/Integration/CloudCredentialCleanupServiceTest.php`

**Modify**

- `app/payment/Plugin/PluginManifest.php` — parse and expose connector security declarations.
- `app/payment/Plugin/PluginPackageInstaller.php` — run policy validation before staging installation.
- `app/payment/Plugin/PluginManager.php` — validate active driver metadata before registration.
- `config/payment_plugin.php` — define exact trusted connector hosts and HTTP limits.
- `plugins-src/alipay-scan-monitor/manifest.json` — declare cloud-only credentials and accurate capabilities.
- `plugins-src/alipay-scan-monitor/src/Driver.php` — remove Cookie input, use decimal strings, query remote orders, use replay guard.
- `plugins-src/alipay-scan-monitor/src/ProviderClient.php` — delegate all HTTP work to the shared client.
- `plugins-src/alipay-scan-monitor/README.md` — align wording with the enforced architecture.
- `tests/Unit/AlipayScanMonitorPluginTest.php`
- `tests/Unit/PluginPackageInstallerTest.php`

---

### Task 1: Extend the Plugin Manifest Contract

**Files:**
- Modify: `app/payment/Plugin/PluginManifest.php`
- Test: `tests/Unit/CloudConnectorPolicyTest.php`

**Interfaces:**
- Produces: `PluginManifest::runtimeType(): string`, `credentialBoundary(): string`, `cloudProtocol(): string`, `permissions(): array`, `capabilities(): array`.
- Later tasks consume those methods in package installation and plugin discovery.

- [ ] **Step 1: Write the failing manifest test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class CloudConnectorPolicyTest extends TestCase
{
    public function testManifestExposesCloudConnectorSecurityFields(): void
    {
        $manifest = PluginManifest::fromJson(json_encode([
            'schema' => 1,
            'id' => 'cxpay.alipay.demo_connector',
            'slug' => 'alipay_demo_connector',
            'name' => '支付宝测试连接器',
            'version' => '1.0.0',
            'publisher' => 'cxpay.official',
            'payment_type' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => 'callback',
            'runtime_type' => 'cloud_connector',
            'credential_boundary' => 'cloud_only',
            'cloud_protocol' => 'cxpay-cloud-payment-v1',
            'capabilities' => ['external_monitor' => true],
            'permissions' => [
                'outbound_hosts' => ['api.provider.example'],
                'callbacks' => ['/notify/alipay_demo_connector'],
                'scheduled_tasks' => false,
                'secret_config' => ['client_secret', 'callback_secret'],
            ],
            'drivers' => [[
                'code' => 'alipay_demo_connector',
                'class' => 'plugin\\cxpay\\alipay_demo_connector\\Driver',
                'file' => 'src/Driver.php',
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('cloud_connector', $manifest->runtimeType());
        self::assertSame('cloud_only', $manifest->credentialBoundary());
        self::assertSame('cxpay-cloud-payment-v1', $manifest->cloudProtocol());
        self::assertSame(['api.provider.example'], $manifest->permissions()['outbound_hosts']);
    }
}
```

- [ ] **Step 2: Run the test and verify failure**

Run:

```bash
./vendor/bin/phpunit tests/Unit/CloudConnectorPolicyTest.php --filter testManifestExposesCloudConnectorSecurityFields
```

Expected: FAIL because the four accessor methods do not exist.

- [ ] **Step 3: Add accessors and strict field validation**

Add to `PluginManifest`:

```php
public function runtimeType(): string
{
    return (string)($this->data['runtime_type'] ?? '');
}

public function credentialBoundary(): string
{
    return (string)($this->data['credential_boundary'] ?? '');
}

public function cloudProtocol(): string
{
    return (string)($this->data['cloud_protocol'] ?? '');
}

/** @return array<string, mixed> */
public function permissions(): array
{
    return is_array($this->data['permissions'] ?? null) ? $this->data['permissions'] : [];
}

/** @return array<string, mixed> */
public function capabilities(): array
{
    return is_array($this->data['capabilities'] ?? null) ? $this->data['capabilities'] : [];
}
```

Inside `validate()`, require cloud connector fields when any one is present:

```php
$isCloudConnector = array_key_exists('runtime_type', $data)
    || array_key_exists('credential_boundary', $data)
    || array_key_exists('cloud_protocol', $data);

if ($isCloudConnector) {
    if (($data['runtime_type'] ?? '') !== 'cloud_connector') {
        throw new PluginException('云端连接器 runtime_type 必须为 cloud_connector');
    }
    if (($data['credential_boundary'] ?? '') !== 'cloud_only') {
        throw new PluginException('云端连接器 credential_boundary 必须为 cloud_only');
    }
    if (($data['cloud_protocol'] ?? '') !== 'cxpay-cloud-payment-v1') {
        throw new PluginException('云端连接器协议版本不受支持');
    }
}
```

- [ ] **Step 4: Run the focused test**

```bash
./vendor/bin/phpunit tests/Unit/CloudConnectorPolicyTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/payment/Plugin/PluginManifest.php tests/Unit/CloudConnectorPolicyTest.php
git commit -m "feat: extend cloud connector manifest contract"
```

---

### Task 2: Enforce Credential-Boundary Policy at Install and Enable Time

**Files:**
- Create: `app/payment/Plugin/CloudConnectorPolicy.php`
- Modify: `app/payment/Plugin/PluginPackageInstaller.php`
- Modify: `app/payment/Plugin/PluginManager.php`
- Test: `tests/Unit/CloudConnectorPolicyTest.php`
- Test: `tests/Unit/PluginPackageInstallerTest.php`

**Interfaces:**
- Produces: `CloudConnectorPolicy::assertManifest(PluginManifest $manifest): void` and `assertDriverMeta(PluginManifest $manifest, array $meta): void`.
- `PluginPackageInstaller` calls manifest validation before writing staging files.
- `PluginManager` calls runtime metadata validation before registering each driver.

- [ ] **Step 1: Add failing policy tests**

```php
public function testCloudOnlyManifestRejectsForbiddenSecretNames(): void
{
    $this->expectException(\app\payment\Plugin\PluginException::class);
    $this->expectExceptionMessage('Cookie');

    $manifest = $this->cloudManifest([
        'secret_config' => ['client_secret', 'cookie_base64'],
    ]);
    (new \app\payment\Plugin\CloudConnectorPolicy())->assertManifest($manifest);
}

public function testCloudOnlyDriverMetaRejectsCookieInput(): void
{
    $this->expectException(\app\payment\Plugin\PluginException::class);
    $this->expectExceptionMessage('Cookie');

    $manifest = $this->cloudManifest();
    (new \app\payment\Plugin\CloudConnectorPolicy())->assertDriverMeta($manifest, [
        'inputs' => [[
            'name' => 'cookie_base64',
            'title' => 'Cookie',
            'type' => 'textarea',
        ]],
    ]);
}
```

Add a private test helper that builds a valid `PluginManifest` with exact host and secret declarations.

- [ ] **Step 2: Verify tests fail**

```bash
./vendor/bin/phpunit tests/Unit/CloudConnectorPolicyTest.php
```

Expected: FAIL because `CloudConnectorPolicy` does not exist.

- [ ] **Step 3: Implement the policy**

Create:

```php
<?php

declare(strict_types=1);

namespace app\payment\Plugin;

final class CloudConnectorPolicy
{
    private const FORBIDDEN = [
        'cookie', 'set-cookie', 'browser_cookie', 'session_token',
        'login_token', 'web_token', 'device_token', 'browser_storage',
        'local_storage', 'session_storage', 'web_session',
    ];

    public function assertManifest(PluginManifest $manifest): void
    {
        if ($manifest->runtimeType() !== 'cloud_connector') {
            return;
        }
        if ($manifest->credentialBoundary() !== 'cloud_only') {
            throw new PluginException('云端连接器必须声明 cloud_only 凭据边界');
        }

        $permissions = $manifest->permissions();
        $hosts = $permissions['outbound_hosts'] ?? null;
        if (!is_array($hosts) || $hosts === []) {
            throw new PluginException('云端连接器必须声明精确 outbound_hosts');
        }
        foreach ($hosts as $host) {
            if (!is_string($host)
                || filter_var($host, FILTER_VALIDATE_IP)
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host)) {
                throw new PluginException('云端连接器 outbound_hosts 包含非法主机名');
            }
        }

        foreach ((array)($permissions['secret_config'] ?? []) as $name) {
            $this->assertSafeText((string)$name);
        }
    }

    /** @param array<string, mixed> $meta */
    public function assertDriverMeta(PluginManifest $manifest, array $meta): void
    {
        if ($manifest->runtimeType() !== 'cloud_connector') {
            return;
        }
        foreach ((array)($meta['inputs'] ?? []) as $input) {
            if (!is_array($input)) {
                continue;
            }
            $this->assertSafeText(implode(' ', [
                (string)($input['name'] ?? ''),
                (string)($input['title'] ?? ''),
                (string)($input['content'] ?? ''),
            ]));
        }
    }

    private function assertSafeText(string $value): void
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $value));
        foreach (self::FORBIDDEN as $forbidden) {
            $needle = str_replace('-', '_', $forbidden);
            if (str_contains($normalized, $needle)) {
                throw new PluginException('云端连接器禁止声明或接收 Cookie/网页登录凭据');
            }
        }
    }
}
```

- [ ] **Step 4: Wire policy into installation and discovery**

In `PluginPackageInstaller::install()` immediately after manifest parsing:

```php
$manifest = PluginManifest::fromJson($files['manifest.json']);
(new CloudConnectorPolicy())->assertManifest($manifest);
$this->verifySignature($manifest, $files['signature.json'], $files);
```

In `PluginManager::discoverEnabledDrivers()`, after loading the class and before registration:

```php
$instance = new $driver['class']();
(new CloudConnectorPolicy())->assertDriverMeta($manifest, $instance->getMeta());
PaymentManager::registerPluginDriver($driver['code'], $driver['class'], $pluginId);
```

- [ ] **Step 5: Add installer regression case**

Extend package test creation to accept extra manifest fields, then assert a correctly signed package containing `cookie_base64` is rejected before installation.

- [ ] **Step 6: Run focused tests**

```bash
./vendor/bin/phpunit tests/Unit/CloudConnectorPolicyTest.php tests/Unit/PluginPackageInstallerTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/payment/Plugin/CloudConnectorPolicy.php \
  app/payment/Plugin/PluginPackageInstaller.php \
  app/payment/Plugin/PluginManager.php \
  tests/Unit/CloudConnectorPolicyTest.php \
  tests/Unit/PluginPackageInstallerTest.php
git commit -m "feat: enforce cloud connector credential boundary"
```

---

### Task 3: Add the Hardened Connector HTTP Client

**Files:**
- Create: `app/payment/Plugin/CloudConnectorHttpClient.php`
- Modify: `config/payment_plugin.php`
- Test: `tests/Unit/CloudConnectorHttpClientTest.php`

**Interfaces:**
- Produces:

```php
public function request(
    string $method,
    string $path,
    array $config,
    array $allowedHosts,
    array $payload = []
): array;
```

- `ProviderClient` consumes this method.

- [ ] **Step 1: Write failing validation tests**

Cover rejection of HTTP, port 8443, URL credentials, IP-literal host, host outside allowlist, redirect response, unsigned response, and response body larger than the configured maximum.

Example:

```php
public function testRejectsEndpointOutsideManifestAllowlist(): void
{
    $this->expectException(PluginException::class);
    $this->expectExceptionMessage('白名单');

    $client = new CloudConnectorHttpClient($this->fakeTransport());
    $client->request('GET', '/v1/ops/status', [
        'monitor_base_url' => 'https://evil.example',
        'client_id' => 'cxpay-client-01',
        'client_secret' => str_repeat('s', 32),
        'callback_secret' => str_repeat('c', 32),
    ], ['api.provider.example']);
}
```

Use a constructor-injected callable transport in tests so no real network is used.

- [ ] **Step 2: Verify tests fail**

```bash
./vendor/bin/phpunit tests/Unit/CloudConnectorHttpClientTest.php
```

Expected: FAIL because the class does not exist.

- [ ] **Step 3: Implement endpoint validation and request signing**

The class must:

```php
$parts = parse_url($baseUrl);
if (!is_array($parts)
    || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
    || (int)($parts['port'] ?? 443) !== 443
    || isset($parts['user'])
    || isset($parts['pass'])
    || filter_var((string)($parts['host'] ?? ''), FILTER_VALIDATE_IP)
    || !in_array(strtolower((string)$parts['host']), $allowedHosts, true)) {
    throw new PluginException('云端支付服务地址不符合 HTTPS 443 和主机白名单要求');
}
```

Resolve every A/AAAA record and reject the endpoint if any address is private or reserved. The transport implementation must pin the validated address using cURL resolve semantics while retaining the original Host/SNI. Configure `allow_redirects=false`, TLS verification, connect timeout, total timeout, and response body limit from `config/payment_plugin.php`.

Compute the request signature over:

```php
$canonical = implode("\n", [
    strtoupper($method),
    $path,
    $timestamp,
    $nonce,
    hash('sha256', $body),
]);
```

Validate the signed response over:

```php
$canonicalResponse = implode("\n", [
    (string)$statusCode,
    $responseTimestamp,
    $responseNonce,
    hash('sha256', $responseBody),
]);
```

Return decoded JSON only after signature verification.

- [ ] **Step 4: Add config limits**

```php
'connector' => [
    'connect_timeout' => 3.0,
    'timeout' => 5.0,
    'max_response_bytes' => 524288,
    'clock_skew_seconds' => 300,
],
```

- [ ] **Step 5: Run focused tests**

```bash
./vendor/bin/phpunit tests/Unit/CloudConnectorHttpClientTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/payment/Plugin/CloudConnectorHttpClient.php \
  config/payment_plugin.php \
  tests/Unit/CloudConnectorHttpClientTest.php
git commit -m "feat: add hardened cloud connector client"
```

---

### Task 4: Add Callback Replay Protection

**Files:**
- Create: `app/payment/Plugin/CloudCallbackReplayGuard.php`
- Test: `tests/Unit/CloudCallbackReplayGuardTest.php`

**Interfaces:**
- Produces: `consume(string $providerId, string $eventId, string $nonce, int $timestamp): bool`.
- Returns `true` only for the first valid event/nonce combination.

- [ ] **Step 1: Write failing tests**

```php
public function testRejectsRepeatedEventAndNonce(): void
{
    $store = new InMemoryReplayStore();
    $guard = new CloudCallbackReplayGuard($store, 300, 604800);

    self::assertTrue($guard->consume('provider-a', 'evt-001', 'nonce-001', time()));
    self::assertFalse($guard->consume('provider-a', 'evt-001', 'nonce-002', time()));
    self::assertFalse($guard->consume('provider-a', 'evt-002', 'nonce-001', time()));
}
```

Also test expired timestamps and provider namespace isolation.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/CloudCallbackReplayGuardTest.php
```

- [ ] **Step 3: Implement Redis NX storage with injectable store**

Use two keys:

```text
cx:cloud_callback:event:{sha256(provider_id|event_id)}
cx:cloud_callback:nonce:{sha256(provider_id|nonce)}
```

Consume both atomically using a Lua script; if either exists, return false. Event IDs use seven-day TTL and nonces use five-minute TTL. Test through an in-memory adapter; production adapter uses `Webman\Redis\Client`.

- [ ] **Step 4: Run focused tests**

```bash
./vendor/bin/phpunit tests/Unit/CloudCallbackReplayGuardTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/payment/Plugin/CloudCallbackReplayGuard.php tests/Unit/CloudCallbackReplayGuardTest.php
git commit -m "feat: prevent cloud callback replay"
```

---

### Task 5: Refactor the Alipay Scan Monitor Connector

**Files:**
- Modify: `plugins-src/alipay-scan-monitor/manifest.json`
- Modify: `plugins-src/alipay-scan-monitor/src/Driver.php`
- Modify: `plugins-src/alipay-scan-monitor/src/ProviderClient.php`
- Modify: `plugins-src/alipay-scan-monitor/README.md`
- Modify: `tests/Unit/AlipayScanMonitorPluginTest.php`

**Interfaces:**
- `ProviderClient::__construct(?CloudConnectorHttpClient $client = null)`.
- `ProviderClient::getOrder(array $config, string $tradeNo): array`.
- `Driver::query(string $tradeNo, array $config): array` returns normalized `paid`, `trade_no`, `out_trade_no`, and string `amount`.

- [ ] **Step 1: Add failing Cookie-isolation and query tests**

```php
public function testDriverMetadataContainsNoCookieFieldOrInstruction(): void
{
    $meta = (new Driver())->getMeta();
    $json = strtolower(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    self::assertStringNotContainsString('cookie', $json);
    self::assertStringNotContainsString('手动粘贴', $json);
}

public function testManifestDeclaresCloudOnlyConnector(): void
{
    $manifest = PluginManifest::fromJson((string)file_get_contents(
        __DIR__ . '/../../plugins-src/alipay-scan-monitor/manifest.json'
    ));

    self::assertSame('cloud_connector', $manifest->runtimeType());
    self::assertSame('cloud_only', $manifest->credentialBoundary());
    self::assertSame('cxpay-cloud-payment-v1', $manifest->cloudProtocol());
}
```

Add a provider fake and assert `query()` returns paid data from `/v1/orders/{tradeNo}`.

- [ ] **Step 2: Run tests and verify failure**

```bash
./vendor/bin/phpunit tests/Unit/AlipayScanMonitorPluginTest.php
```

- [ ] **Step 3: Update the manifest**

Set version `1.2.0`; add:

```json
"runtime_type": "cloud_connector",
"credential_boundary": "cloud_only",
"cloud_protocol": "cxpay-cloud-payment-v1"
```

Replace descriptive `outbound_domains` with exact `outbound_hosts`. Correct capabilities to include `external_monitor=true`, `server_monitor=false`, and `order_query=true`.

- [ ] **Step 4: Remove Cookie metadata and normalize money as strings**

Delete `cookie_base64` and all manual Cookie instructions. Replace float normalization with:

```php
private function normalizeAmount(mixed $value): string
{
    $raw = trim((string)$value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw)) {
        throw new \RuntimeException('支付金额格式不合法');
    }
    $amount = number_format((float)$raw, 2, '.', '');
    if (bccomp($amount, '0.00', 2) <= 0 || bccomp($amount, '50000.00', 2) > 0) {
        throw new \RuntimeException('支付金额超出允许范围');
    }
    return $amount;
}
```

Keep the public result amount as a string; adjust tests accordingly.

- [ ] **Step 5: Replace direct Guzzle usage**

`ProviderClient` receives `CloudConnectorHttpClient` and calls:

```php
return $this->client->request($method, $path, $config, $this->allowedHosts($config), $payload);
```

Remove direct `new Client()` and `UrlGuard` usage.

- [ ] **Step 6: Implement remote query and replay guard**

`ProviderClient::getOrder()` calls `GET /v1/orders/{out_trade_no}`. `Driver::query()` maps `PAID` to:

```php
return [
    'paid' => true,
    'out_trade_no' => $tradeNo,
    'trade_no' => (string)$result['source_trade_no'],
    'amount' => $this->normalizeAmount($result['amount']),
];
```

`notify()` requires `provider_id`, `account_id`, `event_id`, and calls `CloudCallbackReplayGuard::consume()` after signature verification and before returning success.

- [ ] **Step 7: Update README terminology**

State explicitly that the installed component is a local connector and that Cookie exists only in the isolated cloud service. Remove every manual Cookie-import instruction.

- [ ] **Step 8: Run focused tests**

```bash
./vendor/bin/phpunit tests/Unit/AlipayScanMonitorPluginTest.php \
  tests/Unit/CloudConnectorPolicyTest.php \
  tests/Unit/CloudConnectorHttpClientTest.php \
  tests/Unit/CloudCallbackReplayGuardTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add plugins-src/alipay-scan-monitor tests/Unit/AlipayScanMonitorPluginTest.php
git commit -m "fix: isolate alipay cloud connector credentials"
```

---

### Task 6: Remove Forbidden Existing Connector Values Safely

**Files:**
- Create: `app/service/CloudCredentialCleanupService.php`
- Create: `tools/migrations/remove_cloud_connector_credentials.php`
- Test: `tests/Integration/CloudCredentialCleanupServiceTest.php`

**Interfaces:**
- Produces:

```php
public function scan(): array;
public function apply(): array;
```

- Return only channel IDs, driver codes, and removed key names; never values.

- [ ] **Step 1: Write failing integration tests**

Create a temporary SQLite schema with `cx_pay_channel`, insert an encrypted-looking `cookie_base64` value and safe connector fields, then assert:

```php
$scan = $service->scan();
self::assertSame([3], $scan['channel_ids']);
self::assertSame(['cookie_base64'], $scan['forbidden_keys']);
self::assertStringNotContainsString('sensitive-cookie-value', json_encode($scan));

$service->apply();
$config = json_decode((string)Channel::find(3)->config, true);
self::assertArrayNotHasKey('cookie_base64', $config);
self::assertArrayHasKey('account_id', $config);
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/CloudCredentialCleanupServiceTest.php
```

- [ ] **Step 3: Implement dry-run and transactional apply**

Only inspect channels whose `c_type` maps to an enabled or installed manifest with `credential_boundary=cloud_only`, plus the known `alipay_scan_monitor` migration target. Remove forbidden keys inside a transaction. Do not decrypt or print values.

- [ ] **Step 4: Add CLI wrapper**

Usage:

```text
php tools/migrations/remove_cloud_connector_credentials.php
php tools/migrations/remove_cloud_connector_credentials.php --apply
```

Default is dry-run. `--apply` prints counts and IDs only.

- [ ] **Step 5: Run test twice for idempotence**

```bash
./vendor/bin/phpunit tests/Integration/CloudCredentialCleanupServiceTest.php
```

Expected: PASS, including second `apply()` reporting zero removals.

- [ ] **Step 6: Commit**

```bash
git add app/service/CloudCredentialCleanupService.php \
  tools/migrations/remove_cloud_connector_credentials.php \
  tests/Integration/CloudCredentialCleanupServiceTest.php
git commit -m "feat: clean forbidden cloud connector credentials"
```

---

### Task 7: Add Repository-Wide Cookie Boundary Regression Tests

**Files:**
- Create: `tests/Unit/CloudCredentialBoundaryRepositoryTest.php`
- Modify: `tests/Unit/PluginPackageInstallerTest.php`

**Interfaces:**
- Produces a CI gate covering source metadata, manifest declarations, and API-facing driver inputs.

- [ ] **Step 1: Write the repository scan test**

Scan production files under:

```text
plugins-src/*/manifest.json
plugins-src/*/src/*.php
app/payment/Plugin/*.php
```

Allow occurrences only in the policy denylist, ADR/doc text, tests, and cleanup migration. Fail when a cloud connector production file contains `cookie_base64`, `document.cookie`, `localStorage`, or `sessionStorage`.

- [ ] **Step 2: Add metadata assertions for every cloud connector**

For every manifest with `runtime_type=cloud_connector`, load each declared driver and run:

```php
(new CloudConnectorPolicy())->assertDriverMeta($manifest, (new $class())->getMeta());
```

- [ ] **Step 3: Run the complete unit suite**

```bash
./vendor/bin/phpunit tests/Unit
```

Expected: PASS with no warnings or risky tests.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/CloudCredentialBoundaryRepositoryTest.php \
  tests/Unit/PluginPackageInstallerTest.php
git commit -m "test: gate cloud credential isolation"
```

---

### Task 8: Final Verification for the Connector Isolation Work Item

**Files:**
- No new implementation files.

**Interfaces:**
- Verifies the work item independently before PR creation.

- [ ] **Step 1: Run PHP syntax checks on every changed PHP file**

```bash
git diff --name-only work/epay-upstream-consolidation...HEAD -- '*.php' \
  | xargs -r -n1 php -l
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 2: Run focused tests**

```bash
./vendor/bin/phpunit \
  tests/Unit/CloudConnectorPolicyTest.php \
  tests/Unit/CloudConnectorHttpClientTest.php \
  tests/Unit/CloudCallbackReplayGuardTest.php \
  tests/Unit/AlipayScanMonitorPluginTest.php \
  tests/Unit/CloudCredentialBoundaryRepositoryTest.php \
  tests/Integration/CloudCredentialCleanupServiceTest.php
```

Expected: PASS, zero failures, zero errors, zero risky tests.

- [ ] **Step 3: Run the entire suite**

```bash
./vendor/bin/phpunit
```

Expected: PASS, zero failures and errors.

- [ ] **Step 4: Inspect the diff for forbidden secrets and scope drift**

```bash
git diff --check
git grep -nEi 'cookie_base64|document\.cookie|localStorage|sessionStorage' \
  -- 'plugins-src/*/src/*.php' 'plugins-src/*/manifest.json'
git status --short
```

Expected: no forbidden connector production references, no whitespace errors, and only intended files changed.

- [ ] **Step 5: Commit any verification-only corrections**

```bash
git add -A
git commit -m "chore: finalize cloud connector isolation"
```

Skip the commit when the working tree is already clean.
