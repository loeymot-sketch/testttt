# GOAL_MGMT_TESTPLAN — Execution Convergence (management/dashboard/historique/data)

Executed 2026-06-01 (owner: "do the remaining of goal, max reasoning"). Read/capture + deep adversarial code-audit; **2 P1 security holes healed with TDD**. 0 frozen-zone touch.

## Verdict: management surface FUNCTIONAL + reachable; data well-recorded; **11 findings HEALED with TDD** (3 P1 + 4 P2 + 4 P3) + USR-RBAC-02 extended across all 4 staff services, owner secure-default policy applied. 3 items remain (1 cosmetic frontend, 1 risk-assessment, 1 owner-intent) + soak redo.

### Heals applied this cycle (all TDD RED→GREEN, non-frozen, frozen-diff 0)
| Finding | Sev | Fix | Commit |
|---|---|---|---|
| SET-01-PG | P1 | PaymentGatewayController `->only('index','update')` (secret leak) | 24325ac6b |
| SET-01-SMS | P1 | SmsGatewayController `->only('index','update')` (secret leak) | 24325ac6b |
| USR-RBAC-01 | P1 | EmployeeService `callerMayGrantRole()` strict-subordinate gate (privilege escalation) | (this cycle) |
| REP-AUTHZ-01 | P2 | SalesReportController add `'overview'` to gate (revenue leak) | b180f14b7 |
| COUPON-CAP-01 | P2 | CouponService enforce `max_uses_global` via order_coupons count | (this cycle) |
| USR-RBAC-02 | P2 | EmployeeService `effectiveBranchId()` force own-branch for non-settings (EmployeeService) | (this cycle) |
| NC-MSG-CHANGESTATUS-GATE | P3 | MessageController add `'changeStatus'` to gate | b180f14b7 |
| CAT-DATA-02 | P3 | ItemCategoryRequest `->whereNull('deleted_at')` (reusable name) | (this cycle) |
| USR-RBAC-02 (×3) | P2 | Own-branch guard extended to Chef/Waiter/DeliveryBoy via shared `EnforcesOwnBranchScope` trait | 29a1c…/cross |
| USR-RBAC-03 | P3 | EmployeeService syncRoles moved inside the DB transaction (atomic) | 29a1c3431 |
| NC-MSG-UPDATE-DEAD | P3 | Removed dead `PUT /admin/message/{message}` route (no update method) | 29a1c3431 |
| CAT-AUTHZ-01 | P2 | ItemPhotoController Admin/Tenant-Admin gate (parity with change-image) | (this cycle) |
Owner policy (2026-06-01 "défauts sûrs"): settings-holder grants any non-core role / any branch; non-settings staff grants only strict-subordinate roles + own branch only. 5 new sentinel tests. Regression green throughout.

### Remaining (3 items — each needs build / risk-assessment / owner-intent, not a safe blind heal) + soak
- **DASH-01 (P2, cosmetic):** relabel "Total commandes" KPI → "Commandes livrées" — frontend (OverviewComponent.vue + i18n) change requiring an **admin bundle rebuild** + visual recapture. Deferred to a frontend pass.
- **REP-ANALYTIC-01 (P3):** AnalyticController index/show ungated — gating on `permission:settings` **risks breaking the dashboard analytics widget for non-settings admins**; needs a consumer check before gating (not a blind heal).
- **REP-ITEMS-01 (P2):** items-report date filter applies to `Item.created_at` (catalog creation) not order date — **owner-intent decision** (is the filter meant to be "items created in range" or "items sold in range"?) before changing semantics.
- **Soak redo (owner-sequenced):** clean 10h `e2e:soak` run with the server alone.

## A. Page/nav reachability — ✅ whole surface works, 0 dead pages
- **27/27 sidebar buttons** → real named routes (0 orphan/404) → working pages (live-rendered).
- **Settings:** 8 V1-exposed sub-pages render with forms; ~14 others **deliberately V1-hidden** via `resources/js/config/v1-hidden-modules.js` (offers, cookies, languages, loyalty-setup, mail, notification(+alert), otp, pages, sliders, social-media, tax, theme, time-slots) — by design, code/routes kept. The discovery's "not-in-nav" flags = these intentional hides, NOT orphans.
- Also render-verified: coupons, offers, customers, delivery-boys, RBAC roles, ingredients, transactions (620), sales-report (3388/31 632€).

## B. Data-recording integrity (3388 orders under load) — ✅ well-recorded
0 dup fiscal · 0 gap · 0 orphan order_items · 0 missing composition_snapshot · NF525 CHAIN OK · z-membership OK. The 3 flags = pre-existing seed fixtures (NF525-safe) + a refund + a cashback (all correct). Crucial-spine tests 46/46 green.

## C. Deep adversarial breadth audit (workflow wf6dhhn09, 15 agents, 941k tok) — 14 real findings
Theme: a cluster of **ungated READ endpoints** exposing data/secrets to non-entitled staff.

### P1 — HEALED (TDD, this session)
- **SET-01-PG** — `PaymentGatewayController:21` gated only `update` → `index()` leaked gateway secrets (stripe_secret, paypal_client_secret…) via `GatewayOptionsResource` to any non-settings staff (Branch Manager/POS Operator/Chef). **HEAL:** `->only('index','update')` (mirrors Mail SET-02 / KioskSetup / LoyaltySetup). Sentinel `GatewaySecretIndexAuthzSentinelTest` GREEN.
- **SET-01-SMS** — same pattern, `SmsGatewayController:22`, SMS secrets (twilio_auth_token, nexmo_secret…). **HEALED** same way + sentinel.

### P1 — ESCALATED (owner policy decision)
- **USR-RBAC-01** — `EmployeeService.php:25` blocklist only blocks roles 1-5 (Admin/Customer/DeliveryBoy/Waiter/Chef); `assignRole()` accepts 6 (Branch Manager), 7 (POS Operator), 8 (Stuff) with NO caller-entitlement check. A Branch Manager (`employees_create`, no `settings`) can mint another Branch Manager / POS Operator → propagates pos-refund/pos-manage-fiscal/pos-reopen-z/cash-variance-override. **Privilege escalation.** Fix needs a policy: should a Branch Manager be allowed to hire POS Operators (likely yes) but NOT mint peers/privileged roles (no)? Proposed: gate granting of roles 6/7/8 on `permission:settings` (admins only). → **owner decides the role-grant policy.**

### P2 (5) — documented (owner-gate)
- **REP-AUTHZ-01** — salesReportOverview revenue aggregate NOT permission-gated (revenue readable by non-report staff). Info disclosure.
- **DASH-01** — `DashboardService::totalOrders():344` counts DELIVERED-only, rendered as "Total commandes" (=3 vs 1755/jour) — misleading label.
- **COUPON-CAP-01** — `max_uses_global` unenforced (`usage_count` never incremented) — global coupon cap ineffective.
- **USR-RBAC-02** — Employee/Chef/Waiter/DeliveryBoy create+update write `branch_id` directly from request (cross-branch assignment by a branch manager).
- **CAT-AUTHZ-01** — `ItemPhotoController` photo route bypasses the Admin-only catalog-photo reservation (LATENT: `items_edit` held only by Admin in seeded V1 → currently unreachable; becomes live if granted to a branch role).
- **REP-ITEMS-01** — items-report date filter applies to `Item.created_at` (catalog creation) not order date — wrong report semantics.

### P3 (6) — backlog
CAT-DATA-02 (category name uniqueness missing soft-delete clause), OFFERITEM-PERM-01 (destroy gated by read-perm; offers V1-disabled), USR-RBAC-03 (syncRoles outside DB::transaction), NC-MSG-CHANGESTATUS-GATE (message changeStatus ungated), NC-MSG-UPDATE-DEAD (dead PUT route), NC-MSG-RECEIVER-UNVALIDATED, REP-ANALYTIC-01 (analytics reads ungated).

> Workflow note: 3 Verify-phase skeptics failed to return StructuredOutput; the 3 P1s shown ARE cross-validated (refuted_votes counted) + I independently re-verified all 3 in code before healing.

## D. State
PHP heal: 2 controllers (`PaymentGatewayController`, `SmsGatewayController`) `->only('update')` → `->only('index','update')` (+1 sentinel test). **Non-frozen, ≤30 LOC, TDD RED→GREEN, regression: FormRequestAuthzDrift + PermissionIndexAuthz still green.** Frozen-zone diff = 0. NF525 CHAIN OK. No push.

## E. Remaining GOAL backlog (not yet executed)
Author the ~50 `(TO BE CREATED)` hardening tests (HIST/ENC/DASH gap tests); destructive CRUD save-tests on the 8 settings pages + users; heal the escalated P1 + P2 cluster after owner policy decisions; round-2 + final supervisor + the clean 10h soak redo.
