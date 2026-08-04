# CXPAY P0 Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the online-update disable switch enforceable, remove synthetic channel records, and add a repeatable CI quality gate.

**Architecture:** Introduce two small, independently testable services. `SystemUpdateGuard` owns the update enable/disable decision and produces a consistent 403 response; `AdminChannelPresenter` owns the API representation of real channel rows and never fabricates records. Controllers delegate to these services, while GitHub Actions runs syntax checks and PHPUnit on every push and pull request.

**Tech Stack:** PHP 8.1+, Webman 2, PHPUnit 10, GitHub Actions, Composer.

## Global Constraints

- Online update remains disabled by default through `SYSTEM_UPDATE_ENABLED=false`.
- Disabled update endpoints must not execute Git, SQL, or process-reload commands.
- Channel list responses must contain only persisted `cx_channel` rows.
- No direct changes are made to `main`; all work is isolated on `fix/p0-hardening`.
- Existing public API response envelope remains `{code, msg, data}`.

---

### Task 1: Establish CI and regression tests

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `tests/Unit/SystemUpdateGuardTest.php`
- Create: `tests/Unit/AdminChannelPresenterTest.php`

**Interfaces:**
- Produces: `app\service\SystemUpdateGuard::__construct(?bool $enabled = null)` and `disabledResponse(): ?support\Response`.
- Produces: `app\service\AdminChannelPresenter::format(array $channels): array`.

- [ ] **Step 1: Add a PHP 8.1 CI workflow**

Run Composer validation, install dependencies, syntax-check application PHP files, and run PHPUnit.

- [ ] **Step 2: Add failing update-guard test**

Assert that the guard class exists, disabled mode returns HTTP 403, enabled mode returns `null`, and every public update controller endpoint returns 403 when injected with a disabled guard.

- [ ] **Step 3: Add failing channel-presenter test**

Assert that an empty input returns an empty array and a persisted channel row is normalized with strict boolean `enabled` and integer `online_status` fields.

- [ ] **Step 4: Verify the branch CI fails for the missing services**

Expected: PHPUnit fails because `SystemUpdateGuard` and `AdminChannelPresenter` do not exist.

### Task 2: Enforce the online-update switch

**Files:**
- Create: `app/service/SystemUpdateGuard.php`
- Modify: `app/controller/admin/SystemUpdateController.php`

**Interfaces:**
- `SystemUpdateGuard::isEnabled(): bool`
- `SystemUpdateGuard::disabledResponse(): ?support\Response`
- `SystemUpdateController::__construct(?SystemUpdateGuard $guard = null)`

- [ ] **Step 1: Implement the minimal guard**

Resolve the configured value from constructor injection for tests or `config('app.system_update_enabled', false)` in production. Return a JSON 403 response when disabled.

- [ ] **Step 2: Gate every update endpoint before side effects**

Apply the guard at the first line of `checkUpdate`, `doUpdate`, `versionHistory`, `pollProgress`, `getUpdateLog`, and `doRollback`.

- [ ] **Step 3: Verify guard tests pass**

Expected: all `SystemUpdateGuardTest` cases pass and no command path runs while disabled.

### Task 3: Remove synthetic channel records

**Files:**
- Create: `app/service/AdminChannelPresenter.php`
- Modify: `app/controller/admin/AdminController.php`

**Interfaces:**
- `AdminChannelPresenter::format(array $channels): array`

- [ ] **Step 1: Implement deterministic channel normalization**

Map only supplied rows. Preserve `id`, `code`, `name`, `pay_type`, `c_type`, `remark`, `online_status`, `enabled`, `weight`, and `configured`.

- [ ] **Step 2: Replace the default-driver fallback**

Have `listChannels()` return `AdminChannelPresenter::format($channels)`; an empty query result must produce `data: []`.

- [ ] **Step 3: Verify presenter and full test suite pass**

Expected: PHPUnit and syntax checks pass in CI.

### Task 4: Review and handoff

**Files:**
- Review all changed files.

- [ ] **Step 1: Compare `fix/p0-hardening` against `main`**

Confirm only the plan, workflow, two services, two tests, and two controller changes are present.

- [ ] **Step 2: Open a draft pull request**

Document root causes, behavioral changes, test evidence, and remaining follow-up risks without merging automatically.
