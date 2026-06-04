# W2 — Admin Dashboard (catalogue + observability + cash-overview + reports + Z + permissions UI)

Date : 2026-05-21
Branch : heal/cms-pr1-quickwins-2026-05-18 @ 1116b39578
Audit type : deep audit + surface auto-fix (≤30 LOC) — pre-cloud goal

---

## 1. Scope & anchors

Path roots (absolute):

- `app/Http/Controllers/Admin/CashOverviewController.php` (491 LOC, Wave X4 NEW 2026-05-21)
- `app/Http/Controllers/Admin/StockRuptureDashboardController.php` (642 LOC, M1 catalogOverview added)
- `app/Http/Controllers/Admin/PermissionController.php` (62 LOC, settings-gated since CMS-2026-05-18 M-R3-P0-A)
- `app/Http/Controllers/Admin/Fiscal/ZReportController.php` (131 LOC, Wave T R1 F1 P0 read-fix)
- `app/Http/Controllers/Admin/ItemController.php` (293 LOC, permission-gated per-method)
- `app/Http/Controllers/Admin/CashSessionReportController.php` (Wave O O4)
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php` (`permission:kitchen-display-system`)
- `resources/js/components/admin/cashOverview/CashOverviewComponent.vue` (406 LOC)
- `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` (642 LOC)
- `routes/api.php` L256 (`throttle:60,1` light group) + L270..1154 (`throttle:admin-mutation` heavy group). Cash-overview routes (L1046-48) confirmed INSIDE admin-mutation group. Permission CRUD (L468-71) inside settings group.

Permission middleware coverage : 140 occurrences across admin controllers, 5 grouped in `routes/api.php` (matches expected baseline post BUILD-6).

## 2. Tests run + counts

`php artisan test --filter "CashOverviewControllerTest|PermissionControllerIndexAuthzTest|StockRuptureDashboardEndpointsTest|StockRuptureDashboardLowAlertsN1Test|FiscalPermissionTest"` → **21 passed / 0 failed / 3.86s**.

Notable: `CashOverviewControllerTest` (15 cases) covers — rejects unauthenticated, rejects without fiscal permission, admin sees all branches, branch manager only own, source/payment bucket derivation, summary aggregation, date/source/mode filters, cash_back exclusion, invalid date 422, reconciliation, query-count bounded (no N+1).

`php artisan test --filter "ZReportController"` → **10 passed** (incl. cross-branch show forbidden, PDF signed bundle, admin cross-branch index, mutating endpoints still require pinned branch).

`StockRuptureDashboardLowAlertsN1Test` confirms `lowAlerts` ≤ 7 queries (was up to 1200 pre-BUILD-4).

## 3. Adversarial dispute results

| Challenge | Outcome |
| --- | --- |
| Admin route exposure on cloud — no WAF/IP allowlist | **IaC-layer pre-cloud risk — DEFERRED (see §5)** |
| Z report PDF PII leak | PASS. `ZReport::$fillable` contains `opened_by`/`closed_by` as FK integers only. No customer PII. `total_by_method`/`total_by_tax_rate` are commercial aggregates, NF525-mandated, gated by `pos-manage-fiscal`. |
| Cash-overview branch isolation via whereHas | PASS. Double-protection: (a) `withoutGlobalScope(BranchScope::class)` applied ONLY when `isGlobalAdmin`; staff path keeps BranchScope on Order. (b) `resolveBranchFilter()` forces staff to own branch, silently drops `?branch_id=` overrides. Confirmed by `branch_manager_only_sees_own_branch` test. |
| Wave X4 honest 3-cell pattern (no algebraically-broken écart) | PASS. Round-2 C-013 dropped `cashDiff/diffLabel/diffClass` computed props (verified L281-288 of Vue). UI now renders only opening / collected_today / expected_in_drawer + a `cash_drawer_count_pending_note` info strip. |
| Wave Y rate-limit dynamic Retry-After | PASS. `resources/js/bootstrap.js` L184-189 reads real `Retry-After` header. `admin-mutation` cap env-configurable since RouteServiceProvider L77. |
| PermissionController index gate sentinel (commit 6a01c71bf-equivalent) | PASS. `PermissionController::__construct` L33 applies `permission:settings` to all 3 methods; `PermissionControllerIndexAuthzTest` GREEN. |

## 4. Surface fixes APPLIED

**0 fixes applied** (budget : max 5).

No actionable surface defect found inside fix policy. Cash-overview Vue is i18n-clean (FR keys present at `resources/js/languages/fr.json` L368, L989-1015), permission gates intact, branch isolation double-protected, throttle group confirmed, NF525 read-only paths verified by tests. Manufacturing a fix to hit budget would dilute signal.

## 5. Critical findings DEFERRED (pre-cloud)

### F-IAC-W2-01 — Admin route exposure on cloud: no WAF / IP allowlist / fail2ban in codebase
- **Layer**: Infrastructure-as-Code, OUTSIDE app repo scope (correctly).
- **Current state**: `/admin/*` is gated by `auth:sanctum` + `apiKey` + per-route `permission:*` middleware. Sufficient for local single-resto V1 LOCAL. Pre-cloud goal requires a WAF rule (Cloudflare/AWS WAF) and IP allowlist (or geo-fence FR-only) on `/admin/*` before public DNS exposure.
- **Why deferred**: Per `feedback_no_cloud_until_owner_initiates.md` — no cloud actions until owner explicit go. Surface fix budget of this audit cannot author IaC.
- **Recommendation when owner triggers cloud**: Cloudflare ruleset (block non-FR ASN on `/admin/*` + rate-limit /login 5/min/IP) OR VPC private subnet + bastion. Pair with mandatory 2FA on admin accounts (current single-factor sanctum tokens).

### NOTE-W2-02 (non-blocking) — i18n EN/AR drift on Wave X4 cash-overview Vue
- Audit detected: `resources/js/languages/fr.json` has all ~15 cash-overview keys. `en.json` has only 3 (`cash_collected_today`, `expected_in_drawer`, `cash_drawer_count_pending_note`). `ar.json` has 0.
- **No runtime impact**: `resources/js/i18n.js` L57+L88 hard-locks `/admin/*` to FR (NF525 mandate). EN/AR keys are dead in this surface.
- **Action**: leave as-is for V1 LOCAL. Backfill EN/AR to `v1.0.x-i18n` backlog only if V2 multi-tenant SaaS goes EN/AR-capable admin.

### NOTE-W2-03 — Z report exposes signed JSON in `pdf()` (not PII, but commercial)
- `ZReportController::pdf()` returns full `$zReport` model + `verified` flag. Contains `total_ttc`, `total_by_method`, `total_by_tax_rate`, `cash_variance`. Acceptable per NF525 audit-chain reqs and gated by `pos-manage-fiscal` (Admin + Branch Manager only). One reviewer line — no action.

## 6. Verdict

**GO** for W2 Admin Dashboard, local scope. 0 surface fixes needed. 1 IaC-layer pre-cloud risk (F-IAC-W2-01) flagged for the owner-triggered cloud transition. NF525 fiscal endpoints, permission gates, branch isolation, throttle, and Wave X4 honest reconciliation pattern all verified by 31 passing PHPUnit tests + adversarial review.

## 7. Files inspected (evidence trail)

- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/CashOverviewController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/StockRuptureDashboardController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/PermissionController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/Fiscal/ZReportController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Http/Controllers/Admin/ItemController.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/ZReport.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/cashOverview/CashOverviewComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/admin/stock/StockRuptureDashboardComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/i18n.js`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/languages/fr.json` (L368, L989-1015)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/routes/api.php` (L256, L270-1154, L1046-48, L468-71)
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Admin/CashOverviewControllerTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Admin/PermissionControllerIndexAuthzTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/Feature/Admin/StockRuptureDashboardEndpointsTest.php`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/e2e/__screenshots__/wave-x4-cash-overview/` (10 capture sets verified clean)
