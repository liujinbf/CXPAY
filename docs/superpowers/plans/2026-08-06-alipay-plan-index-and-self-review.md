# Alipay Workstream Plan Index and Self-Review Corrections

> **For agentic workers:** Read this file before executing any of the three implementation plans. The corrections below are mandatory and supersede conflicting snippets in the individual plan files.

## Execution Order

1. `2026-08-06-cloud-connector-credential-isolation.md`
2. `2026-08-06-alipay-isv-f2f-core.md`
3. `2026-08-06-alipay-refund-workflow.md`

The first plan can be reviewed and merged independently. The refund plan depends on the gateway, authorization, driver, notification, query, and close boundaries created by the core plan.

## Self-Review Result

The approved design is covered by the three plans:

- cloud Cookie isolation, signed local connector, plugin policy, hardened HTTP, replay protection, cleanup, and CI gates;
- official Alipay environment configuration, ISV merchant authorization, encrypted token persistence, precreate, notify, query, close, and production approval;
- full/multiple partial refunds, mandatory review, amount reservation, idempotency, execution claim, result query, uncertainty recovery, and audit events.

No `TODO`, `TBD`, or “similar to another task” placeholders are permitted. Manual sandbox and production acceptance remain explicitly unverified until real credentials and a controlled deployment are available.

## Mandatory Correction 1: One Decimal-String Normalizer

The individual plans contain example snippets using `number_format((float)...)`. Those snippets are superseded. Payment and refund code must not convert money through binary floating point.

Create:

```text
support/DecimalMoney.php
```

with this exact public boundary:

```php
<?php

declare(strict_types=1);

namespace support;

use RuntimeException;

final class DecimalMoney
{
    public static function normalize(mixed $value, string $field = '金额'): string
    {
        $raw = trim((string)$value);
        if (!preg_match('/^(0|[1-9]\d{0,7})(?:\.(\d{1,2}))?$/', $raw, $matches)) {
            throw new RuntimeException($field . '格式不合法');
        }

        $whole = ltrim($matches[1], '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = str_pad((string)($matches[2] ?? ''), 2, '0');
        return $whole . '.' . $fraction;
    }

    public static function requirePositive(mixed $value, string $field = '金额'): string
    {
        $amount = self::normalize($value, $field);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new RuntimeException($field . '必须大于 0');
        }
        return $amount;
    }
}
```

Add:

```text
tests/Unit/DecimalMoneyTest.php
```

covering `0`, `1`, `1.2`, `1.23`, leading-zero rejection, three-decimal rejection, negative rejection, exponent rejection, and the maximum accepted whole-number width.

Use `DecimalMoney::requirePositive()` in:

- `plugins-src/alipay-scan-monitor/src/Driver.php`;
- `app/payment/Drivers/AlipayIsvF2f/Driver.php`;
- `app/payment/Alipay/AlipayResult.php`;
- `app/service/OrderService.php` settlement input;
- `app/service/RefundApplicationService.php`;
- `app/service/AlipayRefundService.php`.

All comparisons and additions use `bccomp`, `bcadd`, and `bcsub`.

## Mandatory Correction 2: Define Replay Store Types Explicitly

The connector plan references an in-memory replay store without defining its production interface. Add these files to Task 4 of that plan:

```text
app/payment/Plugin/CloudReplayStoreInterface.php
app/payment/Plugin/RedisCloudReplayStore.php
```

Exact interface:

```php
interface CloudReplayStoreInterface
{
    public function consume(
        string $eventKey,
        string $nonceKey,
        int $eventTtlSeconds,
        int $nonceTtlSeconds
    ): bool;
}
```

`RedisCloudReplayStore` must use one Lua script so event ID and nonce are consumed atomically. `CloudCallbackReplayGuard` receives `CloudReplayStoreInterface` in its constructor. The unit test defines a private in-memory implementation in `tests/Unit/CloudCallbackReplayGuardTest.php`.

## Mandatory Correction 3: Define HTTP Test Transport Explicitly

`CloudConnectorHttpClient` uses this constructor:

```php
/** @param null|callable(array<string,mixed>):array<string,mixed> $transport */
public function __construct(?callable $transport = null)
```

The callable receives method, URL, headers, body, validated IP, original host, timeouts, and maximum response bytes. It returns status, headers, and raw body. Production uses the internal Guzzle/cURL transport; unit tests inject a deterministic callable and perform no network calls.

## Mandatory Correction 4: Source Scan Exception Is Narrow

The repository Cookie scan may allow the literal `Set-Cookie` only in:

```text
app/payment/Plugin/CloudConnectorHttpClient.php
```

where it is discarded. It remains forbidden in connector manifests, driver metadata, provider clients, controllers, database configuration, logs, and API responses.

## Mandatory Correction 5: Driver Dependency Injection

For testability, the Alipay cloud connector driver uses:

```php
public function __construct(
    ?ProviderClient $providerClient = null,
    ?CloudCallbackReplayGuard $replayGuard = null
)
```

and the official driver uses:

```php
public function __construct(
    ?AlipayTradeService $tradeService = null,
    ?AlipayAuthorizationService $authorizationService = null,
    ?AlipayAuthorizationRepository $authorizationRepository = null
)
```

No test may patch global SDK state or perform real network calls.

## Mandatory Correction 6: Complete Test File Maps

Add these files to the core plan File Structure section:

```text
tests/Integration/AlipayAuthorizationApiTest.php
tests/Integration/AlipayOfficialNotifySettlementTest.php
tests/Integration/AlipayProductionApprovalTest.php
```

Add this file to the refund plan File Structure section:

```text
tests/Integration/RefundAuditSecurityTest.php
```

Add `support/DecimalMoney.php` and `tests/Unit/DecimalMoneyTest.php` to both the connector/core/refund workstream dependency maps; implement it once in the first executed plan and reuse it later.

## Mandatory Correction 7: Refunds Do Not Require the Authorization Row to Stay Active

A paid order retains its original authorization reference. Refund submission must not be rejected only because the authorization status later became `reauth_required` or `revoked`.

Rules:

- the original authorization record and encrypted token are never deleted while refundable orders exist;
- submission validates the paid official order and remaining refundable amount;
- execution attempts to use the original token;
- an official authorization failure moves the refund to `uncertain` or definite `failed` according to the gateway result, marks the authorization `reauth_required`, and raises an administrator action requirement;
- after reauthorization, retry/query continues with the same refund row and same `out_request_no`;
- no new refund request is created to work around authorization failure.

This supersedes the refund-plan global sentence requiring an “active” authorization at submission time.

## Mandatory Correction 8: Official Alipay API Names

The gateway adapter must map the domain interface to these official API operations:

```text
alipay.open.auth.token.app
alipay.trade.precreate
alipay.trade.query
alipay.trade.close
alipay.trade.refund
alipay.trade.fastpay.refund.query
```

SDK method names may differ in Yansongda Pay 3.5; the adapter must be verified against the installed package source before implementation. Domain services and tests use only `AlipayGatewayInterface` and do not import SDK request classes.

## Mandatory Correction 9: Authorization Identity Verification

`exchangeAuthorizationCode()` returns a normalized result containing at least:

```php
[
    'app_auth_token' => '...',
    'app_refresh_token' => '',
    'auth_app_id' => '...',
    'user_id' => '...',
    'expires_in' => 0,
    're_expires_in' => 0,
]
```

Persist `app_refresh_token` only when the official response supplies it, encrypted under the same boundary as `app_auth_token`. The migration therefore includes `app_refresh_token_cipher`, `token_expires_at`, and `refresh_token_expires_at`.

Reject the callback when `auth_app_id` does not equal the environment AppID. Do not infer the merchant PID from request input. Use the official response identity and subsequent official capability/merchant verification when required.

## Mandatory Correction 10: Plan and Branch Boundaries

Execution must create isolated worktrees using `superpowers:using-git-worktrees` before modifying code.

Recommended branches:

```text
work/cloud-connector-credential-isolation
work/alipay-isv-f2f-core
work/alipay-refund-workflow
```

Do not implement all three work items in the documentation branch merely because the plan files currently reside there. Each PR has its own test evidence and review gate.
