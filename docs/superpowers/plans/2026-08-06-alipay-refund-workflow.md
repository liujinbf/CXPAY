# Alipay Refund Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add full and repeated partial Alipay refunds with merchant API submission, mandatory administrator review, idempotent execution, active result query, uncertain-result compensation, and append-only audit events.

**Architecture:** A refund aggregate and event table own the refund state machine. All merchant and administrator entry points call one application service that locks the paid order and all amount-reserving refunds before accepting a request. The Alipay gateway adds refund/query methods, while a background reconciliation service resolves `executing` and `uncertain` records without generating new request numbers.

**Tech Stack:** PHP 8.1+, Webman 2.1, Illuminate Database, PHPUnit 10, Yansongda Pay 3.5, BCMath, existing administrator/merchant authentication middleware.

## Global Constraints

- Supports full refund and multiple partial refunds.
- Every refund uses one stable `out_request_no`; retries never create another request number.
- Reserved total is `succeeded + pending_review + approved + executing + uncertain` and may not exceed the original paid amount.
- `rejected`, `cancelled`, and definite `failed` records do not reserve amount.
- Phase one always produces `pending_review`; automatic refund execution remains disabled.
- Merchant console and merchant API may submit; only administrators may approve or reject.
- Administrators cannot edit provider results or manually mark a refund successful.
- Result-uncertain refunds continue reserving amount until an official query resolves them.
- Refund execution is allowed only for a successfully paid `alipay_isv_f2f` order with an active/usable original authorization context.
- All amount values are normalized two-decimal strings and use `bc*` arithmetic.
- No production database migration, `.env` edit, restart, or real refund is executed by this plan.
- Use TDD and commit after every task.

---

## File Structure

**Create**

- `app/model/Refund.php`
- `app/model/RefundEvent.php`
- `app/service/RefundApplicationService.php`
- `app/service/RefundRiskService.php`
- `app/service/AlipayRefundService.php`
- `app/service/AlipayRefundReconciliationService.php`
- `app/controller/api/RefundApiController.php`
- `app/controller/api/MerchantRefundController.php`
- `app/controller/admin/RefundAdminController.php`
- `database/patch_v8_alipay_refund.sql`
- `tests/Unit/RefundRiskServiceTest.php`
- `tests/Unit/AlipayRefundServiceTest.php`
- `tests/Integration/RefundApplicationServiceTest.php`
- `tests/Integration/RefundApiTest.php`
- `tests/Integration/RefundApprovalTest.php`
- `tests/Integration/RefundReconciliationTest.php`

**Modify**

- `app/payment/Alipay/AlipayGatewayInterface.php`
- `app/payment/Alipay/YansongdaAlipayGateway.php`
- `app/payment/Alipay/AlipayResult.php`
- `config/route.php`
- `process/ChannelTimerProcess.php`

---

### Task 1: Add Refund Persistence and State Constants

**Files:**
- Create: `database/patch_v8_alipay_refund.sql`
- Create: `app/model/Refund.php`
- Create: `app/model/RefundEvent.php`
- Test: `tests/Integration/RefundApplicationServiceTest.php`

**Interfaces:**
- `Refund` exposes exact status constants.
- Later services use the same constants instead of string literals.

- [ ] **Step 1: Write the failing persistence test**

```php
public function testRefundUsesUniqueMerchantAndProviderRequestNumbers(): void
{
    Refund::create([
        'merchant_id' => 7,
        'order_id' => 11,
        'merchant_refund_no' => 'MERCHANT-RF-001',
        'out_request_no' => 'CXRF001',
        'refund_amount' => '10.00',
        'reason' => '用户申请',
        'status' => Refund::STATUS_PENDING_REVIEW,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $this->expectException(\Throwable::class);
    Refund::create([
        'merchant_id' => 7,
        'order_id' => 11,
        'merchant_refund_no' => 'MERCHANT-RF-001',
        'out_request_no' => 'CXRF002',
        'refund_amount' => '10.00',
        'reason' => 'duplicate',
        'status' => Refund::STATUS_PENDING_REVIEW,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
}
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/RefundApplicationServiceTest.php --filter testRefundUsesUniqueMerchantAndProviderRequestNumbers
```

- [ ] **Step 3: Create the migration**

`cx_refund` columns:

```text
id
merchant_id
order_id
merchant_refund_no
out_request_no
refund_amount
reason
status
risk_result
reviewed_by
reviewed_at
executed_at
alipay_trade_no
alipay_response_code
alipay_response_sub_code
last_query_at
query_attempts
failure_message
created_at
updated_at
```

Indexes:

```text
UNIQUE (merchant_id, merchant_refund_no)
UNIQUE (order_id, out_request_no)
INDEX (status, updated_at)
INDEX (order_id, status)
```

`cx_refund_event` stores refund ID, event type, operator type/ID, safe context JSON, and timestamp.

- [ ] **Step 4: Implement models and constants**

```php
final class Refund extends Model
{
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNCERTAIN = 'uncertain';

    protected $table = 'cx_refund';
    protected $guarded = [];
    public $timestamps = false;
}
```

- [ ] **Step 5: Run persistence test**

```bash
./vendor/bin/phpunit tests/Integration/RefundApplicationServiceTest.php --filter testRefundUsesUniqueMerchantAndProviderRequestNumbers
```

- [ ] **Step 6: Commit**

```bash
git add database/patch_v8_alipay_refund.sql app/model/Refund.php \
  app/model/RefundEvent.php tests/Integration/RefundApplicationServiceTest.php
git commit -m "feat: add refund persistence"
```

---

### Task 2: Implement Mandatory-Review Risk Decisions

**Files:**
- Create: `app/service/RefundRiskService.php`
- Test: `tests/Unit/RefundRiskServiceTest.php`

**Interfaces:**
- Produces:

```php
public function evaluate(Order $order, string $refundAmount, array $context = []): array;
```

- Exact phase-one result:

```php
[
    'decision' => 'manual_review',
    'reason_codes' => ['PHASE_ONE_MANUAL_REVIEW'],
]
```

- [ ] **Step 1: Write failing test**

```php
public function testPhaseOneAlwaysRequiresManualReview(): void
{
    $result = (new RefundRiskService())->evaluate($this->paidOrder('100.00'), '1.00');

    self::assertSame('manual_review', $result['decision']);
    self::assertSame(['PHASE_ONE_MANUAL_REVIEW'], $result['reason_codes']);
}
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/RefundRiskServiceTest.php
```

- [ ] **Step 3: Implement the minimal fixed policy**

Return the exact result above. Keep constructor and method boundary suitable for later platform/merchant thresholds, but do not implement automatic execution now.

- [ ] **Step 4: Run test**

```bash
./vendor/bin/phpunit tests/Unit/RefundRiskServiceTest.php
```

- [ ] **Step 5: Commit**

```bash
git add app/service/RefundRiskService.php tests/Unit/RefundRiskServiceTest.php
git commit -m "feat: require refund manual review"
```

---

### Task 3: Implement Transactional Refund Submission and Amount Reservation

**Files:**
- Create: `app/service/RefundApplicationService.php`
- Modify: `tests/Integration/RefundApplicationServiceTest.php`

**Interfaces:**
- Produces:

```php
public function submit(
    int $merchantId,
    string $orderTradeNo,
    string $merchantRefundNo,
    string $refundAmount,
    string $reason,
    string $operatorType,
    int $operatorId
): Refund;

public function getOwned(int $merchantId, int $refundId): ?Refund;
public function listOwned(int $merchantId, array $filters = []): array;
```

- [ ] **Step 1: Write failing submission tests**

Cover:

- unpaid order rejected;
- non-Alipay official order rejected;
- amount `0.00`, malformed, or greater than paid amount rejected;
- first partial refund accepted as `pending_review`;
- repeated same merchant refund number with identical parameters returns original row;
- repeated number with different amount raises idempotency conflict;
- two concurrent submissions cannot reserve more than paid amount;
- existing `uncertain` refund continues reserving amount.

Example reservation assertion:

```php
$service->submit(7, $order->trade_no, 'RF-001', '70.00', 'part 1', 'merchant', 7);

$this->expectException(\RuntimeException::class);
$this->expectExceptionMessage('累计退款金额');
$service->submit(7, $order->trade_no, 'RF-002', '40.00', 'part 2', 'merchant', 7);
```

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/RefundApplicationServiceTest.php
```

- [ ] **Step 3: Implement strict normalization**

```php
private function normalizeAmount(string $amount): string
{
    $amount = trim($amount);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('退款金额格式不合法');
    }
    $normalized = number_format((float)$amount, 2, '.', '');
    if (bccomp($normalized, '0.00', 2) <= 0) {
        throw new RuntimeException('退款金额必须大于 0');
    }
    return $normalized;
}
```

- [ ] **Step 4: Implement transactional locking**

Inside one transaction:

1. resolve merchant-owned order by CXPAY `trade_no`;
2. lock merchant, order, and all refund rows for the order using the established lock order;
3. require `order.status=1` and channel `c_type=alipay_isv_f2f`;
4. check existing merchant refund number for idempotency;
5. sum reserved statuses with `bcadd()`;
6. reject when `reserved + requested > order.price`;
7. generate one stable provider request number such as `CXRF` + SnowFlake ID;
8. evaluate risk;
9. create `pending_review` row;
10. append `submitted` and `risk_evaluated` events.

- [ ] **Step 5: Run integration tests**

```bash
./vendor/bin/phpunit tests/Integration/RefundApplicationServiceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/service/RefundApplicationService.php \
  tests/Integration/RefundApplicationServiceTest.php
git commit -m "feat: submit idempotent partial refunds"
```

---

### Task 4: Extend the Alipay Gateway for Refund and Refund Query

**Files:**
- Modify: `app/payment/Alipay/AlipayGatewayInterface.php`
- Modify: `app/payment/Alipay/YansongdaAlipayGateway.php`
- Modify: `app/payment/Alipay/AlipayResult.php`
- Create: `app/service/AlipayRefundService.php`
- Test: `tests/Unit/AlipayRefundServiceTest.php`

**Interfaces:**
- Add:

```php
public function refund(array $payload, string $appAuthToken): array;
public function refundQuery(string $outTradeNo, string $outRequestNo, string $appAuthToken): array;
```

- `AlipayRefundService` produces:

```php
public function execute(Refund $refund): array;
public function query(Refund $refund): array;
```

- [ ] **Step 1: Write failing service tests**

Test exact payload:

```php
$gateway->expectRefund([
    'out_trade_no' => 'CXORDER001',
    'refund_amount' => '30.00',
    'out_request_no' => 'CXRF001',
    'refund_reason' => '用户申请',
], 'app-auth-token');
```

Cover success, definite provider failure, retryable error, and result-uncertain transport failure.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Unit/AlipayRefundServiceTest.php
```

- [ ] **Step 3: Add gateway methods**

Keep all Yansongda-specific invocation inside `YansongdaAlipayGateway`. Return safe arrays and classify exceptions exactly as the core plan does.

- [ ] **Step 4: Implement refund service**

Load the original order, channel, and encrypted authorization token. Build payload with original CXPAY `trade_no`; never use merchant `out_trade_no` as the upstream identifier.

Normalize result to:

```php
[
    'state' => 'succeeded|failed|uncertain',
    'trade_no' => '...',
    'fund_change' => true,
    'provider_code' => '10000',
    'provider_sub_code' => '',
    'message' => 'safe message',
]
```

- [ ] **Step 5: Run unit tests**

```bash
./vendor/bin/phpunit tests/Unit/AlipayRefundServiceTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/payment/Alipay/AlipayGatewayInterface.php \
  app/payment/Alipay/YansongdaAlipayGateway.php \
  app/payment/Alipay/AlipayResult.php app/service/AlipayRefundService.php \
  tests/Unit/AlipayRefundServiceTest.php
git commit -m "feat: add alipay refund gateway"
```

---

### Task 5: Implement Administrator Approval and Single-Execution Claim

**Files:**
- Extend: `app/service/RefundApplicationService.php`
- Test: `tests/Integration/RefundApprovalTest.php`

**Interfaces:**
- Produces:

```php
public function approve(int $refundId, int $adminId): Refund;
public function reject(int $refundId, int $adminId, string $reason): Refund;
public function executeApproved(int $refundId): Refund;
```

- [ ] **Step 1: Write failing approval tests**

Cover:

- only `pending_review` can be approved/rejected;
- repeated same approval is idempotent;
- rejected refund releases reserved amount;
- two workers calling `executeApproved()` result in one gateway refund call;
- success -> `succeeded`;
- definite failure -> `failed`;
- uncertain -> `uncertain`;
- no path lets an administrator set `succeeded` directly.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/RefundApprovalTest.php
```

- [ ] **Step 3: Implement approve/reject transactions**

Lock refund row and append events:

```text
approved
rejected
```

Store reviewer and timestamp. Rejection reason is limited to 255 characters and contains no provider secrets.

- [ ] **Step 4: Implement execution claim**

In one transaction, change `approved -> executing` only when current status is exactly `approved`. Commit the claim before the network call. After the call, lock the row again and transition based on the service result. Append `execution_started`, then one terminal/uncertain event.

- [ ] **Step 5: Run tests**

```bash
./vendor/bin/phpunit tests/Integration/RefundApprovalTest.php
```

- [ ] **Step 6: Commit**

```bash
git add app/service/RefundApplicationService.php tests/Integration/RefundApprovalTest.php
git commit -m "feat: review and execute alipay refunds"
```

---

### Task 6: Expose Merchant and Administrator Refund APIs

**Files:**
- Create: `app/controller/api/RefundApiController.php`
- Create: `app/controller/api/MerchantRefundController.php`
- Create: `app/controller/admin/RefundAdminController.php`
- Modify: `config/route.php`
- Test: `tests/Integration/RefundApiTest.php`

**Interfaces:**
- Public merchant-signed API:

```text
POST /api/refund/create
GET|POST /api/refund/query
```

- Merchant console APIs:

```text
POST /api/merchant/refunds
GET  /api/merchant/refunds
GET  /api/merchant/refunds/{id}
```

- Administrator APIs:

```text
GET  /api/admin/refunds
POST /api/admin/refunds/{id}/approve
POST /api/admin/refunds/{id}/reject
POST /api/admin/refunds/{id}/query
POST /api/admin/refunds/{id}/retry
```

- [ ] **Step 1: Write failing API tests**

Test authentication/signature, merchant ownership, idempotent replay, amount conflict, masked provider fields, admin-only approval, rejection reason, and no endpoint accepting a requested status.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/RefundApiTest.php
```

- [ ] **Step 3: Implement merchant-signed API**

Reuse the project merchant lookup, IP whitelist, and signature conventions used by `OrderService::createOrder()`. Required fields:

```text
pid
trade_no
out_refund_no
refund_amount
reason
sign
```

The API returns CXPAY refund number, merchant refund number, amount, and current status only.

- [ ] **Step 4: Implement merchant console endpoints**

Resolve merchant identity only from middleware/session. Never accept `merchant_id` from request input.

- [ ] **Step 5: Implement admin endpoints**

Approval invokes `approve()` then `executeApproved()` with the same refund ID. Retry is allowed only for `uncertain` query or an `approved` row that has never been claimed; it never creates a new `out_request_no`.

- [ ] **Step 6: Add routes under existing middleware groups**

Keep `/api/refund/create` and `/api/refund/query` outside merchant-session middleware because they use merchant protocol signing; place console/admin routes inside existing groups.

- [ ] **Step 7: Run API tests**

```bash
./vendor/bin/phpunit tests/Integration/RefundApiTest.php
```

- [ ] **Step 8: Commit**

```bash
git add app/controller/api/RefundApiController.php \
  app/controller/api/MerchantRefundController.php \
  app/controller/admin/RefundAdminController.php config/route.php \
  tests/Integration/RefundApiTest.php
git commit -m "feat: expose reviewed refund APIs"
```

---

### Task 7: Reconcile Executing and Uncertain Refunds

**Files:**
- Create: `app/service/AlipayRefundReconciliationService.php`
- Modify: `process/ChannelTimerProcess.php`
- Test: `tests/Integration/RefundReconciliationTest.php`

**Interfaces:**
- Produces:

```php
public function reconcileDue(int $limit = 100): array;
public function reconcileRefund(Refund $refund): string;
```

- Result strings are `succeeded`, `failed`, `waiting`, `retry`, or `skipped`.

- [ ] **Step 1: Write failing reconciliation tests**

Cover:

- uncertain query resolves success;
- uncertain query resolves definite failure;
- query remains unknown -> keep uncertain and increment attempts;
- executing record older than claim timeout is queried, not re-refunded;
- repeated workers claim once;
- authorization failure marks related auth `reauth_required` but preserves refund amount reservation;
- maximum attempts produce admin alert but no manual success/failure mutation.

- [ ] **Step 2: Verify failure**

```bash
./vendor/bin/phpunit tests/Integration/RefundReconciliationTest.php
```

- [ ] **Step 3: Implement due selection**

Select `executing` or `uncertain` records based on `last_query_at`, `query_attempts`, and update time. Claim each with a short Redis/database lease.

- [ ] **Step 4: Query by original identifiers**

Always call:

```php
$gateway->refundQuery(
    (string)$order->trade_no,
    (string)$refund->out_request_no,
    $appAuthToken
);
```

Never call `refund()` during uncertainty reconciliation.

- [ ] **Step 5: Update state and append events**

Use transactions and append `query_started`, `query_completed`, and the resulting terminal event. Keep `failure_message` safe and bounded.

- [ ] **Step 6: Schedule every 15 seconds**

Add a dedicated timer callback in `ChannelTimerProcess`, isolated from payment reconciliation and existing queues.

- [ ] **Step 7: Run tests**

```bash
./vendor/bin/phpunit tests/Integration/RefundReconciliationTest.php \
  tests/Integration/RefundApprovalTest.php
```

- [ ] **Step 8: Commit**

```bash
git add app/service/AlipayRefundReconciliationService.php \
  process/ChannelTimerProcess.php tests/Integration/RefundReconciliationTest.php
git commit -m "feat: reconcile uncertain alipay refunds"
```

---

### Task 8: Add Refund Audit and Sensitive-Data Regression Tests

**Files:**
- Create: `tests/Integration/RefundAuditSecurityTest.php`
- Modify: `tests/Integration/RefundApiTest.php`

**Interfaces:**
- Verifies every transition has an event and public responses/log context do not expose token/key material.

- [ ] **Step 1: Write event-sequence tests**

For a successful refund assert exact sequence:

```text
submitted
risk_evaluated
approved
execution_started
refund_succeeded
```

For an uncertain then resolved refund assert:

```text
submitted
risk_evaluated
approved
execution_started
execution_uncertain
query_started
query_completed
refund_succeeded
```

- [ ] **Step 2: Add secret exclusion assertions**

Serialize refund API responses and event context and assert they do not contain:

```text
app_auth_token
private_key
alipay_public_key
auth_code
```

- [ ] **Step 3: Run security tests**

```bash
./vendor/bin/phpunit tests/Integration/RefundAuditSecurityTest.php \
  tests/Integration/RefundApiTest.php
```

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/RefundAuditSecurityTest.php \
  tests/Integration/RefundApiTest.php
git commit -m "test: audit alipay refund lifecycle"
```

---

### Task 9: Refund Work Item Verification

**Files:**
- No new implementation files.

**Interfaces:**
- Verifies refund completeness before sandbox manual acceptance.

- [ ] **Step 1: Run syntax checks**

```bash
git diff --name-only work/epay-upstream-consolidation...HEAD -- '*.php' \
  | xargs -r -n1 php -l
```

- [ ] **Step 2: Run refund-focused tests**

```bash
./vendor/bin/phpunit \
  tests/Unit/RefundRiskServiceTest.php \
  tests/Unit/AlipayRefundServiceTest.php \
  tests/Integration/RefundApplicationServiceTest.php \
  tests/Integration/RefundApiTest.php \
  tests/Integration/RefundApprovalTest.php \
  tests/Integration/RefundReconciliationTest.php \
  tests/Integration/RefundAuditSecurityTest.php
```

Expected: zero failures, errors, warnings, or risky tests.

- [ ] **Step 3: Run complete suite**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 4: Inspect state and identifier invariants**

```bash
git diff --check
git grep -n "out_request_no" app tests
git grep -nE "STATUS_(PENDING_REVIEW|APPROVED|REJECTED|EXECUTING|SUCCEEDED|FAILED|UNCERTAIN)" app tests
git status --short
```

Confirm all network retries reuse stored `out_request_no`, all state transitions use constants, and no controller writes `status` directly.

- [ ] **Step 5: Record manual sandbox refund scenarios as pending**

The PR checklist must include full refund, two partial refunds, duplicate request, rejected review, uncertain result/query recovery, and revoked-authorization behavior. Do not claim them complete without real sandbox evidence.

- [ ] **Step 6: Commit any verification corrections**

```bash
git add -A
git commit -m "chore: finalize alipay refund workflow"
```

Skip when clean.
