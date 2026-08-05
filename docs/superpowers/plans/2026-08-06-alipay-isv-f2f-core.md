# Alipay ISV F2F Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a first-class `alipay_isv_f2f` driver that supports merchant authorization, `alipay.trade.precreate`, official callback verification, active query, and query-before-close reconciliation in sandbox and production-isolated environments.

**Architecture:** Platform credentials remain in `.env`; encrypted per-merchant `app_auth_token` values live in a dedicated authorization table. A testable gateway interface isolates Yansongda SDK calls from authorization, driver, controller, and reconciliation services. Existing `OrderService` remains the sole order creation, settlement, fee-release, and merchant-notification authority.

**Tech Stack:** PHP 8.1+, Webman 2.1, Illuminate Database/Redis, PHPUnit 10, Yansongda Pay 3.5, OpenSSL AES-256-GCM, BCMath.

## Global Constraints

- Driver code is exactly `alipay_isv_f2f`; never reuse removed IDs `alipay_official` or `alipay_scan_bill`.
- Payment mode is exactly `alipay.trade.precreate` with one dynamic QR code per CXPAY order.
- Sandbox and production AppID, private key, Alipay public key, gateway, authorization records, and production approvals are isolated.
- Production is disabled by default and cannot be enabled without a recorded sandbox verification and administrator approval.
- Platform private keys and Alipay public keys remain in `.env`/config and never enter channel rows or API responses.
- `app_auth_token` is stored only as AES-256-GCM ciphertext using the existing `APP_KEY` security boundary.
- One active authorization per `(merchant_id, environment, account_slot=default)` in phase one.
- Money remains a normalized two-decimal string throughout validation and settlement.
- `OrderService::markAsPaid()` remains the only successful settlement entry point.
- Expired official orders must be queried and, when still waiting, closed upstream before local fee release.
- No production migration, `.env` write, service restart, or real payment is executed by this plan.
- Use TDD and commit after every task.

---

## File Structure

**Create**

- `config/alipay_isv.php` — sandbox/production platform configuration.
- `app/payment/Alipay/AlipayEnvironmentConfig.php` — validated environment value object.
- `app/payment/Alipay/AlipayGatewayInterface.php` — SDK-independent official API contract.
- `app/payment/Alipay/YansongdaAlipayGateway.php` — Yansongda adapter.
- `app/payment/Alipay/AlipayGatewayFactory.php` — constructs a gateway for one environment.
- `app/payment/Alipay/AlipayResult.php` — normalized gateway result helpers.
- `app/model/AlipayAuthorization.php`
- `app/model/AlipayAuthorizationEvent.php`
- `app/model/AlipayProductionApproval.php`
- `app/service/AlipayAuthorizationService.php`
- `app/service/AlipayAuthorizationRepository.php`
- `app/service/AlipayTradeService.php`
- `app/service/AlipayTradeReconciliationService.php`
- `app/payment/Drivers/AlipayIsvF2f/Driver.php`
- `app/controller/admin/AlipayAuthorizationAdminController.php`
- `database/patch_v7_alipay_isv_f2f.sql`
- `tests/Unit/AlipayEnvironmentConfigTest.php`
- `tests/Unit/AlipayAuthorizationServiceTest.php`
- `tests/Unit/AlipayIsvF2fDriverTest.php`
- `tests/Unit/AlipayTradeServiceTest.php`
- `tests/Integration/AlipayAuthorizationPersistenceTest.php`
- `tests/Integration/AlipayOrderReconciliationTest.php`

**Modify**

- `.env.example` — names only, no real credentials.
- `config/route.php` — authorization callback and admin APIs.
- `app/controller/api/AlipayProtocolAdminController.php` — replace 501 placeholder with public callback/authorization-page behavior.
- `app/controller/api/MerchantChannelController.php` — pass merchant context and preserve disabled pre-authorization channels.
- `app/controller/notify/NotifyController.php` — pass normalized string amounts and strict official result fields.
- `app/service/OrderService.php` — string amount settlement and upstream-aware expiration.
- `app/service/ChannelMonitorService.php` — schedule official reconciliation before local expiration.
- `process/ChannelTimerProcess.php` — call the reconciliation service on a dedicated interval.

---

### Task 1: Add Validated Environment Configuration

**Files:**
- Create: `config/alipay_isv.php`
- Create: `app/payment/Alipay/AlipayEnvironmentConfig.php`
- Modify: `.env.example`
- Test: `tests/Unit/AlipayEnvironmentConfigTest.php`

**Interfaces:**
- Produces:

```php
public static function fromConfig(string $environment, array $config): self;
public function environment(): string;
public function enabled(): bool;
public function appId(): string;
public function privateKey(): string;
public function alipayPublicKey(): string;
public function gateway(): string;
public function authCallbackUrl(): string;
public function notifyBaseUrl(): string;
```

- `AlipayGatewayFactory`, driver validation, and authorization service consume this object.

- [ ] **Step 1: Write failing tests**

```php
public function testBuildsSandboxConfigAndRejectsMixedEnvironment(): void
{
    $config = AlipayEnvironmentConfig::fromConfig('sandbox', [
        'sandbox' => [
            'enabled' => true,
            'app_id' => '2026000000000001',
            'private_key' => $this->privateKeyPem(),
            'alipay_public_key' => $this->publicKeyPem(),
            'gateway' => 'https://openapi-sandbox.dl.alipaydev.com/gateway.do',
        ],
        'production' => [
            'enabled' => false,
            'app_id' => '2026000000000002',
        ],
        'auth_callback_url' => 'https://pay.example.com/api/alipay/confirm_auth',
        'notify_base_url' => 'https://pay.example.com',
    ]);

    self::assertSame('sandbox', $config->environment());
    self::assertTrue($config->enabled());
    self::assertSame('2026000000000001', $config->appId());
}

public function testRejectsEnabledEnvironmentWithInvalidPrivateKey(): void
{
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('应用私钥');

    AlipayEnvironmentConfig::fromConfig('sandbox', [
        'sandbox' => [
            'enabled' => true,
            'app_id' => '2026000000000001',
            'private_key' => 'not-a-key',
            'alipay_public_key' => $this->publicKeyPem(),
            'gateway' => 'https://openapi-sandbox.dl.alipaydev.com/gateway.do',
        ],
        'auth_callback_url' => 'https://pay.example.com/api/alipay/confirm_auth',
        'notify_base_url' => 'https://pay.example.com',
    ]);
}
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/AlipayEnvironmentConfigTest.php
```

- [ ] **Step 3: Add configuration file**

```php
<?php

return [
    'sandbox' => [
        'enabled' => filter_var(env('ALIPAY_ISV_SANDBOX_ENABLED', false), FILTER_VALIDATE_BOOL),
        'app_id' => (string)env('ALIPAY_ISV_SANDBOX_APP_ID', ''),
        'private_key' => (string)env('ALIPAY_ISV_SANDBOX_PRIVATE_KEY', ''),
        'alipay_public_key' => (string)env('ALIPAY_ISV_SANDBOX_PUBLIC_KEY', ''),
        'gateway' => (string)env('ALIPAY_ISV_SANDBOX_GATEWAY', 'https://openapi-sandbox.dl.alipaydev.com/gateway.do'),
    ],
    'production' => [
        'enabled' => filter_var(env('ALIPAY_ISV_PRODUCTION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'app_id' => (string)env('ALIPAY_ISV_PRODUCTION_APP_ID', ''),
        'private_key' => (string)env('ALIPAY_ISV_PRODUCTION_PRIVATE_KEY', ''),
        'alipay_public_key' => (string)env('ALIPAY_ISV_PRODUCTION_PUBLIC_KEY', ''),
        'gateway' => (string)env('ALIPAY_ISV_PRODUCTION_GATEWAY', 'https://openapi.alipay.com/gateway.do'),
    ],
    'auth_callback_url' => (string)env('ALIPAY_ISV_AUTH_CALLBACK_URL', ''),
    'notify_base_url' => (string)env('ALIPAY_ISV_NOTIFY_BASE_URL', ''),
];
```

- [ ] **Step 4: Implement strict value-object validation**

Validate environment enum, AppID shape, PEM parseability with OpenSSL, HTTPS gateway, HTTPS callback/base URLs, and enabled-environment completeness. Never include key content in exception messages.

- [ ] **Step 5: Add variable names to `.env.example`**

Use empty values and keep production disabled.

- [ ] **Step 6: Run focused tests**

```bash
./vendor/bin/phpunit tests/Unit/AlipayEnvironmentConfigTest.php
```

- [ ] **Step 7: Commit**

```bash
git add config/alipay_isv.php app/payment/Alipay/AlipayEnvironmentConfig.php \
  .env.example tests/Unit/AlipayEnvironmentConfigTest.php
git commit -m "feat: add validated alipay isv environments"
```

---

### Task 2: Define the Official Alipay Gateway Boundary

**Files:**
- Create: `app/payment/Alipay/AlipayGatewayInterface.php`
- Create: `app/payment/Alipay/AlipayResult.php`
- Create: `app/payment/Alipay/YansongdaAlipayGateway.php`
- Create: `app/payment/Alipay/AlipayGatewayFactory.php`
- Test: `tests/Unit/AlipayTradeServiceTest.php`

**Interfaces:**
- Produces:

```php
interface AlipayGatewayInterface
{
    public function authorizationUrl(string $state): string;
    public function exchangeAuthorizationCode(string $authCode): array;
    public function precreate(array $payload, string $appAuthToken): array;
    public function verifyNotify(array $payload): array;
    public function query(string $outTradeNo, string $appAuthToken): array;
    public function close(string $outTradeNo, string $appAuthToken): array;
}
```

- Refund methods are deliberately excluded and added in the separate refund plan.

- [ ] **Step 1: Write a failing factory test**

```php
public function testFactoryReturnsGatewayForValidatedEnvironment(): void
{
    $factory = new AlipayGatewayFactory(static fn(array $sdkConfig) => new FakePayClient($sdkConfig));
    $gateway = $factory->make($this->sandboxConfig());

    self::assertInstanceOf(AlipayGatewayInterface::class, $gateway);
}
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/AlipayTradeServiceTest.php --filter testFactoryReturnsGatewayForValidatedEnvironment
```

- [ ] **Step 3: Add the gateway interface and normalized result helpers**

`AlipayResult` should provide exact string extraction and error classification:

```php
public static function requiredString(array $data, string $key): string;
public static function money(array $data, string $key): string;
public static function successful(array $data): bool;
public static function retryable(array $data): bool;
```

- [ ] **Step 4: Implement the Yansongda adapter**

Map Yansongda 3.5 calls inside one class only. The adapter returns plain arrays and converts SDK exceptions into a domain exception containing method, safe code, retryable flag, and result-uncertain flag without embedding secret material.

- [ ] **Step 5: Run focused tests using fakes only**

```bash
./vendor/bin/phpunit tests/Unit/AlipayTradeServiceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/payment/Alipay tests/Unit/AlipayTradeServiceTest.php
git commit -m "feat: add alipay gateway boundary"
```

---

### Task 3: Add Authorization and Production-Approval Persistence

**Files:**
- Create: `database/patch_v7_alipay_isv_f2f.sql`
- Create: `app/model/AlipayAuthorization.php`
- Create: `app/model/AlipayAuthorizationEvent.php`
- Create: `app/model/AlipayProductionApproval.php`
- Create: `app/service/AlipayAuthorizationRepository.php`
- Test: `tests/Integration/AlipayAuthorizationPersistenceTest.php`

**Interfaces:**
- Produces:

```php
public function activeForMerchant(int $merchantId, string $environment, string $accountSlot = 'default'): ?AlipayAuthorization;
public function findOwned(int $authorizationId, int $merchantId): ?AlipayAuthorization;
public function saveActive(array $attributes, string $plainToken): AlipayAuthorization;
public function markStatus(int $authorizationId, string $status, array $context = []): void;
public function appendEvent(int $authorizationId, string $eventType, array $context): void;
```

- Driver and authorization service consume this repository.

- [ ] **Step 1: Write failing persistence tests**

Create a temporary database and assert:

```php
$authorization = $repository->saveActive([
    'merchant_id' => 7,
    'environment' => 'sandbox',
    'account_slot' => 'default',
    'platform_app_id' => '2026000000000001',
    'alipay_user_id' => '2088000000000001',
    'merchant_pid' => '2088000000000001',
], 'secret-app-auth-token');

self::assertNotSame('secret-app-auth-token', $authorization->app_auth_token_cipher);
self::assertSame('secret-app-auth-token', $repository->decryptToken($authorization));
self::assertSame($authorization->id, $repository->activeForMerchant(7, 'sandbox')->id);
```

Also assert the unique `(merchant_id, environment, account_slot)` rule and event append behavior.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/AlipayAuthorizationPersistenceTest.php
```

- [ ] **Step 3: Write the migration**

Create:

- `cx_alipay_authorization` with status, encrypted token, merchant identity, timestamps, and unique key;
- `cx_alipay_authorization_event` with append-only audit data;
- `cx_alipay_production_approval` with unique merchant/channel scope, `sandbox_verified_at`, `approved_at`, and `approved_by`;
- order columns `upstream_state`, `upstream_last_query_at`, `upstream_query_attempts`, `upstream_close_status`, `upstream_last_error_code`, `upstream_last_error_at` using `ADD COLUMN IF NOT EXISTS`.

Do not store auth codes or raw callback bodies.

- [ ] **Step 4: Implement models and repository**

Use existing Eloquent style (`$timestamps=false`, `$guarded=[]`). Encrypt tokens with `support\Authcode::encrypt()` and decrypt with `decryptStored()`.

When replacing an active authorization, lock the unique row, append a `replaced` event, update encrypted token and merchant identity, and set status `active` in one transaction.

- [ ] **Step 5: Run persistence tests**

```bash
./vendor/bin/phpunit tests/Integration/AlipayAuthorizationPersistenceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add database/patch_v7_alipay_isv_f2f.sql app/model/AlipayAuthorization.php \
  app/model/AlipayAuthorizationEvent.php app/model/AlipayProductionApproval.php \
  app/service/AlipayAuthorizationRepository.php \
  tests/Integration/AlipayAuthorizationPersistenceTest.php
git commit -m "feat: persist alipay merchant authorizations"
```

---

### Task 4: Implement One-Time Merchant Authorization

**Files:**
- Create: `app/service/AlipayAuthorizationService.php`
- Modify: `app/payment/Alipay/AlipayGatewayInterface.php`
- Modify: `app/payment/Alipay/YansongdaAlipayGateway.php`
- Test: `tests/Unit/AlipayAuthorizationServiceTest.php`

**Interfaces:**
- Produces:

```php
public function start(int $merchantId, string $environment, string $accountSlot, string $operatorType, int $operatorId): array;
public function poll(int $merchantId, string $sessionId): array;
public function confirm(string $state, string $authCode): array;
public function revoke(int $merchantId, int $authorizationId, string $operatorType, int $operatorId): void;
```

- Redis session shape is stable and consumed by controllers and driver authorization methods.

- [ ] **Step 1: Write failing authorization tests**

Test start returns a 10-minute session and URL; confirm consumes state once; wrong merchant cannot poll; repeated callback fails; token is encrypted; authorization event is appended.

Example:

```php
$result = $service->start(7, 'sandbox', 'default', 'merchant', 7);
self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,64}$/', $result['session_id']);
self::assertStringContainsString('state=', $result['authorization_url']);
self::assertLessThanOrEqual(time() + 600, $result['expires_at']);
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/AlipayAuthorizationServiceTest.php
```

- [ ] **Step 3: Implement Redis-backed state**

Store only a SHA-256 state hash, merchant/environment/account slot/operator metadata, expiry, result status, and authorization ID. Use `random_bytes(32)` for session and state values. Consume state atomically with a Redis Lua script.

- [ ] **Step 4: Exchange auth code and bind merchant identity**

`confirm()` must:

1. atomically consume state;
2. call `exchangeAuthorizationCode()`;
3. require non-empty `app_auth_token`, `user_id`/merchant identity, and current platform AppID;
4. save encrypted token through the repository;
5. append `authorization_confirmed`;
6. store only the final result reference in the session;
7. never log or return `auth_code` or token.

- [ ] **Step 5: Run focused tests**

```bash
./vendor/bin/phpunit tests/Unit/AlipayAuthorizationServiceTest.php \
  tests/Integration/AlipayAuthorizationPersistenceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/service/AlipayAuthorizationService.php \
  app/payment/Alipay/AlipayGatewayInterface.php \
  app/payment/Alipay/YansongdaAlipayGateway.php \
  tests/Unit/AlipayAuthorizationServiceTest.php
git commit -m "feat: add alipay merchant authorization flow"
```

---

### Task 5: Expose Merchant and Administrator Authorization APIs

**Files:**
- Modify: `app/controller/api/AlipayProtocolAdminController.php`
- Create: `app/controller/admin/AlipayAuthorizationAdminController.php`
- Modify: `app/controller/api/MerchantChannelController.php`
- Modify: `config/route.php`
- Test: `tests/Integration/AlipayAuthorizationApiTest.php`

**Interfaces:**
- Merchant channel start/poll remains compatible with existing paths.
- Public callback is `GET|POST /api/alipay/confirm_auth`.
- Admin APIs return only status and masked identifiers.

- [ ] **Step 1: Write failing API tests**

Assert:

- unauthenticated merchant start returns 401;
- merchant can start only for owned channel;
- controller passes `merchant_id`, `channel_id`, and environment to the driver/service;
- callback with missing state or auth code returns 400;
- poll response never contains token;
- admin detail contains masked PID and no ciphertext.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/AlipayAuthorizationApiTest.php
```

- [ ] **Step 3: Replace the 501 placeholder**

Use `AlipayProtocolAdminController` only for public auth landing/callback compatibility. `confirmAuth()` calls `AlipayAuthorizationService::confirm()` and renders a safe success/failure page; it does not accept merchant ID from the callback request.

- [ ] **Step 4: Pass merchant context from MerchantChannelController**

Before `PaymentManager::startAccountAuthorization()`:

```php
$config['channel_id'] = (int)$channel->id;
$config['merchant_id'] = (int)$channel->merchant_id;
$config['operator_type'] = 'merchant';
$config['operator_id'] = (int)$channel->merchant_id;
```

Permit a disabled `alipay_isv_f2f` channel to be saved with empty `authorization_id`; reject enabling it until authorization is active.

- [ ] **Step 5: Add admin routes and controller**

Add list/detail/diagnose/revoke/production-approve routes under the existing admin middleware group. Mask merchant PID except the last four characters. Approval stores administrator identity from the authenticated session.

- [ ] **Step 6: Run API tests**

```bash
./vendor/bin/phpunit tests/Integration/AlipayAuthorizationApiTest.php
```

- [ ] **Step 7: Commit**

```bash
git add app/controller/api/AlipayProtocolAdminController.php \
  app/controller/admin/AlipayAuthorizationAdminController.php \
  app/controller/api/MerchantChannelController.php config/route.php \
  tests/Integration/AlipayAuthorizationApiTest.php
git commit -m "feat: expose alipay authorization APIs"
```

---

### Task 6: Implement the `alipay_isv_f2f` Driver

**Files:**
- Create: `app/payment/Drivers/AlipayIsvF2f/Driver.php`
- Create: `app/service/AlipayTradeService.php`
- Test: `tests/Unit/AlipayIsvF2fDriverTest.php`
- Test: `tests/Unit/AlipayTradeServiceTest.php`

**Interfaces:**
- Driver implements `PaymentDriverInterface`, `MonitorableDriverInterface`, and `AccountAuthorizationInterface`.
- `monitorMode()` returns `MODE_CALLBACK`.
- `AlipayTradeService` exposes `precreate()`, `query()`, `close()`, and `verifyNotification()`.

- [ ] **Step 1: Write failing metadata and validation tests**

```php
public function testMetadataDeclaresOfficialDynamicQrChannel(): void
{
    $driver = $this->driver();
    $meta = $driver->getMeta();

    self::assertSame('alipay_isv_f2f', $meta['name']);
    self::assertSame('alipay', $meta['pay_category']);
    self::assertSame('official_api', $meta['collection_mode']);
    self::assertTrue($meta['supports_account_authorization']);
    self::assertSame(MonitorableDriverInterface::MODE_CALLBACK, $driver->monitorMode());
}
```

Also test disabled pre-auth save succeeds, enabled pre-auth save fails, cross-merchant authorization fails, sandbox authorization cannot configure production, and production requires approval.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/AlipayIsvF2fDriverTest.php
```

- [ ] **Step 3: Implement `getMeta()` and `upchannel()`**

Inputs are exactly:

```php
[
    ['name' => 'environment', 'title' => '支付宝环境', 'type' => 'select', 'required' => true],
    ['name' => 'authorization_id', 'title' => '商家授权', 'type' => 'string', 'required' => false],
    ['name' => 'store_id', 'title' => '门店编号（可选）', 'type' => 'string', 'required' => false],
    ['name' => 'timeout_minutes', 'title' => '二维码有效分钟数', 'type' => 'number', 'required' => true, 'default' => '3'],
]
```

Do not include platform AppID, private key, public key, or token fields.

- [ ] **Step 4: Implement dynamic QR precreate**

Map driver params:

```php
$payload = [
    'out_trade_no' => (string)$params['trade_no'],
    'total_amount' => $this->normalizeAmount($params['money']),
    'subject' => mb_substr((string)($params['name'] ?? '网络支付'), 0, 256),
    'notify_url' => (string)$params['notify_url'],
    'timeout_express' => $this->timeoutExpress((int)$params['expire_time']),
];
```

Return:

```php
[
    'type' => 'qrcode',
    'pay_url' => $result['qr_code'],
    'trade_no' => (string)$params['trade_no'],
    'out_trade_no' => (string)$params['out_trade_no'],
    'amount' => $amount,
]
```

- [ ] **Step 5: Implement query and notification normalization**

Successful notification/query results return string amount and include `app_id`, `seller_id`, `trade_status`, `out_trade_no`, and `trade_no` for downstream validation.

- [ ] **Step 6: Delegate account authorization methods**

`startAccountAuthorization()` and `pollAccountAuthorization()` call `AlipayAuthorizationService` with merchant and environment values from config.

- [ ] **Step 7: Run driver tests**

```bash
./vendor/bin/phpunit tests/Unit/AlipayIsvF2fDriverTest.php tests/Unit/AlipayTradeServiceTest.php
```

- [ ] **Step 8: Commit**

```bash
git add app/payment/Drivers/AlipayIsvF2f app/service/AlipayTradeService.php \
  tests/Unit/AlipayIsvF2fDriverTest.php tests/Unit/AlipayTradeServiceTest.php
git commit -m "feat: add alipay isv f2f driver"
```

---

### Task 7: Harden Official Notification Settlement and Decimal Amounts

**Files:**
- Modify: `app/controller/notify/NotifyController.php`
- Modify: `app/service/OrderService.php`
- Test: `tests/Integration/AlipayOfficialNotifySettlementTest.php`

**Interfaces:**
- `OrderService::markAsPaid(string $orderNo, string $channelTradeNo, string|int|float $amount, ?int $channelId = null, bool $validateAmount = true): bool`.
- Driver official results must be checked against environment config and authorization identity before settlement.

- [ ] **Step 1: Write failing notification tests**

Cover valid success, wrong AppID, wrong seller PID, wrong amount, wrong channel, unsupported trade status, duplicate notification, and query/notify race.

Example:

```php
self::assertFalse($this->notify([
    'app_id' => 'wrong-app-id',
    'seller_id' => '2088000000000001',
    'out_trade_no' => $order->trade_no,
    'total_amount' => '18.88',
    'trade_status' => 'TRADE_SUCCESS',
]));
self::assertSame(0, Order::find($order->id)->status);
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/AlipayOfficialNotifySettlementTest.php
```

- [ ] **Step 3: Change settlement amount handling to strings**

Update the public signature and normalize with existing money helpers before `bccomp()`. Remove unnecessary `(float)` conversion in `NotifyController::settleResult()`.

- [ ] **Step 4: Add official field validation before settlement**

For `alipay_isv_f2f`, validate:

```text
app_id == environment AppID
seller_id == authorization merchant_pid
out_trade_no == CXPAY trade_no
total_amount == order price
trade_status in TRADE_SUCCESS, TRADE_FINISHED
channel authorization/environment match the order channel
```

Do not return which field failed to the public caller; log a request ID and safe mismatch category.

- [ ] **Step 5: Run focused tests**

```bash
./vendor/bin/phpunit tests/Integration/AlipayOfficialNotifySettlementTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/controller/notify/NotifyController.php app/service/OrderService.php \
  tests/Integration/AlipayOfficialNotifySettlementTest.php
git commit -m "fix: verify alipay official settlement fields"
```

---

### Task 8: Add Active Query and Query-Before-Close Reconciliation

**Files:**
- Create: `app/service/AlipayTradeReconciliationService.php`
- Modify: `app/service/ChannelMonitorService.php`
- Modify: `app/service/OrderService.php`
- Modify: `process/ChannelTimerProcess.php`
- Test: `tests/Integration/AlipayOrderReconciliationTest.php`

**Interfaces:**
- Produces:

```php
public function reconcileDue(int $limit = 100): array;
public function reconcileOrder(Order $order): string;
```

- Result strings are exactly `paid`, `waiting`, `closed`, `retry`, `reauth_required`, or `skipped`.

- [ ] **Step 1: Write failing reconciliation tests**

Test:

- query says paid -> same settlement entry point;
- query says waiting before expiry -> no close;
- expired + waiting -> upstream close then local close;
- close reports already paid -> settle instead;
- timeout/uncertain -> local order remains pending with `upstream_state=uncertain`;
- invalid auth -> authorization becomes `reauth_required` and channel goes offline;
- repeated workers do not process the same claimed order concurrently.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/AlipayOrderReconciliationTest.php
```

- [ ] **Step 3: Implement due-order selection and claim**

Select only pending orders on `alipay_isv_f2f` channels. Claim rows with a short database/Redis lease keyed by order ID. Query cadence uses persisted `upstream_last_query_at` and attempts.

- [ ] **Step 4: Implement query-before-close state machine**

Use the original CXPAY `trade_no` for every query and close. Only call `OrderService::closePendingOrder()` after upstream returns a definite close success. Never release the reserved fee on timeout or unknown result.

- [ ] **Step 5: Exclude official orders from blind local expiration**

Modify `OrderService::expirePendingOrders()` to skip orders whose bound channel type is `alipay_isv_f2f`; they are handled by reconciliation.

- [ ] **Step 6: Schedule reconciliation**

Instantiate `AlipayTradeReconciliationService` in `ChannelTimerProcess` and call it every 15 seconds inside its own try/catch. Keep existing timers intact.

- [ ] **Step 7: Run focused tests**

```bash
./vendor/bin/phpunit tests/Integration/AlipayOrderReconciliationTest.php \
  tests/Integration/AlipayOfficialNotifySettlementTest.php
```

- [ ] **Step 8: Commit**

```bash
git add app/service/AlipayTradeReconciliationService.php \
  app/service/ChannelMonitorService.php app/service/OrderService.php \
  process/ChannelTimerProcess.php tests/Integration/AlipayOrderReconciliationTest.php
git commit -m "feat: reconcile and close alipay orders safely"
```

---

### Task 9: Add Production Approval and Channel Readiness Diagnostics

**Files:**
- Modify: `app/controller/admin/AlipayAuthorizationAdminController.php`
- Modify: `app/payment/Drivers/AlipayIsvF2f/Driver.php`
- Modify: `app/service/AlipayAuthorizationService.php`
- Test: `tests/Integration/AlipayProductionApprovalTest.php`

**Interfaces:**
- Production approval stores `merchant_id`, `authorization_id`, optional `channel_id`, `sandbox_verified_at`, `approved_at`, and `approved_by`.
- Driver `upchannel()` consumes approval status.

- [ ] **Step 1: Write failing approval tests**

Assert production channel enable fails when:

- production env flag is false;
- sandbox verification is missing;
- admin approval is missing;
- approval belongs to another merchant or authorization.

Assert a correctly approved channel passes readiness validation.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/AlipayProductionApprovalTest.php
```

- [ ] **Step 3: Implement approval command**

The admin endpoint must require a previously recorded successful sandbox end-to-end marker and store authenticated administrator ID. Re-approval is append-audited.

- [ ] **Step 4: Add diagnostic response**

Return booleans only:

```json
{
  "environment_enabled": true,
  "authorization_active": true,
  "merchant_identity_present": true,
  "sandbox_verified": true,
  "production_approved": true,
  "channel_ready": true
}
```

Never include token or key material.

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit tests/Integration/AlipayProductionApprovalTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/controller/admin/AlipayAuthorizationAdminController.php \
  app/payment/Drivers/AlipayIsvF2f/Driver.php \
  app/service/AlipayAuthorizationService.php \
  tests/Integration/AlipayProductionApprovalTest.php
git commit -m "feat: gate alipay production activation"
```

---

### Task 10: Core Work Item Verification

**Files:**
- No new implementation files.

**Interfaces:**
- Verifies the official payment core before the refund plan begins.

- [ ] **Step 1: Run syntax checks**

```bash
git diff --name-only work/epay-upstream-consolidation...HEAD -- '*.php' \
  | xargs -r -n1 php -l
```

Expected: no syntax errors.

- [ ] **Step 2: Run official-payment focused tests**

```bash
./vendor/bin/phpunit \
  tests/Unit/AlipayEnvironmentConfigTest.php \
  tests/Unit/AlipayAuthorizationServiceTest.php \
  tests/Unit/AlipayIsvF2fDriverTest.php \
  tests/Unit/AlipayTradeServiceTest.php \
  tests/Integration/AlipayAuthorizationPersistenceTest.php \
  tests/Integration/AlipayAuthorizationApiTest.php \
  tests/Integration/AlipayOfficialNotifySettlementTest.php \
  tests/Integration/AlipayOrderReconciliationTest.php \
  tests/Integration/AlipayProductionApprovalTest.php
```

Expected: zero failures, errors, warnings, or risky tests.

- [ ] **Step 3: Run the complete suite**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 4: Inspect sensitive-data and removed-driver boundaries**

```bash
git diff --check
git grep -nE 'ALIPAY_ISV_.*(PRIVATE|PUBLIC)|app_auth_token' -- ':!docs' ':!tests'
git grep -nE 'alipay_official|alipay_scan_bill' -- app config public plugins-src \
  ':!app/payment/RemovedPaymentDrivers.php'
git status --short
```

Review every match. Expected: keys appear only as config reads; tokens appear only in encrypted repository/service handling; removed IDs are not restored.

- [ ] **Step 5: Record sandbox manual acceptance as pending**

Do not claim sandbox completion. Add a PR checklist showing the twelve manual sandbox scenarios remain blocked until real sandbox credentials and a controlled deployment are provided.

- [ ] **Step 6: Commit any verification corrections**

```bash
git add -A
git commit -m "chore: finalize alipay isv f2f core"
```

Skip when the working tree is clean.
