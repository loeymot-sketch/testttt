# PR Package 1 — CENTRAL backbone heal — 2026-05-18

> **Source-of-truth audit dossier:** `reports/test-e2e/goal-ultra-central-mgmt-sync-2026-05-18/round-{1,2}/` + `FINAL_ROUND_1_2_VERDICT.md` §4.
> Every heal commit below cites the agent finding ID — re-read that finding (full reasoning + cost-of-delay YAML) before patching. Do **not** re-research.

## Branch
- name: `heal/central-backbone-2026-05-18`
- base SHA: `5b147f9e7`
- rebased on: `main` (or `v1-0-1-hardening-2026-05-17` per GOAL §0.5)
- merge order: PR #1 first; PRs #2 + #3 rebase on this.

## Heal commits (in order — smallest blast-radius first)

### Commit 1 — `central-heal-cvp0-1` — CVP0-1 TRUNCATE GRANT
- **Title:** `fix(central-heal-2026-05-18): revoke TRUNCATE/DROP on fiscal tables (CVP0-1)`
- **Files modified:**
  - `deploy/ansible/site.yml` — NEW task block (insert before `composer install`, after DB-up wait); ~25 lines added
  - `deploy/ansible/tasks/fiscal-db-acl.yml` — `(file TO BE CREATED at deploy/ansible/tasks/fiscal-db-acl.yml)` — new file, ~40 lines
- **Patch scope (pseudocode, NOT actual code):**
  ```
  At deploy/ansible/site.yml, ADD task:
    - name: Apply NF525 fiscal-DB privilege restrictions
      include_tasks: tasks/fiscal-db-acl.yml
      when: app_env == 'production'

  At deploy/ansible/tasks/fiscal-db-acl.yml (new), DECLARE the Ansible mysql_query module run, idempotent:
    - REVOKE DROP, TRUNCATE, ALTER on `{{ db_name }}`.audit_logs FROM `{{ db_user }}`@`%`
    - REVOKE DROP, TRUNCATE, ALTER on `{{ db_name }}`.z_reports FROM `{{ db_user }}`@`%`
    - REVOKE DROP, TRUNCATE, ALTER on `{{ db_name }}`.cash_movements FROM `{{ db_user }}`@`%`
    - REVOKE DROP, TRUNCATE, ALTER on `{{ db_name }}`.cash_drawer_sessions FROM `{{ db_user }}`@`%`
    - REVOKE DROP, TRUNCATE, ALTER on `{{ db_name }}`.order_payments FROM `{{ db_user }}`@`%`
    - REVOKE DROP, TRUNCATE, ALTER on `{{ db_name }}`.domain_events FROM `{{ db_user }}`@`%`
    - FLUSH PRIVILEGES
  Why: bypass-via-TRUNCATE acknowledged in migration 2026_05_09_160000:30-32 (z_reports trigger) and 2026_05_10_010000:48-49 (audit_logs trigger). Sentinel-grade DGFiP gap per R1 Fiscal F-FISC-002 and R1 Security S-1.
  Rollback: revert site.yml task block + GRANT ALL privileges to db_user if needed (emergency).
  ```
- **Tests added:** `tests/Feature/Fiscal/AuditTruncateProtectionDeployDocTest.php` — `(file TO BE CREATED)` — 2 cases: (a) sentinel asserts `deploy/ansible/tasks/fiscal-db-acl.yml` exists and contains REVOKE on each of the 5 protected tables (greps the YAML — does NOT execute against MySQL), (b) sentinel asserts `docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt` references the fiscal-db-acl runbook.
- **Acceptance evidence command:** `php artisan test --filter=AuditTruncateProtectionDeployDocTest` → PASS
- **Frozen-zone status:** 0 lines touched. (Migrations + service stay untouched; only Ansible + new sentinel test.)

### Commit 2 — `central-heal-cp0-h` — C-P0-H IdempotencyKey header-omission bypass
- **Title:** `fix(central-heal-2026-05-18): add 9 mutating routes to idempotency required_routes (C-P0-H)`
- **Files modified:**
  - `config/idempotency.php` lines 25-34 (`required_routes` array)
- **Patch scope:**
  ```
  At config/idempotency.php:25-34, EXTEND the `required_routes` array with these 9 patterns (each route currently mounted with middleware('idempotency') per R2-Security S-1 evidence):
    - 'api/admin/pos-order/counter-collect/*/confirm'   (routes/api.php:768)
    - 'api/admin/pos-order/counter-collect/*/cancel'    (routes/api.php:788)
    - 'api/admin/pos-order/collect-kiosk-cash/*'        (routes/api.php:799)
    - 'api/admin/orders/print-receipt'                   (routes/api.php:800)
    - 'api/admin/cash-drawer/open'                       (routes/api.php:813)
    - 'api/admin/cash-drawer/sessions/open'              (routes/api.php:817)
    - 'api/admin/cash-drawer/sessions/close'             (routes/api.php:820)
    - 'api/admin/cash-drawer/sessions/reconcile'         (routes/api.php:824)
    - 'api/admin/pos-order/*/refund-with-counter-entry'  (routes/api.php:867)
  Why: middleware skip silent-pass on line 49-59 of IdempotencyKeyMiddleware when route is decorated but not required. Double-charge surface today (R2-Security S-1, reproduction at lines 71-77).
  Rollback: revert array additions (10 lines).
  ```
- **Tests added:** `tests/Feature/Idempotency/IdempotencyRequiredRoutesCoverageTest.php` — `(file TO BE CREATED)` — sentinel iterates `routes/api.php` searching for `->middleware('idempotency')` declarations, asserts each route URL appears in `config('idempotency.required_routes')` either by literal path or by `Route::is()` glob match. Fails on every new wired route until added.
- **Acceptance evidence command:** `php artisan test --filter=IdempotencyRequiredRoutesCoverageTest` → PASS
- **Frozen-zone status:** 0 lines touched (only `config/idempotency.php` — not frozen).

### Commit 3 — `central-heal-cp0-g` — C-P0-G IdempotencyKey fail-closed
- **Title:** `fix(central-heal-2026-05-18): IdempotencyKey resolveBranchId fail-closed (C-P0-G)`
- **Files modified:**
  - `app/Http/Middleware/IdempotencyKeyMiddleware.php` line 219 — 1-line change
- **Patch scope:**
  ```
  At app/Http/Middleware/IdempotencyKeyMiddleware.php:219, CHANGE
    return (int) $request->input('branch_id', -1);
  TO
    return -1;  // fail-closed: unresolvable branch_id rejects via line-70 guard (-> 422 MissingIdempotencyKeyException)
  Why: line 219 lets a non-admin non-kiosk user with branch_id=0 control the scope tuple from the payload (R2-Architect P0 finding + R2-Security S-2). Fail-closed delegates to the existing line-70 guard which throws.
  Rollback: 1-line revert.
  ```
- **Frozen-zone status:** TOUCHES the frozen middleware. **Per FINAL §5 paragraph 5**, this is the "Option B escape clause" path (1 line, no LOCK doc). Owner sign-off in PR description's "Open questions" section. If owner declines, this commit is reclassified as requiring `LOCK_IDEMPOTENCY_RESOLVE_BRANCH_FAILCLOSED_2026-05-18.md`.
- **Tests added:** `tests/Feature/Idempotency/UnresolvableBranchIdRejectedTest.php` — `(file TO BE CREATED)` — creates user with `branch_id=0`, no Admin role, no KioskMachine pivot; POSTs to required route with `X-Idempotency-Key` valid; asserts 422 + `MISSING_IDEMPOTENCY_KEY` error code.
- **Acceptance evidence command:** `php artisan test --filter=UnresolvableBranchIdRejectedTest` → PASS

### Commit 4 — `central-heal-cp0-a` — C-P0-A env() cache HMAC fix (Option B: boot-time pre-resolve)
- **Title:** `fix(central-heal-2026-05-18): pre-resolve fiscal per-branch secrets in config (C-P0-A)`
- **Files modified:**
  - `config/fiscal.php` — ADD `audit_secret_per_branch` keyed map (~12 lines)
  - `app/Services/Fiscal/AuditLogService.php` line 273 — REPLACE `env()` lookup with `Config::get()` lookup (1 line)
- **Patch scope:**
  ```
  At config/fiscal.php, ADD a new top-level key (alongside existing 'audit_secret'):
    'audit_secret_per_branch' => [
        1  => env('FISCAL_AUDIT_SECRET_BRANCH_1'),
        2  => env('FISCAL_AUDIT_SECRET_BRANCH_2'),
        // continue up to expected max branch count, e.g. 10 — set per deploy config
    ],
  Why: env() returns null at runtime when config:cache is in effect — R1-Security S-2 + R1-Fiscal F-FISC-002 reasoning. Pre-resolving forces values into bootstrap/cache/config.php at deploy time.

  At app/Services/Fiscal/AuditLogService.php:273, CHANGE
    $override = env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId);
  TO
    $override = Config::get('fiscal.audit_secret_per_branch.'.$branchId);
  ```
- **Frozen-zone status:** TOUCHES `AuditLogService.php:273` (frozen per CLAUDE.md §7). FINAL §5 paragraph 6 explicitly calls this out: "**recommend Option B (boot-time pre-resolve) to avoid LOCK overhead**". Owner sign-off required in PR description's "Open questions" — if owner declines, this commit is reclassified as requiring `LOCK_AUDITLOGSERVICE_ENV_TO_CONFIG_2026-05-18.md`.
- **Tests added:** `tests/Feature/Fiscal/FiscalSecretConfigCachedTest.php` — `(file TO BE CREATED)` — boots Laravel with `app()->offsetSet('config', $this->cached())` simulating `configurationIsCached()==true`; asserts `AuditLogService::secretFor(1)` returns the per-branch override (not the shared fallback). Extends existing `FiscalSecretProductionGuardTest.php`.
- **Acceptance evidence command:** `php artisan test --filter=FiscalSecretConfigCachedTest` → PASS + `php artisan config:cache && php artisan test --filter=AuditLogHashChainTest` → PASS (chain integrity preserved post-cache)

### Commit 5 — `central-heal-cp0-d` — C-P0-D Add BranchScope to 10 gap models
- **Title:** `fix(central-heal-2026-05-18): add BranchScope to NF525-critical + business gap models (C-P0-D)`
- **Files modified:** (10 files, ~2 lines each = ~20 lines total)
  - `app/Models/AuditLog.php` — add `static::addGlobalScope(new BranchScope())` in `booted()`
  - `app/Models/ZReport.php` — same
  - `app/Models/DomainEvent.php` — same
  - `app/Models/ActionLog.php` — same
  - `app/Models/KioskPromo.php` — same
  - `app/Models/UpsellRule.php` — same
  - `app/Models/Message.php` — same
  - `app/Models/FrontendDiningTable.php` — same
  - `app/Models/DiningTableAuditLog.php` — same
  - `app/Models/ItemBranchAvailability.php` — same
  - Customer model: KEEP EXEMPTED (extends User; Sanctum recursion exemption per R2-Architect F1 ALLOWLIST + R2-Security S-5)
- **Patch scope:**
  ```
  At each model with a `branch_id` column listed above, IN booted():
    static::addGlobalScope(new BranchScope());
  Use the existing convention pattern: see app/Models/Order.php:92 as reference.

  Why: R2-Architect F1 lists 11 models with branch_id but no scope; AuditLog + ZReport are NF525-P0 (cross-contamination of fiscal chain on multi-branch reads); rest are business-data P1 (cross-tenant promo/upsell/chat/dining-table leak). Sequencing: this commit lands AFTER Commit 4 (C-P0-A) per F1 sequencing constraint — per-branch HMAC config:cache fix must precede AuditLog scope add.
  Rollback: revert each booted() addition.
  ```
- **Tests added:** None new in this commit (Commit 6 adds the sentinel that exercises these).
- **Acceptance evidence command:** Existing tests stay green: `php artisan test --filter=Branch` + `php artisan test --filter=AuditLogBranchRequired` → PASS
- **Frozen-zone status:** 0 lines touched (models are not in CLAUDE.md §7 frozen list).

### Commit 6 — `central-heal-cp0-e` — C-P0-E BranchScope coverage sentinel
- **Title:** `feat(central-heal-2026-05-18): CI guardrail BranchScopeCoverageSentinelTest (C-P0-E)`
- **Files modified:**
  - `tests/Feature/Multitenant/BranchScopeCoverageSentinelTest.php` — `(file TO BE CREATED)` — ~80 lines
  - `docs/multitenant/BRANCH_SCOPE_ALLOWLIST.md` — `(file TO BE CREATED at docs/multitenant/BRANCH_SCOPE_ALLOWLIST.md)` — ~30 lines, lists Customer + User Sanctum-recursion exemption documented (per R2-Architect F2 sentinel architecture §4 of report)
- **Patch scope:**
  ```
  At tests/Feature/Multitenant/BranchScopeCoverageSentinelTest.php (new):
    1. Use Schema::getAllTables() (driver-aware), filter tables having a 'branch_id' column.
    2. For each such table, resolve to its Eloquent model class via convention (singularised + Studly-cased — app/Models/*.php glob match), OR look up in an internal registry array.
    3. assertNotEmpty($model::getGlobalScopes()) AND assertTrue($model has BranchScope class registered) OR assertContains($model, $ALLOWLIST).
    4. ALLOWLIST = [Customer::class => 'Sanctum recursion (extends User)'] post-this-PR.
  Why: catches every new model with branch_id column shipped without BranchScope OR explicit allowlist entry. R2-Architect F2: "without sentinel, F1 heal can silently regress".
  ```
- **Acceptance evidence command:** `php artisan test --filter=BranchScopeCoverageSentinelTest` → PASS (with all 10 models from Commit 5 declaring scope)
- **Frozen-zone status:** 0 lines touched (new test + doc only).

### Commit 7 — `central-heal-cp0-f` — C-P0-F Branch Manager persistent foothold heal (4-layer defense)
- **Title:** `fix(central-heal-2026-05-18): block cross-branch user creation by Branch Manager (C-P0-F)`
- **Files modified:**
  - `app/Traits/DefaultAccessModelTrait.php` line 40 — 1-line fix
  - `app/Http/Requests/EmployeeRequest.php` — `authorize()` + `branch_id` rule strengthening
  - `app/Http/Requests/ChefRequest.php`, `app/Http/Requests/WaiterRequest.php`, `app/Http/Requests/DeliveryBoyRequest.php` — same pattern
  - `app/Models/User.php` — add `static::saving()` guard in `booted()`
- **Patch scope:**
  ```
  At app/Traits/DefaultAccessModelTrait.php:40, CHANGE
    $this->branch();
  TO
    $branchId = $this->branch();  // assign return value (defending against payload-controlled branch_id)
  Why: documented dead branch — R2-Security S-1 attack chain step 8 (line 39-40 of the trait silently discards branch() return value).

  At app/Http/Requests/EmployeeRequest.php (and ChefRequest, WaiterRequest, DeliveryBoyRequest):
    1. REPLACE authorize(): return true; WITH proper check:
       authorize(): return $this->user()?->hasRole(['Admin','Tenant Admin']) || ($this->user()?->branch_id === (int) $this->input('branch_id'));
    2. STRENGTHEN 'branch_id' rule:
       FROM ['nullable', 'numeric']
       TO   ['nullable', 'numeric', Rule::in($this->user()?->branch_id === 0 ? Branch::pluck('id')->all() : [$this->user()?->branch_id])]

  At app/Models/User.php booted(), ADD:
    static::saving(function (User $u): void {
        $auth = Auth::user();
        if ($auth && (int) $auth->branch_id !== 0 && $u->branch_id !== null && (int) $u->branch_id !== (int) $auth->branch_id) {
            throw new \RuntimeException('Cross-branch User assignment forbidden.');
        }
    });

  Why: 4-layer defense — trait fix + FormRequest authz + FormRequest rule + model guard. Per R2-Security S-1 recommendation §3.
  Rollback: revert each layer independently.
  ```
- **Tests added:** `tests/Feature/Branch/StaffCrossBranchCreationDeniedTest.php` — `(file TO BE CREATED)` — covers all four services (Employee/Chef/Waiter/DeliveryBoy) + the User model guard via direct instantiation.
- **Acceptance evidence command:** `php artisan test --filter=StaffCrossBranchCreationDeniedTest` → PASS
- **Frozen-zone status:** 0 lines touched (DefaultAccessModelTrait is not in CLAUDE.md §7 frozen list, neither are the FormRequests nor User model).

## PR description template (ready for `gh pr create`)

Title: `feat(central-backbone-2026-05-18): NF525 + BranchScope + IdempotencyKey heal — 7 P0 closed`

Body: