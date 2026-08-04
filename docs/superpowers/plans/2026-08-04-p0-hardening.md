# CXPAY P0 Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the online-update disable switch enforceable, prevent synthetic channel records from reaching the administrator API, and establish a repeatable CI quality gate.

**Architecture:** `SystemUpdateGuard` owns the update enable/disable decision and stops controller actions before side effects. `AdminChannelPresenter` normalizes persisted channel rows, while `AdminChannelListContractMiddleware` runs after the existing administrator authentication chain and replaces only the legacy synthetic response shape. GitHub Actions is intended to run Composer validation, dependency installation, PHP syntax checks, and PHPUnit.

**Tech Stack:** PHP 8.1+, Webman 2, PHPUnit 10, GitHub Actions, Composer.

## Global Constraints

- Online update remains disabled by default through `SYSTEM_UPDATE_ENABLED=false`.
- Disabled update endpoints must not execute Git, SQL, or process-reload commands.
- Channel list responses must contain only persisted `cx_channel` rows.
- Authentication and authorization must execute before channel response normalization.
- Database failure must not restore synthetic fallback channels.
- No direct changes are made to `main`; all work is isolated on `fix/p0-hardening`.
- Existing public API response envelope remains `{code, msg, data}`.

---

### Task 1: Establish CI and regression tests

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `tests/Unit/SystemUpdateGuardTest.php`
- Create: `tests/Unit/AdminChannelPresenterTest.php`
- Create: `tests/Unit/AdminChannelListContractMiddlewareTest.php`

- [x] **Step 1: Add the CI workflow**

The workflow inspects the runner, validates Composer metadata, installs dependencies, syntax-checks PHP files, runs PHPUnit, and preserves diagnostic logs when execution reaches those steps.

- [x] **Step 2: Add the update-guard regression test**

The test covers disabled and enabled policy behavior and all six update controller endpoints.

- [x] **Step 3: Add channel presenter and middleware tests**

The tests cover empty input, strict field normalization, middleware registration, legacy synthetic shape detection, and persisted response pass-through.

- [x] **Step 4: Establish a red baseline**

A CI run failed before the two new services existed, proving the regression tests detect the missing behavior.

### Task 2: Enforce the online-update switch

**Files:**
- Create: `app/service/SystemUpdateGuard.php`
- Modify: `app/controller/admin/SystemUpdateController.php`

**Interfaces:**
- `SystemUpdateGuard::isEnabled(): bool`
- `SystemUpdateGuard::disabledResponse(): ?support\Response`
- `SystemUpdateController::__construct(?SystemUpdateGuard $guard = null)`

- [x] **Step 1: Implement the policy guard**

Constructor injection controls tests; production resolves `config('app.system_update_enabled', false)`. Disabled mode returns HTTP 403.

- [x] **Step 2: Gate every update endpoint before side effects**

The guard is the first operation in `checkUpdate`, `doUpdate`, `versionHistory`, `pollProgress`, `getUpdateLog`, and `doRollback`.

- [x] **Step 3: Perform targeted behavior verification**

A local PHP stub harness verified that disabled endpoints return 403 before code paths that require `base_path()`, Git, SQL, or reload execution.

### Task 3: Enforce the real-channel response contract

**Files:**
- Create: `app/service/AdminChannelPresenter.php`
- Create: `app/middleware/AdminChannelListContractMiddleware.php`
- Modify: `config/middleware.php`

**Interfaces:**
- `AdminChannelPresenter::format(array $channels): array`
- `AdminChannelListContractMiddleware::process(Request $request, callable $handler): Response`

- [x] **Step 1: Implement deterministic channel normalization**

The presenter maps only supplied rows and never creates fallback records.

- [x] **Step 2: Preserve the administrator authentication chain**

The middleware invokes the downstream handler first and leaves non-success responses unchanged.

- [x] **Step 3: Replace only the legacy synthetic response**

Persisted responses containing `c_type` and `online_status` pass through. Legacy fallback data or an empty legacy response is replaced using real `cx_channel` rows.

- [x] **Step 4: Fail closed on storage errors**

A failed corrective database read returns HTTP 503 with `data: []`, rather than exposing synthetic channels.

- [x] **Step 5: Perform targeted behavior verification**

A local PHP stub harness covered empty storage, synthetic fallback replacement, persisted response pass-through, database failure, and unauthorized response pass-through.

### Task 4: CI diagnosis and review handoff

- [x] **Step 1: Compare the branch with `main`**

The final diff contains the CI workflow, plan, two services, one middleware, middleware registration, controller guard changes, and three focused test files.

- [x] **Step 2: Open and document a draft PR**

PR #2 records the root causes, implementation, verification evidence, and remaining risks.

- [ ] **Step 3: Obtain a complete green GitHub Actions run**

Current Actions runs terminate before reporting any executed steps; the API exposes an empty step list and no downloadable log or artifact. Keep the PR in Draft until the repository Actions environment executes the workflow and the full Composer/PHPUnit suite passes.

- [ ] **Step 4: Remove the legacy controller fallback in a follow-up cleanup**

The middleware enforces safe external behavior now. A later focused PR should delete the obsolete fallback block directly from `AdminController::listChannels()` once normal repository checkout and full-suite verification are available.
