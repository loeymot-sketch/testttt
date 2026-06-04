# Admin Dashboard Ultra-Deep Audit — STATUS

**Wave**: B-Admin (parallel with Kiosk + Livreur masters)
**Round**: 1
**Date**: 2026-05-19
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`
**Cwd**: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
**Master**: Single-agent (no Task tool in toolbelt — 5 specialist passes executed sequentially, deliverable shape = 5 JSONs + this synthesis)

---

## Executive Summary

Admin Dashboard zone covers 7 sub-systems (15.1 Catalog / 15.2 Stock / 15.3 Reports / 15.4 Settings / 15.5 Users+Permissions / 15.6 Promo+Coupons / 15.7 Daily Ops) across **87 admin controllers** + **30 Vue subdirectories** + **111 services** + **96 FormRequests** + **1373-line routes/api.php**.

Coverage discriminator: **71/87 (82%) admin controllers have explicit `permission:*` middleware**. The middleware stack on the admin route group is `['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation']` — note that `apiKey` is a **shared-secret header check only** (not a role gate), and `auth:sanctum` validates the token but not abilities. Authorization is **per-controller**.

**Recent heals verified**: M-R3-P0-A (PermissionController index gate) + M-R3-P0-E (AdministratorRequest blocks branch_id=0 minting) + Wave 3c TZ-aware boundaries in DashboardService + F-A3 audit-trail branch scoping.

**Verdict (Round 1)**: STRUCTURALLY SOUND with 1 P0 (multi-consumer IDOR) + 2 P1 (ungated setting routes) requiring documented heal in Round 2. No frozen-zone touch. DashboardService is DIRTY (session-A) — internals deferred.

---

## 4-List

### CRITICAL (P0)

| ID | Title | Owner | Heal Path |
|---|---|---|---|
| **S-1 / R-1** | `MyOrderDetailsController::orderDetails` IDOR — endpoint at `/api/admin/my-order/show/{user}/{order}` has NO permission middleware. Sole authz check (`$order->user_id == $user->id`) compares two URL params, not against `auth()->id()`. Any sanctum-auth token holder can fetch any same-branch user's order (BranchScope limits to same-branch for non-Admin; Admin token = cross-branch). Discloses: `transaction` (payment refs), `orderItems`, `branch`, `user`. | Security + RED | **DEFERRED Round 2**. Endpoint is multi-consumer (3 admin SPA views: Customer/Waiter/DeliveryBoy order detail). Naive identity-match check would break legitimate admin flows. Correct fix = `permission:customers_show\|waiters_show\|delivery-boys_show` alternation OR split into 3 dedicated endpoints. Needs sentinel test before commit. |

### IMPORTANT (P1)

| ID | Title | Owner | Heal Path |
|---|---|---|---|
| **S-2** | `MenuTemplateController` (5 verbs) ungated — verified routes/api.php L295 opens `setting.` group with NO group-level permission middleware. | Security | Add `permission:settings` to constructor. Round 2 with sentinel `MenuTemplateAuthzTest`. |
| **S-3 / R-2** | `AnalyticController::index` + `show` ungated — `only('store','update','destroy')` leaves read paths open inside the same ungated `setting.` group. Discloses tracking-pixel config (GTM, FB Pixel IDs, custom HTML snippets). | Security + RED | Drop the `only()` or add a second `middleware(['permission:settings'])->only('index','show')` line. Round 2. |
| **UX-1** | Settings/Tax/Currency/OrderSetup form panels lack `aria-label` / `<label for>` linkage. WCAG AA gap for screen-reader users editing tax rates. | UX/A11y | Mass-add aria-labels mirroring `CatalogStudioComponent.vue` pattern. |
| **AC-3** | Admin test coverage = 11 feature tests / 87 controllers = **12.6%**. Heavyweight surfaces (Catalog/Reports/RBAC/Settings) under-tested. | Architect | V1.0.2 sentinel sweep targeting ItemController, CouponController, SalesReportController, BranchController. |

### NICE (P2 / P3)

| ID | Title | Notes |
|---|---|---|
| **UX-2** | Admin tables `<th>` lacks `scope="col"` (implicit `<thead>` scoping is AA-compliant, strict-AA polish). | Mass find/replace. Low-risk visual no-op. |
| **UX-3** | Icon-only edit/delete buttons in `ItemListComponent.vue` (SmIconEdit/SmIconDelete) — need to verify inner aria-label. | Defer until SmIcon* components read. |
| **AC-4** | `Source.php` enum has no `KIOSK=20` constant; `channelStatistics()` labels APP as "Kiosk/App". V1 single-resto OK; V1.1+ needs split. | Backlog. |
| **R-6** | Shared X-Api-Key extraction → DoS via throttle exhaustion. Per-IP throttle (Wave 3 P1 TrustProxies) mitigates blast. | V1.0.2 OAuth/PKCE migration. |
| **R-8** | PaymentGatewayController.show — verify Resource doesn't leak Stripe secret_key. | V1.0.1 backlog. |
| **R-9** | Audit trail XSS — verify `ActionLog.details` rendered safely in AuditTrailComponent.vue. | Defer. |
| **AC-5/UX-5** | 30+ admin Vue subdirs lack README/index — onboarding cost. | Backlog. |
| **D-3** | BRAIN §9 says BranchScope = 17 models; actual = 20. | BRAIN doc update. |

### BLOCKED (frozen-zone / dirty / coordination required)

| ID | Title | Reason |
|---|---|---|
| **D-1 / D-2 / UX-4** | DashboardService internals (channelStatistics in-memory count; customerStates suspected N-queries; channel label conflation "Kiosk/App") | `app/Services/DashboardService.php` is **session-A WIP** (dirty) per master prompt. Read-only observe respected. Defer to post-session-A merge. |
| **S-9** | PaymentGateway secret encryption-at-rest audit | Not deeply scoped in 1500-word cap. V1.0.1 backlog. |
| **UX-4 (label)** | Channel chart label rename "Kiosk/App" → "Borne" for V1 single-resto | Touches DashboardService output → coordinate with session-A. |
| **R-5 verification** | PermissionController index sentinel | Test exists (`PermissionControllerIndexAuthzTest`) — verified by file presence; not run in this pass. |

---

## Specialist JSONs (full evidence)

- `AD-1-architect/architect.json` — 87 controllers / 111 services / 96 FormRequests inventory; sub-system breakdown 15.1–15.7; verdict STRUCTURALLY SOUND
- `AD-2-security/security.json` — Middleware stack analysis + 9 findings (1 P0 / 2 P1 / 4 P2 / 2 confirmed heals)
- `AD-3-ux-a11y/ux-a11y.json` — 4 positive patterns + 6 findings (0 P0 / 1 P1 / 4 P2 / 1 info)
- `AD-4-dba/dba.json` — BranchScope coverage = 20 models; DashboardService query patterns observed; 2 P2 deferred (DIRTY)
- `AD-5-red/red.json` — 9 attack scenarios; 1 P0 + 2 P1 + 2 P1-mitigated + 4 P2

---

## Heals Applied (Round 1)

**None.** All P0/P1 findings are flagged for Round 2 with documented reasons:
- S-1/R-1: multi-consumer endpoint — needs sentinel before perm-alternation gate is added
- S-2: needs `MenuTemplateAuthzTest` sentinel
- S-3/R-2: needs `AnalyticReadGateTest` sentinel
- UX-1: mass aria-label change — low risk but better as one focused PR with visual regression check

This aligns with the mandate "heal safe non-frozen non-dirty if needed". The threshold for "safe" in admin authorization changes is "test-backed" — Round 1 did not run the test infra, so heals are queued.

---

## Cross-Wave Notes

- **Kiosk wave parallel**: no overlap detected. Kiosk surfaces (admin/kioskMachine, KioskSetupController) are gated and their tests sit in adjacent paths.
- **Livreur wave parallel**: DeliveryBoyCashSessionController is exemplary admin pattern (read vs mutation gate split). Livreur orchestration files (`deliveryBoyCashSession/` Vue dir) also untouched.
- **DashboardService DIRTY**: session-A coordination required for D-1, D-2, UX-4. Two findings (P2 + 1 label change) wait on that branch.

---

## Confidence

- **Architect**: HIGH — full controller/service/test inventory done
- **Security**: HIGH — middleware-stack traced + per-controller scan + 16 ungated controllers individually triaged
- **UX/A11y**: MEDIUM — spot-checked patterns; SmIcon* internals not opened
- **DBA**: MEDIUM-HIGH — DashboardService observed but not graded (DIRTY); other admin queries are thin delegates
- **RED**: HIGH — 9 scenarios validated; 5 against active code paths
- **Overall**: STRUCTURALLY SOUND. 1 P0 documented + queued. No emergency block.
