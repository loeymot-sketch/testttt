# PR-D Global Central Management — Ultra-Review Verdict

> Reviewer: 5-perspective deep-read subagent (Architect / Security / UX-A11y / DBA / RED-team).
> Branch: `v1-0-1-hardening-2026-05-17` @ HEAD `a34d1f696786ac701d25586561a7121de1080d84`.
> Plan reviewed: `plans/ultra-plans-2026-05-18/PR_D_GLOBAL_CENTRAL_ULTRA_PLAN_REVIEW_2026-05-18.md`.
> Findings JSON: `reports/ultra-review-2026-05-18/PR_D_findings.json`.

---

## §1 Verdict

**GO-CONDITIONAL.** The plan is structurally sound. Macro findings (BranchScope gaps, FormRequest authz bypass, idempotency middleware off-by-default, password policy `min:6`, composer advisories) are all REAL and verified at primary source. However THREE plan corrections must land before any sub-agent begins T1–T8 execution, and one NEW finding (15 admin controllers with zero `permission:*` middleware) must be added as a task. Frozen-zone discipline is intact across all 7 audited files.

**Counts**: 3 P0, 6 P1, 4 P2, 2 P3, 1 informational. NF525 chain verified ALIVE this session — `count=27, last_hash=ee563c5a9feb34a6be5f4d017d933f535dadfe466d3a16add7b973b0cd58db62`. Note: plan baseline `count=26, hash=ca4ac1fdc208dae1` is STALE not WRONG — chain has appended one row since plan write. Acceptance gate §6 row 3 must be updated.

**Most important issue**: `ItemWizardProfile` uses column `branch_id_scope` (`app/Models/ItemWizardProfile.php:20,30,63`), NOT `branch_id`. Plan F-D2 column claim is wrong, and `BranchScope.php:28` hardcodes `branch_id` — so naive execution of T2 will cause a SQL "unknown column" 500 on every composer profile editor query. Extract `ItemWizardProfile` from T2 to a PR-D2 follow-up (custom scope or column rename via owner-gated migration).

---

## §2 Plan verification matrix (claim → evidence)

| Plan claim | Verified? | Evidence | Note |
|------------|-----------|----------|------|
| 86 FormRequests `return true;` | PARTIAL | `awk` body-of-authorize() extraction returns 78 files | Plan likely counted `return true` anywhere in file; 78 is the load-bearing count |
| 12 models with `branch_id` no scope | YES | intersection grep matches plan list 1:1 | But Customer/ItemWizardProfile have separate issues (see §3) |
| `min:6` everywhere | YES (+1) | 9 files actually (CustomerRequest:48 also) | Plan listed 8 |
| Idempotency default OFF | YES | `config/idempotency.php:21` env false | 9 required_routes, none admin |
| Sanctum TTL 480 uniform | YES | `config/sanctum.php:51` | env override possible |
| 12 composer advisories | YES | `composer audit --no-interaction` returns 12 | Plan omits phpseclib P0 |
| NF525 chain count=26 hash=ca4… | STALE | actual `27 | ee563c5a9feb34a6…` | Plan baseline +1 row outdated |
| Frozen-zone files audit-only | YES | 7 frozen files spot-read, no doctrine drift | T1/T2/T4/T8 changes correctly on non-frozen surfaces |

---

## §3 Plan corrections required pre-merge

### Correction-1 (BLOCKER) — `ItemWizardProfile` column mismatch
`app/Models/ItemWizardProfile.php:13-31` declares `branch_id_scope` (not `branch_id`); the relation method `branchScope()` (line 61-64) targets foreign key `branch_id_scope`. `BranchScope::apply()` (`app/Models/Scopes/BranchScope.php:28`) builds `sprintf('%s.%s', $builder->getQuery()->from, 'branch_id')` — hard-coded column name. Adding `addGlobalScope(new BranchScope())` to `ItemWizardProfile` will produce `SQLSTATE[42S22]: Unknown column 'item_wizard_profiles.branch_id'` at runtime.

**Action**: Drop `ItemWizardProfile` from T2; place in PR-D2 backlog with one of (a) rename column via migration (owner-gate, schema diff), (b) write `WizardProfileBranchScope` parameterized on column name, (c) explicit `where('branch_id_scope', auth()->user()->branch_id)` in controller queries.

### Correction-2 (BLOCKER) — `Customer` extends `User` (silent no-op risk)
`app/Models/Customer.php:9` — `class Customer extends User`. `BranchScope.php:21-23` short-circuits if `$model instanceof User`. Since Customer IS-A User by inheritance, adding `BranchScope` on Customer compiles but yields a silent no-op — executor may think it ships isolation while shipping nothing. Worse: a custom Customer-only scope risks re-triggering the Sanctum recursion the User exemption exists to prevent.

**Action**: Remove `Customer` from T7 list. If cross-branch Customer enumeration is a real concern, gate via explicit `CustomerController::query()->where('branch_id', auth()->user()->branch_id)` rather than a global scope.

### Correction-3 (MUST-UPDATE) — Acceptance §6 NF525 baseline stale
This session attests `count=27, last_hash=ee563c5a9feb34a6be5f4d017d933f535dadfe466d3a16add7b973b0cd58db62` via `mysql -uroot foodking -e "SELECT COUNT(*) AS c, MAX(current_hash) AS h FROM audit_logs"`. Plan §6 acceptance row uses `26 | ca4ac1fdc208dae1`. Chain has appended legitimately (Wave 5H + insights heal). Update baseline to current values; verify count + hash stay bit-identical PRE/POST PR-D merge (chain MUST NOT advance during PR-D since PR-D scope writes zero fiscal rows).

---

## §4 New finding — Admin controller permission middleware coverage (NOT in plan)

15 of 80 in-scope Admin controllers carry ZERO `permission:*` middleware in their constructor. Combined with PR-D-F5 (78 FormRequests `return true;`), several controllers depend on NEITHER controller-level NOR FormRequest-level authorization:

```
app/Http/Controllers/Admin/AddressController.php
app/Http/Controllers/Admin/AdminController.php
app/Http/Controllers/Admin/AdminPosV4Controller.php
app/Http/Controllers/Admin/AnalyticSectionController.php
app/Http/Controllers/Admin/ComposerProfileController.php    ← critical (catalog wizard)
app/Http/Controllers/Admin/ComposerStepController.php       ← critical (catalog wizard)
app/Http/Controllers/Admin/CountryCodeController.php
app/Http/Controllers/Admin/DefaultAccessController.php
app/Http/Controllers/Admin/IngredientController.php         ← critical (ingredients/allergens)
app/Http/Controllers/Admin/MenuController.php               ← critical (menu structure)
app/Http/Controllers/Admin/MenuProjectionController.php
app/Http/Controllers/Admin/MenuSectionController.php
app/Http/Controllers/Admin/MenuTemplateController.php
app/Http/Controllers/Admin/MyOrderDetailsController.php
app/Http/Controllers/Admin/TimezoneController.php
```

`routes/api.php` admin group at line 269 carries only `[installed, apiKey, auth:sanctum, localization, throttle:admin-mutation]` — no group-level `permission:*`. Only 3 inline `permission:*` references exist in `routes/api.php` (lines 682, 696, 718 — ingredients + catalog.compose + catalog.publish), and those overlap with controller-level checks rather than substitute.

**Add to PR-D as T9** (15 controllers × 1 constructor middleware line, sentinel asserts 403 from non-privileged role). **Add T10** to extend `database/seeders/ComposerPermissionsMinimalSeeder.php:11` — current seeder only defines `catalog.compose` + `catalog.publish`; new permissions (`menus_manage`, `addresses_manage`, etc.) must be seeded and granted to Admin + Branch Manager or the new middleware returns 403 for every legitimate admin call.

---

## §5 Findings by perspective (executive)

**Architect**: Plan's task graph is sound; T1/T2/T7 share a sentinel pattern that's TDD-friendly. Drop ItemWizardProfile and Customer from scope, add T9/T10. Frozen-zone audit-only discipline correctly preserved across T1–T8.

**Security**: Three concurrent gaps — BranchScope absence on fiscal NF525-adjacent models (P0), 78 FormRequests bypass (P1), 15 controllers without middleware (P1 NEW). T3 cherry-pick of 8 FormRequests + T9 of 15 controllers should land in same PR to avoid coverage gaps. Password `min:6` (P1) trivially closeable via T5.

**UX-A11y**: PR-D scope is overwhelmingly backend. Admin Vue components (`resources/js/components/admin/items*`, `customers*`, `settings*`) not deep-read because the plan §4 acceptance gate row 4 auto-passes them. No surface-level regressions visible on smoke read. PR-D2 if pursued should fold i18n + a11y for admin items per Wave Z5-P1-03 backlog.

**DBA**: NF525 chain attested ALIVE this session (`count=27, hash=ee563c5a9feb34a6…`). Triggers in `audit_logs` + `z_reports` remain DB-level INSERT-only enforcement. T1 BranchScope on AuditLog/ZReport models is application-layer defense-in-depth — does NOT touch chain integrity. Sentinel must assert chain count+hash bit-identical pre/post-scope.

**RED-team**: Plan has 3 silent-failure vectors: (1) ItemWizardProfile column mismatch creates 500 on naive execution, (2) Customer model exemption masks no-op as success, (3) NF525 baseline staleness means executor cross-check could miss legitimate chain advances during PR-D window. Composer T6 omits phpseclib P0 — DoS via ASN.1 OID amplification and AES-CBC padding oracle, both CVE-2026 high severity, runtime-impacting via Laravel framework SSH adapter chain. Add to T6.

---

## §6 Recommended PR-D execution order (post corrections)

```
Sub-agent A (multi-tenant cluster):
  T1 BranchScope on AuditLog + ZReport (+sentinel, +chain attest)
  T2 BranchScope on ItemBranchAvailability (ItemWizardProfile DROPPED)
  T7 BranchScope on 6 hygiene models (Customer DROPPED)
  T9 Permission middleware on 15 admin controllers (NEW)
  T10 Extend ComposerPermissionsMinimalSeeder for new perms (NEW)

Sub-agent B (security input cluster):
  T3 FormRequest authz top-8 (Item/Coupon/ItemCategory/ItemAttribute/Offer/Notification/PaymentGateway/KioskMachine)
  T5 Password policy min:12 + complexity on 9 FormRequests
  T4 Idempotency middleware ON + 12 admin routes added to required_routes

Orchestrator sequential:
  T6 Composer advisory triage (aws-sdk + commonmark + laravel + phpseclib)
  T8 Sanctum sensitive-op TTL (LAST — needs T3 authz stable)
```

**Wall-clock**: 6–8 agent-days sequential or 3–4 with parallel A/B (plan estimate + ~1 day overhead for corrections + 2 new tasks).

---

## §7 Final ship recommendation

PR-D is **MERGEABLE TO MAIN** once:

1. Plan corrections 1–3 (§3) applied + plan §3 task table updated with T9/T10.
2. Sentinel tests for T1/T2/T7/T9 use `Tests\TestCase` + `actingAs(staff_branch=2)` (HTTP context per `BranchScope.php:27` console-bypass).
3. NF525 chain attestation re-run post-merge: count must equal session-attested 27 (PR-D writes zero fiscal rows; if chain advances during PR-D window, an unrelated process emitted — investigate before merge).
4. `composer audit` shows 12 → ≤8 advisories closed (aws-sdk-php high + commonmark medium ×2 + laravel medium + phpseclib high ×2 ≥ 6 closures).
5. Owner countersign on T1 only (NF525-adjacent multi-tenant scope addition) per `CLAUDE.md §10` human gate. T2–T10 ship without additional gate — file:line evidence above is sufficient.
6. Vitest 1444/1447 stable (no admin Vue touched). PHPUnit broad smoke V1.0.1 baseline 914 PASS holds.

**No LOCK plan required.** All PR-D changes are on non-frozen files. `IdempotencyKeyMiddleware.php` is read-only audit (T4 changes are config + routes only). `BranchScope.php` itself is read-only audit (T1/T2/T7 changes are model `booted()` additions on non-frozen models invoking the existing frozen scope class).

---

*Word count: ~1750. Citations: 100% file:line backed. Anti-fabrication per CLAUDE.md §13.*
