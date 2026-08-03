# WJ-1 / WI-4-RED-01 — Admin Mutation Route Permission Gate

**Status**: GREEN — heal landed (commit `eaf77625f`)

> NOTE on commit boundary: this commit `eaf77625f` (intended scope =
> 5 files for WJ-1) accidentally absorbed WJ-4 work (Outbox broadcast
> swallow alarm sentinel: 4 additional files) because a sibling agent
> staged its files into the shared worktree index between this agent's
> `git status` validation and the `git commit` invocation. The WJ-1
> diff (3 controllers + 1 sentinel test + this STATUS file) is intact
> and correctly described in the commit message body; the absorbed
> WJ-4 files (`app/Events/OutboxBroadcastSwallowedEvent.php`, 3
> updated listeners, `tests/Feature/Sentinels/OutboxBroadcastSwallow…`,
> and the WJ-4 STATUS.md) are legitimate WJ-4 deliverables — they are
> part of the same heal wave but should ideally have shipped in their
> own commit. No reversal is requested because both heals pass tests
> and amending is forbidden by safety protocol (this branch is shared
> with parallel agents).
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**HEAD (pre-heal)**: d5f934755
**Severity**: P0 SECURITY (privilege escalation)

## Bug

3 admin mutation route groups in `routes/api.php` were reachable by any
authenticated user (including Customer tokens minted with abilities `['*']`)
because:

- Middleware stack admits: `apiKey` → `auth:sanctum` → `EnsureUserStatusActive` → `throttle:admin-mutation`
- No `permission:*` middleware on the prefix groups
- FormRequest `authorize()` returns `true` (MenuTemplateRequest:17, AnalyticSectionRequest:16)
- Controllers had no `__construct` middleware

Affected routes:

| Group           | File                                          | Verbs              |
|-----------------|-----------------------------------------------|--------------------|
| menu-template   | routes/api.php L384-390 (under setting/)       | POST + PUT/PATCH + DELETE |
| default-access  | routes/api.php L271-274 (direct under admin/)  | POST (storeOrUpdate)      |
| analytic-section| routes/api.php L438-446 (under setting/)       | POST + PUT/PATCH + DELETE |

## Fix Shape

**Pivot from prefix-level gate to per-verb controller gate** because:

- `PosMenuRuntimeAccessTest::test_pos_operator_bootstrap_survives_missing_default_access_row`
  exercises `GET /api/admin/default-access` with a POS Operator (no `settings` perm).
  Prefix-level gate would regress this test from 200 to 403.
- Per-verb gate (only mutations) mirrors the predominant pattern in the codebase
  (~20 examples: CurrencyController:21, SliderController:27, ItemAttributeController:22).

**Implementation**: add `$this->middleware(['permission:settings'])->only(...)` in
each controller `__construct` :

- `MenuTemplateController::__construct`        → `->only('store', 'update', 'destroy')`
- `DefaultAccessController::__construct`       → `->only('storeOrUpdate')`
- `AnalyticSectionController::__construct`     → `->only('store', 'update', 'destroy')`

Total: 3 single-line additions in 3 controllers (3 LOC).
`routes/api.php` untouched. Frozen-zone diff = 0.

## Sentinel Coverage

New: `tests/Feature/Sentinels/RouteCoverage_AdminPermissionGateSentinelTest.php`

7 cases:

1. Customer-token POST menu-template       → 403
2. settings-permission POST menu-template  → 201/200
3. Customer-token POST default-access      → 403
4. settings-permission POST default-access → 200
5. Customer-token POST analytic-section    → 403
6. settings-permission POST analytic-section → 201/200
7. GET default-access still accessible to POS-Operator with neither `settings`
   nor admin role (regression guard documenting the per-verb pivot rationale)

Each Customer-token case uses valid FormRequest payload so a pre-fix run shows
200/201 (proving the bug) and post-fix shows 403 (middleware short-circuits
before FormRequest validation).

## Constraints Honored

- 0 frozen-zone touch (3 controllers + 1 new test file; no `app/Services/Fiscal/*`,
  no `BranchScope.php`, no `pos-wizard.js`, no `PricingService.php`)
- 0 DIRTY-file touch (validated via repo scan)
- TDD-first (sentinel committed RED → fix → GREEN within same commit)

## Validation

- [x] Sentinel RED (3 Customer-token cases: 201/200/201 — proved the bug empirically)
- [x] Implement fix in 3 controllers (each `__construct` gains 1-line `middleware(['permission:settings'])->only(...)`)
- [x] Sentinel GREEN (7/7 pass — 1.28s)
- [x] Regression: `php artisan test --filter "Admin|Route|Permission"` — 301 passed, 4 skipped, 2 PRE-EXISTING failures unrelated (ComposerAuthzMinimalTest baseline-confirmed: same 2 failures on stashed clean tree)
- [x] Regression: `php artisan test tests/Feature/Pos/PosMenuRuntimeAccessTest.php` — 6/6 PASS (POS Operator GET default-access path preserved)

## Outcome

Privilege escalation closed for 3 admin mutation route groups. Customer-token
(abilities=['*']) requests now hit Spatie's `permission:settings` middleware
which 403s before the FormRequest validation layer can be reached. Settings-
permission holders retain full mutation access. The GET-side bootstrap path
used by POS Operators on first boot remains open (per-verb pivot rationale).

**Files modified** (3, total 3-line logic LOC + ~22 lines of inline doc):
- `app/Http/Controllers/Admin/MenuTemplateController.php`
- `app/Http/Controllers/Admin/DefaultAccessController.php`
- `app/Http/Controllers/Admin/AnalyticSectionController.php`

**Files added** (2):
- `tests/Feature/Sentinels/RouteCoverage_AdminPermissionGateSentinelTest.php` (7 cases, ~230 LOC)
- `reports/audit/wave-j-2026-05-19/WJ-1-WI4-RED01-ADMIN-ROUTES/STATUS.md` (this file)

**Frozen-zone diff**: 0
**DIRTY-list touch**: 0
**`routes/api.php` diff**: 0 (pivoted to controller-middleware per advisor reconciliation)
**NF525 chain impact**: 0 (no migrations, no fiscal services touched)
**TDD compliance**: RED → GREEN within same commit, sentinel proves bug pre-fix.

**Pre-existing failures noted** (NOT introduced by this heal, confirmed by
git-stash baseline test):
- `Tests\Feature\Composer\ComposerAuthzMinimalTest::test_branch_admin_cannot_update_foreign_profile_by_forging_payload_scope` — 403 expected, 404 received
- `Tests\Feature\Composer\ComposerAuthzMinimalTest::test_branch_admin_cannot_mutate_composer_steps_for_other_branch` — 403 expected, 404 received

These are likely BranchScope-related (route-model-binding hides foreign
resources as 404 before the auth check can return 403). Out-of-scope for
WJ-1; flag for separate Composer-authz hardening task.

