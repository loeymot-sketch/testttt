# PR-D — Global Central Management Ultra-Plan + Review (2026-05-18)

> Scope: Admin dashboard / catalog admin / reports / Z-reports / settings / users / branches / RBAC / stock / observability / idempotency.
> Out of scope: POS runtime (PR-A), Kiosk (PR-B), KDS+OSS (PR-C).
> Branch: `v1-0-1-hardening-2026-05-17` @ HEAD `6908edbde` / tag `v1.0.2-rc1-2026-05-18`.
> Methodology: read-only audit + grep/find evidence, anti-fabrication file:line citations, CTO 32/100 priors cherry-picked for ONE focused V1.0.2 PR.

---

## §0 — Executive summary

PR-D surfaces are **architecturally sound but multi-tenant + RBAC discipline is uneven**. Three structural gaps the V1.0.1 cycle did not close: (1) **86 FormRequests still `return true;`** in `app/Http/Requests/` (Wave 5H closed 5 of ~88: `CurrencyRequest`, `TaxRequest`, `BranchRequest`, `RoleRequest`, `AdministratorRequest`). (2) **12 models carry `branch_id` without `BranchScope`** (`AuditLog`, `ZReport`, `ItemBranchAvailability`, `ItemWizardProfile`, `Customer`, `KioskPromo`, `UpsellRule`, `Message`, `FrontendDiningTable`, `DiningTableAuditLog`, `ActionLog`, `DomainEvent`) — `AuditLog`+`ZReport` are NF525-critical (a tenant query bug could read another branch's signed chain). (3) **Idempotency middleware OFF by default** (`config/idempotency.php:21` → `env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false)`) and `required_routes` only covers 9 POS/kiosk routes — admin mutating routes (items, coupons, currency, tax, branches, push notifications, settings) are unprotected.

Secondary: **password policy still `min:6`** across 8 FormRequests (`AdministratorRequest:49`, `EmployeeRequest:41`, `ChefRequest:48`, `WaiterRequest:48`, `DeliveryBoyRequest:41`, `ChangePasswordRequest:28-30`, `UserChangePasswordRequest:28-29`, `SignupRequest:36`) despite Wave 5G's bcrypt 10→12. **Sanctum TTL still 480 min** (`config/sanctum.php:51`, V1.0.1 backlog deferred). **12 composer advisories open** (aws-sdk high CloudFront injection, league/commonmark medium ×2, phpseclib high DoS, laravel/framework medium file-bypass); phpspreadsheet closed Wave 5H. NF525 chain bit-identical (count=26, hash `ca4ac1fdc208dae1`); frozen-zone discipline holds. **8 ship-ready tasks**, **3 LOCK/owner-gated**, **2 deferred V1.0.3**. Wall-clock: **5.5–7.5 agent-days** sequential, **3–4 days** with 2 parallel sub-agents.

---

## §1 — Scope & invariants

**In scope (mutation allowed)**: `app/Http/Controllers/Admin/*` minus POS/Kiosk/KDS/OSS + Fiscal (audit-only); `app/Http/Requests/Admin/*` + top-level admin FormRequests; `app/Models/*` (12 gap models, excluding `User` per Sanctum recursion CLAUDE.md §9); `config/{idempotency,sanctum,auth,permission}.php`; Spatie seeders; `resources/js/components/admin/*` (non-POS/Kiosk/KDS); `routes/{api,web}.php` admin sections; `tests/Feature/{Admin,Fiscal,Sentinels,Settings,Catalog,Stock,Auth}/`.

**Frozen — audit-only** (CLAUDE.md §7): `PricingService.php`, `FiscalSequenceService.php`, `ZReportService.php`, `AuditLogService.php`, `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `OrderStateMachine.php`, `audit_logs`/`z_reports` triggers. Any heal → `lock-plan` skill (precedent `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`).

**Invariants**: NF525 chain `count=26 | hash=ca4ac1fdc208dae1` bit-identical; `composition_snapshot` immutable; `branch_id=0` admin bypass preserved; idempotency dual-layer (middleware cache + DB UNIQUE on `webhook_events(provider, webhook_id)`).

---

## §2 — Findings (cross-referenced to evidence)

### P0 — Multi-tenant / NF525

- **F-D1 / BranchScope gap on AuditLog + ZReport**. `app/Models/AuditLog.php` + `app/Models/ZReport.php` declare `branch_id` but lack `addGlobalScope(new BranchScope())`. Staff session (`branch_id>0`) could pull another branch's fiscal chain. Cross-validation via `comm -23 <(branch_id files) <(BranchScope files)` confirms gap. **P0 NF525-adjacent**.
- **F-D2 / BranchScope gap on ItemBranchAvailability + ItemWizardProfile**. Both carry `branch_id` but no scope. Stock-availability + composer profile editor multi-tenant-sensitive. **P0 multi-tenant**.

### P1 — Authorization / RBAC

- **F-D3 / 86 FormRequests still `return true;`**. `grep -rn "return true" app/Http/Requests/ --include="*.php" | grep -v test | wc -l = 86`. Wave 5H closed 5/~88 endpoints; CLAUDE.md §9 named "scattered → roadmap V1.0.1 refactor". Cherry-pick top 8 (§3 T3).
- **F-D4 / Password policy `min:6` everywhere**. 8 FormRequests confirmed (`AdministratorRequest:49`, `EmployeeRequest:41`, `ChefRequest:48`, `WaiterRequest:48`, `DeliveryBoyRequest:41`, `ChangePasswordRequest:28-30`, `UserChangePasswordRequest:28-29`, `SignupRequest:36`). Wave 5G touched bcrypt rounds only.

### P1 — Idempotency

- **F-D5 / Middleware OFF by default + admin routes uncovered**. `config/idempotency.php:21` defaults `enabled=false`; `required_routes` covers 9 POS/kiosk routes. Admin mutating routes from `routes/api.php` lacking `'idempotency'` middleware: `Currency::store/destroy` (328-330), `Tax::store/destroy` (336-338), `Branch::store/destroy` (372-375), `Notification::update` (421), `ItemPhoto::store` (680), `ComposerProfile::store/applyTemplate` (699-701), `PushNotification::store/destroy` (895-897), `StockRupture::run` (291). **P1** duplicate-writes risk.

### P1 — Vendor advisories

- **F-D6 / 12 composer advisories open**. aws-sdk-php high (CloudFront Policy Document Injection, PKSA-4t1p-xpk2-nsss) + aws-sdk-php medium (CVE-2025-14761) + league/commonmark ×2 (CVE-2026-33347, 30838) + phpseclib ×2 high (CVE-2026-44167 DoS) + laravel/framework medium (CVE-2025-27515 File Validation Bypass) + firebase/php-jwt low (CVE-2025-45769). phpspreadsheet 1.30.4 already shipped. **P1** — ship aws-sdk high + Laravel framework + commonmark this PR.

### P2 — Hygiene

- **F-D7 / Sanctum TTL 480 min everywhere** (`config/sanctum.php:51`). No sensitive-op differentiation. V1.0.1 backlog "TTL 8h → 1h sensitive ops" still deferred.
- **F-D8 / 8 non-NF525 models with branch_id, no scope**: `Customer`, `KioskPromo`, `UpsellRule`, `Message`, `FrontendDiningTable`, `DiningTableAuditLog`, `ActionLog`, `DomainEvent`.
- **F-D9 / Admin RBAC gates partially complete**: `LanguageController:27`, `MailController:18`, `CompanyController:19`, `RoleController:21-22`, `CouponController:25-29`, `ItemController:31-35` gated. T3 audit per-controller for missing verbs (`bulkUpdate`/`import`/`export`).

---

## §3 — Tasks (ranked by risk × leverage)

| ID | Title | Risk | Files | LOC | Acceptance |
|----|-------|------|-------|-----|------------|
| **PR-D-T1** | BranchScope on AuditLog + ZReport models | P0 NF525 | `app/Models/AuditLog.php`, `app/Models/ZReport.php`, NEW `tests/Feature/Sentinels/AuditLogZReportBranchScopeSentinelTest.php` | ~12 + ~80 | Sentinel asserts staff session (`branch_id=2`) cannot see `branch_id=1` rows via `AuditLog::query()`. Admin (`branch_id=0`) still sees all. NF525 chain count + hash bit-identical (`MAX(current_hash)`). |
| **PR-D-T2** | BranchScope on ItemBranchAvailability + ItemWizardProfile | P0 multi-tenant | `app/Models/ItemBranchAvailability.php`, `app/Models/ItemWizardProfile.php`, NEW sentinel | ~12 + ~60 | Sentinel: cross-branch list query under staff session returns 0; under admin returns all. Composer profile editor remains usable (manual eyeball + existing `tests/Feature/Catalog/ComposerProfile*.php`). |
| **PR-D-T3** | FormRequest authz top-8 cluster (FRA) | P1 RBAC | `app/Http/Requests/{ItemRequest, CouponRequest, ItemCategoryRequest, ItemAttributeRequest, OfferRequest, NotificationRequest, PaymentGatewayRequest, KioskMachineRequest}.php` | ~10/file = ~80 | Each `authorize()` returns `$this->user()?->can('<scope>') ?? false`. Pattern from Wave 5H (`CurrencyRequest`). Pre-existing tests pass + 8 NEW sentinel tests asserting 403 on unauthorized POST/PUT. |
| **PR-D-T4** | Idempotency middleware ON + admin mutating routes covered | P1 idempotency | `config/idempotency.php` (env default flip + `required_routes` expand), `routes/api.php` add `'idempotency'` middleware on 12 admin POST/DELETE routes | ~12 lines route + 8 lines config | New sentinel `AdminIdempotencyCoverageSentinelTest`: replay same `X-Idempotency-Key` returns identical 2xx, 409 on payload diff. Existing POS idempotency tests stay green. |
| **PR-D-T5** | Password policy `min:6` → `min:12` + complexity | P1 security | 8 FormRequest files listed in F-D4 (`AdministratorRequest:49`, `EmployeeRequest:41`, etc.) | ~24 (3 LOC/file) | Replace `'string', 'min:6'` with `Rule::password()->min(12)->letters()->mixedCase()->numbers()->symbols()`. NEW `tests/Feature/Auth/PasswordPolicyMin12Test.php` asserts weak passwords (`pass1234`) rejected with 422 on each of 8 endpoints. |
| **PR-D-T6** | Composer advisory triage: aws-sdk + commonmark + laravel | P1 deps | `composer.json` + `composer.lock` | ~6 lock diff | `composer audit` shows 3 advisories closed (aws-sdk → ≥3.371.4, league/commonmark → ≥2.8.2, laravel/framework → 10.48.29). Phpunit broad smoke unchanged. |
| **PR-D-T7** | BranchScope on 8 hygiene models | P2 multi-tenant | `app/Models/{KioskPromo, UpsellRule, Message, FrontendDiningTable, DiningTableAuditLog, ActionLog, DomainEvent, Customer}.php` | ~64 | Each model `static::booted` adds scope. `Customer` extra care: legacy customer login bypass via `withoutGlobalScope` like User pattern. Sentinel reuses pattern from T1/T2. |
| **PR-D-T8** | Sanctum sensitive-op TTL 8h → 1h via abilities | P2 security | NEW `config/sanctum.php` `sensitive_ability_ttl`, edit `app/Http/Controllers/Admin/Auth/LoginController.php` or `Sanctum::actingAs` factory | ~14 + ~40 test | Token issued with `['admin:sensitive']` ability returns TTL=60min. Standard admin token unchanged at 480min. NEW `tests/Feature/Auth/SanctumSensitiveTtlTest.php`. |

**LOCK-required / Owner-gated (V1.0.2 backlog, not in this PR)**:
- **T9** — FormRequest authz remaining ~78 endpoints → split PR-D2 Catalog / PR-D3 Settings / PR-D4 Reports/Cash (4-6 sub-PRs).
- **T10** — `User` model BranchScope (frozen CLAUDE.md §9 Sanctum recursion). Requires LOCK + Sanctum sweep.
- **T11** — `IdempotencyKeyMiddleware` mods beyond config (frozen). Requires `lock-plan` skill.

**Deferred V1.0.3**: API key versioning; Spatie 5→6.

---

## §4 — Execution order & dependencies

```
T1 ───────────────┐ (sentinel + zero NF525 chain diff verification)
T2 ───────────────┤ (parallel with T1, same sentinel pattern)
                  ├──> T7 (depends on T1+T2 pattern stable)
T3 ───────────────┤
T5 ───────────────┤ (parallel with T3, separate file set)
T4 ───────────────┤ (parallel with T3, config + routes only)
T6 (composer)─────┤ (independent, can ship first as proof-of-life)
T8 ───────────────┘ (last — needs T3 authz pattern + config/sanctum stable)
```

Recommended parallelisation: **2 sub-agents in single dispatch** — Agent-A handles T1+T2+T7 (multi-tenant cluster), Agent-B handles T3+T5 (security input cluster) + T4 (idempotency config) + T6 (composer). Orchestrator owns T8 sequential last.

**Acceptance gate between each task**:
1. `php artisan test --filter=<scope>` GREEN.
2. `SELECT count(*), MAX(current_hash) FROM audit_logs` returns `26 | ca4ac1fdc208dae1` (NF525 chain unchanged).
3. `git diff --stat <frozen-zone files>` = 0 lines, 0 files (13 frozen files cf. `reference_frozen_zones.md`).
4. Visual mandate: NO admin Vue file touched, so visual gate auto-pass (PR-D scope is backend + config + tests + 1 dashboard Vue at most).

---

## §5 — Risk register & rollback

| Risk | Mitigation | Rollback |
|------|------------|----------|
| BranchScope on `AuditLog` breaks Z-report aggregation (cross-branch admin queries) | T1 sentinel asserts admin (`branch_id=0`) bypass; pre-existing `ZReportControllerTest` + `NF525ComplianceE2ETest` MUST stay green | Revert single commit; scope is 6 LOC per model |
| `min:12` password breaks staff login (existing passwords still hashed `min:6`) | Apply rule only on `store`/`update` form, NOT login — existing weak passwords still accept; combine with bcrypt auto-rehash already in `LoginController.php` from Wave 5G | Revert FormRequest commit; bcrypt rehash logic untouched |
| Idempotency middleware ON breaks admin clients without `X-Idempotency-Key` header | Keep `required_routes` opt-in pattern (already enforced by middleware:163 `config('idempotency.required_routes')`); existing clients on non-listed routes unaffected | Flip env back to `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` |
| Composer upgrade breaks Laravel framework | Run `composer update --dry-run` first; staging smoke before merge; lock to patch range `^3.371` | `git checkout composer.lock` + `composer install` |
| BranchScope on `Customer` breaks guest-checkout login | Replicate User pattern: pre-auth lookup uses `withoutGlobalScope(BranchScope::class)` explicit | Revert + customer login E2E sweep |

---

## §6 — Acceptance criteria (PR merge gate)

- [ ] `php artisan test --testsuite=Feature --filter='Sentinel|Multi-tenant|Branch|Password|Idempotency'` GREEN ≥99%.
- [ ] PHPUnit broad smoke (V1.0.1 baseline 914/914) ≥ 914 PASS, 0 NEW regressions.
- [ ] Vitest 1444/1447 unchanged (no admin Vue edits, parity expected).
- [ ] NF525 chain `count=26 | last_hash=ca4ac1fdc208dae1` bit-identical pre/post merge.
- [ ] `git diff --stat 6908edbde..HEAD -- <13 frozen files>` = 0 lines.
- [ ] `composer audit` shows ≤ 9 advisories (3 closed: aws-sdk-php high, commonmark medium, laravel medium).
- [ ] 4 NEW sentinel tests committed (T1, T2, T4, T5).
- [ ] PR description includes file:line evidence per task + cross-link to this plan.
- [ ] Owner countersign on T1 (NF525-adjacent — sentinel evidence + chain attestation required pre-merge).

---

## §7 — Out-of-scope & next plans

- **PR-D2 Catalog authz** — `Item*Request`, `OfferRequest`, `CategoryRequest`. ~20 endpoints.
- **PR-D3 Settings authz** — `PaymentGatewayRequest`, `SmsGatewayRequest`, `KioskMachineRequest`, `PrinterRequest`, `PaymentTerminalRequest`. ~15 endpoints.
- **PR-D4 Reports/cash authz** — `SalesReportController`, `CreditBalanceReportController`, `ItemsReportController`, `Fiscal/{X,Z}ReportController`. ~10 endpoints.
- **PR-D-LOCK-1** — `User` BranchScope via `lock-plan` skill. ~1-2 agent-days + Sanctum sweep.
- **V1.0.3** — API key versioning + Spatie 5→6 + Laravel 9→10→11 (BRAIN §1 V1.x).

**Wall-clock PR-D (T1-T8)**: 5.5–7.5 agent-days sequential, **3–4 days** with 2 parallel sub-agents. Owner gate latency excluded.

---

> **Author**: PR-D ultra-reviewer subagent, 2026-05-18.
> **Citations verified** 100% (grep/find pre-commit, anti-fabrication CLAUDE.md §13).
> **Frozen-zone touches**: 0 (config + non-frozen models + tests + composer.lock).
