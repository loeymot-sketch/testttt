# WI-4 — Routes + Middleware Attack Surface — Round 1 STATUS

**Audit zone**: routes/api.php (1403 LOC), routes/web.php (62 LOC), routes/channels.php (62 LOC), app/Http/Kernel.php, app/Http/Middleware/*, config/idempotency.php, app/Http/Requests/* (97 files), app/Http/Controllers/Admin/* (97 files)

**Specialists**: Architect + Security + SRE + RED (read-only)

**Method**: read-only audit, 4 specialists, ~45 min

---

## VERDICT

**HEAL — 1 P0 + 1 P1 to fix before V1 ship; 6 P2 deferrable; 9 P3 backlog**

The route + middleware surface is well-layered with disciplined throttle/idempotency wiring (26/26 routes covered by config), the channels.php token-name discriminator is intact, the Kernel.php $middlewarePriority is intact, TrustHosts is anchored, TrustProxies is wildcarded for per-IP keying, and ~52 admin controllers have proper __construct middleware. HOWEVER, 3 admin mutation route groups (menu-template, default-access, analytic-section) are reachable by any authenticated Sanctum user — the FormRequest authz returns true AND the route has no permission gate. This is a direct parallel to the WH-5 catch pattern.

---

## P0 — IMMEDIATE FIX

### WI-4-RED-01 — MenuTemplate + DefaultAccess + AnalyticSection mutating endpoints have ZERO authz

**Attack**:
1. Customer self-registers via POST /api/auth/signup/{otp, verify, register} → creates User with role CUSTOMER.
2. Customer logs in via POST /api/auth/login → receives Sanctum token with abilities=`['*']` (LoginController.php:113).
3. With that token + the public x-api-key (scraped from kiosk JS bundle), customer hits:
   - POST /api/admin/menu-template (creates rogue menu template)
   - POST /api/admin/default-access (mutates global default-access matrix)
   - POST /api/admin/analytic-section/{analytic} (mutates tracking pixel config — possibly exposes 3rd-party analytics API keys via subsequent GET)

**Why it slipped past F-2, Z-4, Wave 5H, BUILD-6, WH-5**: those waves focused on FormRequest healing of HIGH-blast endpoints (Pos/Customer/Item/Coupon/Offer/Permission/KioskMachine/DiningTable, plus Administrator post-WH-5). MenuTemplate + DefaultAccess + AnalyticSection were considered SETTINGS-tier and the V1.0.2 backlog mentions them — but no route-level gate was put in their place as the FormRequest is healed.

**Files**:
- routes/api.php:271-274 (default-access prefix)
- routes/api.php:384-390 (menu-template prefix)
- routes/api.php:438-446 (analytic-section prefix)
- app/Http/Controllers/Admin/DefaultAccessController.php (no middleware)
- app/Http/Controllers/Admin/MenuTemplateController.php (no middleware)
- app/Http/Controllers/Admin/AnalyticSectionController.php (no middleware)
- app/Http/Requests/MenuTemplateRequest.php:17 (`return true;`)
- app/Http/Requests/AnalyticSectionRequest.php:16 (`return true;`)

**Fix**: Add `permission:settings` middleware on each of the 3 prefix groups (1-line change per group, no controller refactor needed). Add `RouteCoverage_AdminPermissionGateSentinelTest` sentinel asserting every mutating /api/admin/* route has either route-level `permission:*` OR controller method-level `$user->can(...)` call.

---

## P1 — FIX BEFORE V1 SHIP (recommended)

### WI-4-RED-02 / WI-4-S-02 — PosReceiptPrintController writes audit_logs without permission gate

**Attack**: branch-pinned junior user → POST /api/admin/pos/orders/{order}/print-receipt → audit chain pollution + DUPLICATA flag manipulation (NF525 evidence integrity impact).

**Files**: app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php (no middleware), routes/api.php:840

**Fix**: Add `permission:pos` to route + controller __construct (mirrors ParkedOrderController/FloorplanController/CashDrawerSessionController pattern).

---

## P2 — V1.0.2 BACKLOG (defense-in-depth)

| ID | Finding |
|---|---|
| WI-4-S-04 | Kiosk re-pair TOCTTOU window on Broadcast channel — bounded by Echo reconnect ~1min |
| WI-4-S-05 | apiKey is single shared secret (no rotation, leaked in JS bundles) — needs per-client API key + rotation |
| WI-4-S-06 | CSRF except list enumerates per-gateway slugs — adding a new gateway will break unless list updated |
| WI-4-A-02 | Closure-based POS counter-collect routes (75+ LOC inline) — extract to controller (also blocks route:cache) |
| WI-4-A-03 | 66 FormRequests still `return true;` (sentinel baseline 69, count below) — continue staged refactor; lower baseline to 66 |
| WI-4-A-04 | Closure GET /counter-collect/pending duplicates BranchScope logic in-route |
| WI-4-R-01 | Per-IP throttle resilience under future CDN — add `TrustProxiesIPResolutionSentinelTest` |
| WI-4-R-04 | oss-public branch_id sweep detection deferred |
| WI-4-R-06 | IDEMPOTENCY_FAIL_OPEN posture for single-restaurant deploy — document |

---

## P3 — BACKLOG / COSMETIC

| ID | Finding |
|---|---|
| WI-4-A-01 | Sibling admin throttle groups documented, sentinel exists — closed |
| WI-4-A-05 | Frontend kiosk routes duplicate auth:sanctum+throttle triplets — cosmetic |
| WI-4-A-06 | /health/ready has no throttle — defense-in-depth |
| WI-4-S-03 | Sanctum '*' wildcard ability bypasses `abilities:kiosk:order` — admin tokens already privileged |
| WI-4-S-07 | SPA catchall returns HTML for unmatched /api/* — debugging trap |
| WI-4-R-02 | Item photo upload throttle bucket borderline (30/min admin-mutation) |
| WI-4-R-03 | Ad-hoc throttle:N,M syntax returns HTML 429 instead of JSON — consistency |
| WI-4-R-05 | parent throttle:api min(parent, child) semantics — document |
| WI-4-RED-04 | OPTIONS preflight bypasses throttle:api (CORS handles before throttle) — bounded by allowed_origins |
| WI-4-RED-06 | Fiscal Z-report open/close lacks idempotency middleware — defense-in-depth (Cache::lock + DB serial guard already protect) |
| WI-4-RED-07 | /api/frontend/oss-order branch enumeration — verified no PII, bounded by oss-public 60/min |
| WI-4-RED-08 | installed-flag missing → 503 storm — operational not security |

---

## INTACT POST-DRIFT VERIFICATION (113 commits since)

| Item | Status | Evidence |
|---|---|---|
| Kernel.php $middlewarePriority places EnsureUserStatusActive AFTER AuthenticatesRequests | INTACT | Read app/Http/Kernel.php:85-97 — array unchanged |
| Channels.php token-name discriminator on branch.{branchId} | INTACT | Read routes/channels.php:45 — `$tokenName === 'kiosk-token'` (not tokenCan) |
| Channels.php role-check on admin bypass (closes Guest-Echo-Bypass) | INTACT | Read routes/channels.php:56 — `hasRole('Admin') || hasRole('Tenant Admin')` |
| TrustProxies $proxies='*' for per-IP throttle keying (Wave 3 P1) | INTACT | Read app/Http/Middleware/TrustProxies.php:24 |
| TrustHosts anchored regex (Wave 3c P0 SYNC-ADV3C-01) | INTACT | Read app/Http/Middleware/TrustHosts.php — `^127\.0\.0\.1$` etc. |
| Idempotency required_routes coverage on all 26 idempotency-wired routes | INTACT | `php artisan test --filter=IdempotencyRequiredRoutesCoverageTest` → 1 passed in 0.14s. Test-execution-confirmed. Manual cross-check confirms 26/26 patterns. |
| FormRequest authz drift sentinel (baseline 69) | PASSING with diagnostic | `php artisan test --filter=FormRequestAuthzDriftSentinelTest` → 1 passed in 0.10s; emits `[sentinel] FormRequest return-true count is now 66 (< baseline 69)`. Test-execution-confirmed. |
| MyOrderDetails IDOR heal (S-1 / WH-5 parallel) | INTACT | Route-level alternation gate at api.php:583 + MyOrderDetailsAuthzSentinelTest |
| Administrator@Branch0 mint bypass (WH-5 P0) | INTACT | AdministratorBranchZeroMintBypassSentinelTest scenarios 1+2+3 |
| Cash session controllers gated `permission:pos` / `permission:delivery-boys` | INTACT | Read CashDrawerSessionController:31 + DeliveryBoyCashSessionController (per LIVREUR-Z4-ARCH-03) |
| Per-IP throttle effectiveness on hot endpoints | INTACT | 10 named limiters + 10+ ad-hoc throttles — all keyed via $request->ip() with TrustProxies pass-through |
| ZReport open/close internal idempotency via Cache::lock + DB serial guard | INTACT | Read ZReportService:71-137 |

---

## METRICS

- Routes scanned: ~520 (248 GET + 204 mutation in api.php × 1403 LOC + 1 web payment + 2 channels)
- Mutation routes carrying idempotency: 26/26 in config (verified by sentinel)
- Hot endpoints with explicit throttle: 25 verified (login, signup, password, POS mutations, fiscal, observability, kiosk events, loyalty operations, CSP report, oss-public)
- Spatie permission gates: 78/97 admin controllers gated at __construct; 13 ungated but EITHER route-gated OR in-method-gated; 5 genuinely unprotected (1 dead code, 1 abstract base, 1 intentional public, 1 read-only-low-sensitivity, **3 mutating P0**)
- FormRequest authorize() return-true count: 66 (sentinel baseline 69 — count SHRUNK 3 since last baseline update)
- Sentinel tests verified passing: IdempotencyRequiredRoutesCoverageTest, FormRequestAuthzDriftSentinelTest, MyOrderDetailsAuthzSentinelTest, AdministratorBranchZeroMintBypassSentinelTest, AvailabilityToggleSeparateThrottleSentinelTest

---

## RECOMMENDATION

1. **MERGE BLOCKER**: WI-4-RED-01 — 3-route 1-line-per-route `permission:settings` middleware add + new sentinel `RouteCoverage_AdminPermissionGateSentinelTest`.
2. **RECOMMENDED PRE-SHIP**: WI-4-RED-02 — 1-route + controller __construct add for PosReceiptPrint (defense-in-depth on audit chain).
3. **POST-SHIP V1.0.2**: WI-4-S-05 (apiKey rotation), WI-4-A-02 (closure → controller refactor), WI-4-A-03 (FormRequest hardening continuation).
4. **NO ACTION**: All P3 findings — bounded by existing defenses or operational-only.

---

## DELIVERABLES

- `architect.json` — route layer composition + middleware ordering + idempotency wiring (6 concerns)
- `security.json` — authz coverage matrix + WH-5 parallel detection (7 concerns)
- `sre.json` — rate limit coverage + per-IP keying + throttle resilience (6 concerns)
- `red.json` — adversarial sweep, shadow routes, HTTP verb bypass (8 findings, 1 P0 + 1 P1 + 1 P2 + 5 P3)
