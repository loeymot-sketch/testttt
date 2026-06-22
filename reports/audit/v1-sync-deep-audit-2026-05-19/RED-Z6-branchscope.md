# RED-Z6 — BranchScope Multi-Tenant Audit

**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z6
**Scope**: BranchScope correctness across declared models + `withoutGlobalScope*` usages + admin/staff bypass logic. Lane-fenced (Z1 stock / Z2 order-lifecycle / Z3 sync / Z4 pricing / Z5 fiscal / Z7 idempotency / Z8 refund owned elsewhere).

---

## A. Anchors verified (file:line)

1. **Frozen scope core**: `app/Models/Scopes/BranchScope.php:13-42` — 42 LOC. Logic: skip `User` model (Sanctum recursion guard), skip when console+!testing, then `userBranch === 0 → no filter` (admin), else `WHERE table.branch_id = $userBranch` (FIX-54-8: staff NEVER see branch_id=0 rows). Read end-to-end.
2. **Nullable-variant scope**: `app/Models/Scopes/WizardProfileBranchScope.php:33-58` — 26 LOC. Same recursion guard; admin no-filter; staff `WHERE branch_id_scope IS NULL OR = $userBranch` (NULL = global publish).
3. **Branch resolver**: `app/Traits/DefaultAccessModelTrait.php:14-30`. Reads `DefaultAccess(user_id, name='branch_id')`. Fallback to `Auth::user()->branch_id`. Admin (branch_id=0) returns 0 → no filter.
4. **CLAUDE.md drift sentinel (WJ-7)**: `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest.php:37-122`. Asserts §9 text "**20 models**" equals `grep -c 'addGlobalScope(new BranchScope'` on `app/Models/*.php` depth 0. Second test bans "User model exempté" stale phrase and requires "Customer".
5. **Coverage sentinel**: `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php:48-66` — `EXEMPTED_MODELS` list: Branch (self-ref), Customer (Sanctum recursion), + 10 BASELINE_V1_2026-05-18 V1.0.2 BACKLOG (FrontendDiningTable, ZReport, AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo, UpsellRule, ActionLog, DomainEvent). Test (lines 68-124) walks `app/Models/*.php` depth 0, for each Model w/ `branch_id` column AND not exempted, asserts `addGlobalScope(new BranchScope` regex matches.
6. **Queue-context sentinel**: `tests/Feature/Sentinels/F010BranchScopeQueueContextSentinelTest.php:67-95`. Locks the `!runningInConsole() || runningUnitTests()` clause in BranchScope::apply. Documents `CleanupStalePendingKioskOrders` as the only legitimate explicit `withoutGlobalScope(BranchScope::class)` job (W9-AUDIT FIX-5).
7. **Total grep**: `grep -c "addGlobalScope(new BranchScope"` returns **20** files declaring BranchScope (CashDrawerSession, CashMovement, DeliveryBoyCashMovement, DeliveryBoyCashSession, DiningTable, FrontendOrder, ItemBranchAvailability, KioskMachine, Order, OrderItem, OrderPayment, OrderQuote, PaymentTerminal, PendingPaymentConfirmation, PosParkedOrder, Printer, PushNotification, StockLevel, StockMovement, User). 21st model `ItemWizardProfile` uses `WizardProfileBranchScope` (nullable). Total = 21 with scope.
8. **Total `withoutGlobalScope*` call sites**: 62 occurrences across `app/` (controllers, services, jobs, commands, FormRequests).

---

## B. Findings P0 → P3

### P0-Z6-01 — CROSS-BRANCH LOYALTY REDEEM via `withoutGlobalScopes()` with NO post-fetch branch check
**File**: `app/Http/Controllers/Admin/PosLoyaltyController.php:41`
```php
$order = Order::withoutGlobalScopes()->find($orderId);
if (!$order) { return response()->json([...], 404); }
// → straight to $this->redemptionService->applyToOrder($order, ...);
```
**FormRequest gate** (`app/Http/Requests/PosLoyaltyRedeemRequest.php:22-25`) only checks Spatie permission `pos.redeem-loyalty` — no branch ownership check on `$orderId`. The controller comment on line 37-40 admits "scope-minimal here" and *assumes* the permission gate filtered, but Spatie permission is global per-user, not branch-bound. The service (`app/Services/Loyalty/PosRedemptionService.php:195`) again uses `Order::withoutGlobalScopes()->where('id', ...)->update(...)`.

**Exploit**: A cashier holding `pos.redeem-loyalty` on branch=5 can `POST /api/admin/pos-order/{N}/redeem-loyalty` with `N` an order id from branch=3, applying a loyalty discount and `loyalty_customer_code` mutation on a foreign branch's order. Audit log will show wrong branch context. Anti-fraud §6.2 single-redemption check WILL still hold (UNIQUE constraint), but the financial mutation goes through.

**Cross-comparison**: `app/Http/Controllers/Admin/PosOrderController.php:113-121` does the *correct* pattern — `Order::withoutGlobalScope(BranchScope::class)->findOrFail($order)` followed by explicit `abort_unless(auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id, 403, ...)`. PosLoyaltyController introduced 2026-05-19 (LOCK_POS_LOYALTY_REDEEM_UI) silently dropped this guard.

**Severity**: P0 — financial mutation + branch-isolation breach + audit-trail dirty.
**Heal**: Insert `abort_unless($order->branch_id === auth()->user()->branch_id || auth()->user()->branch_id === 0, 403)` between line 41 and 50. Existing pattern proven in PosOrderController:117-121.

### P1-Z6-02 — POS-OPERATOR FormRequest baseline at 69 still permits silent branch-write drift
**Anchor**: CLAUDE.md §9 lines about `FormRequestAuthzDriftSentinelTest` baseline-lock at **69 FormRequests with `return true;`** remaining (WAVE 8 → BUILD-6). This RED-Z6 did not enumerate them all but `PosLoyaltyRedeemRequest::authorize()` (which IS hardened with `can(...)`) co-exists with the 69 unscrutinised. Per CLAUDE.md "count GROWS = CI fails", count SHRINKS is healthy. As long as the 69 baseline lives, any of those FormRequests can hide an order/payment mutation lacking branch authz. V1.0.2 backlog item — not a blocker today, but flagged for Z6 zone hygiene.
**Severity**: P1 chronic — not a single-controller bug, a fleet-wide drift posture.

### P1-Z6-03 — `withoutGlobalScopes()` (plural) used 17× — kills BranchScope AND SoftDeletingScope simultaneously
**Files**: `app/Http/Requests/DeliveryBoyCashSessionOpenRequest.php:51`, `app/Http/Requests/PosOrderRequest.php:229`, `app/Http/Controllers/Auth/GuestSignupController.php:98`, `app/Http/Controllers/Admin/DeliveryBoyCashSessionController.php:135`, `app/Http/Controllers/Admin/PosLoyaltyController.php:41`, `app/Jobs/CleanupStalePendingKioskOrders.php:25-30` (comments only — actually uses scoped form), `app/Services/Order/RefundWithCounterEntryService.php:163`, `app/Services/Payments/SplitPaymentService.php:127`, `app/Services/Hardware/EscPosPrinterService.php:93,99`, `app/Services/Fiscal/FiscalSequenceService.php:88`, `app/Services/Loyalty/PosRedemptionService.php:195,205`, `app/Services/Delivery/DeliveryBoyCashSessionService.php` (6× lines 85,140,200,223,329,384), `app/Console/Commands/EnsureAdminLoginCommand.php` (multiple), `app/Console/Commands/EnsurePosOperatorLoginCommand.php:55`, `app/Console/Commands/EnsureChefLoginCommand.php:53`, `app/Console/Commands/FiscalArchiveCommand.php:337`, `app/Console/Commands/RetryFiscalAllocCommand.php:61`.

The pattern `Model::withoutGlobalScopes()` removes **all** scopes including SoftDeletingScope, so soft-deleted rows leak too. Most call sites are followed by explicit `where('branch_id', $branchId)`, so cross-branch is fine BUT soft-deleted rows are returned. The `CleanupStalePendingKioskOrders.php:25-30` comment block explicitly warns about this: *"`withoutGlobalScopes()` would ALSO drop SoftDeletingScope, risking..."* and that job uses the scoped `withoutGlobalScope(BranchScope::class)` form. The 17 unscoped sites do NOT all have this discipline. Per-site audit deferred (lane fenced) but the FAVOR-SAFE pattern should be `withoutGlobalScope(BranchScope::class)` whenever the caller wants ONLY branch-bypass.

**Severity**: P1 latent — V1 LOCAL low impact (single-branch DB), but any controller doing `withoutGlobalScopes()->find($id)` will return soft-deleted rows, breaking many service-level assumptions silently.

### P2-Z6-04 — Customer model exemption — Sanctum recursion risk vs cross-branch loyalty leak
`BranchScopeCoverageSentinelTest::EXEMPTED_MODELS[Customer] = 'Sanctum customer-token recursion risk'`. The recursion risk: `BranchScope::apply()` calls `Auth::check()` → Sanctum guard → resolves user via `Customer` query → if Customer has BranchScope, scope re-enters BranchScope::apply → infinite loop. The exemption is correct *for that reason*.

**But**: any Customer-listing surface that orders by branch or aggregates loyalty info across branches is now globally readable to any staff who has access to a Customer-listing controller. Did not enumerate Customer-facing controllers in this audit (lane fence Z6 ⊂ scope correctness, not Customer endpoint audit). Flagged for Z6 review.
**Severity**: P2 — needs follow-up audit of every `Customer::query()` in `app/Http/Controllers`.

### P2-Z6-05 — Sanctum `kiosk:order` ability NOT branch-bound at token level
**File**: `app/Http/Controllers/Auth/KioskMachineLoginController.php:98-102` mints `createToken('kiosk-token', ['kiosk:order'], now()->addMinutes(480))`. The token ability array contains only `'kiosk:order'`, no `branch:N` claim. The branch fence is enforced indirectly: BranchScope reads `Auth::user()->branch_id`, where `User` is the KioskMachine's linked User (KioskMachine.user_id → users.id, branch_id inherited from User row). If a kiosk token were stolen + the linked User row's `branch_id` were later mutated (admin tooling, console, factory), all future scope decisions would route to the new branch.

`KioskMachineLoginController` does NOT check the linked User's role. If somehow an Admin role user is linked to a KioskMachine row (seeder typo, manual SQL), the kiosk token would carry `branch_id=0` → BranchScope returns no filter → cross-branch read.
**Severity**: P2 mostly theoretical — needs a misconfiguration to exploit. Defense: post-mint assertion that linked User `branch_id > 0` and User is NOT Admin/Tenant Admin role.

### P3-Z6-06 — `LoyaltyController::history` raw `DB::table('orders')` query bypasses BranchScope (informational)
**File**: `app/Http/Controllers/Frontend/LoyaltyController.php:513-522`. Uses `DB::table('orders')->where('user_id', $user->id)->orWhere('loyalty_customer_code', $loyaltyCode)`. This is a customer-facing endpoint where the customer rightfully sees their own loyalty history across branches (the loyalty program IS cross-branch by business design). Not a bug. Flagged for completeness because it bypasses ORM scope and is the kind of pattern an auditor will challenge.
**Severity**: P3 informational. No change needed.

---

## C. Hard questions for owner (18 — frame hostile)

1. **PosLoyaltyController:41** — Owner, can you justify why this endpoint does `Order::withoutGlobalScopes()->find($orderId)` with ZERO branch check, while the sibling `PosOrderController::show:117-121` does the same fetch + immediate `abort_unless($order->branch_id === auth()->user()?->branch_id...)`? Is this a deliberate cross-branch loyalty feature or a regression? The LOCK_POS_LOYALTY_REDEEM_UI doc claims §6.1 "cashier permission enforced" — that's necessary, not sufficient. **Show me a test that proves a branch-5 cashier with `pos.redeem-loyalty` permission gets 403 on a branch-3 order.**
2. Same controller, line 41: the LOCK doc comment says "branch_id is already on the route model; if FormRequest authz is required the permission gate above already filtered". This is **incorrect** — the route model is just the orderId int (`/{order}` no `findOrFail`), and Spatie permission `pos.redeem-loyalty` is granted globally per user, not per branch. Confirm or refute.
3. PosRedemptionService:195 — `Order::withoutGlobalScopes()->where('id', $order->id)->update(...)` runs INSIDE a DB::transaction. If the order is from a foreign branch, this update runs with no further gate. Defense in depth?
4. 17× `withoutGlobalScopes()` (plural) call sites — what's the FoodKing policy: always prefer `withoutGlobalScope(BranchScope::class)` (singular) so SoftDeletingScope stays applied? The CleanupStalePendingKioskOrders comment block:25-30 documents exactly this trap. Adopt as a sentinel?
5. Customer model exempt — when did Q1 2026 last audit confirm no Customer endpoint leaks cross-branch loyalty totals? List the endpoints that read Customer cross-branch.
6. Kiosk token `kiosk:order` ability — is there any production constraint preventing an Admin-role User from being linked to a KioskMachine row? `EnsureKioskMachineCommand` and the KioskMachine seeder?
7. KioskMachineLoginController:73 — `User::query()->find($kioskMachine->user_id)` runs UNDER BranchScope (which has no auth yet, so it skips). What stops a future refactor from adding an auth scope here and breaking pairing for cross-branch admin-linked kiosks?
8. The 10 BASELINE_V1_2026-05-18 exempted models (FrontendDiningTable…DomainEvent) — what's the V1.0.2 schedule? They have `branch_id` columns but no scope. Single-tenant V1 = harmless. V2 SaaS hard fail. Are any of them eager-loaded today via a hasMany on a BranchScoped model?
9. ItemWizardProfile uses WizardProfileBranchScope (nullable). When admin creates a global profile (branch_id_scope=NULL), what guarantees it's not abused as a "publish to all branches" backdoor by a branch-manager who got `permission:settings` later?
10. FormRequestAuthzDriftSentinelTest baseline at 69 `return true;` — is there a published V1.0.2 plan to chip-away in waves? Which 8 critical have already been refactored per CLAUDE.md §9 mention?
11. `DefaultAccessService::storeOrUpdate:47-51` correctly clamps non-admin branch_id writes. But the row is queried as `firstOrNew(['user_id', 'name'])` — what stops admin (branch_id=0) from POSTing `branch_id=0` for a non-admin user's row via the userId routing? (Verified: it uses `Auth::id()`, so only-self-write. Safe.)
12. `User::query()->find($kioskMachine->user_id)` at KioskMachineLoginController.php:72,93 — User is BranchScope-exempt (recursion guard). Are we sure that's the ONLY surface where User is queried without an explicit branch filter in a write-path?
13. WebhookEvent (`app/Models/WebhookEvent.php:43-45` comment) — "intentionally global (no BranchScope)". The dedup is keyed on `(provider, webhook_id)` UNIQUE — but does Stripe send the SAME `webhook_id` for events on two different branches' Stripe accounts (multi-account setup)? In V1 Le Cayenne single account this is moot, but the doc should say so.
14. `EscPosPrinterService::openDrawer:93,99` — invalid_branch returns early; printer query `where('branch_id', $branchId)`. Caller responsibility to pass right branchId. Where is the call site (POS controller) verified?
15. Refund flow (Z8 owns) — `RefundWithCounterEntryService:163` `OrderPayment::withoutGlobalScopes()->where('order_id', $parent->id)`. The `branch_id` on the mirror payment row at line 177 is `$branchId` — sourced where? Verify upstream caller (Z8 lane, but Z6 cares).
16. FiscalSequenceService:88 — `Order::withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->max('fiscal_sequence_no')` — the explicit branch filter is correct. But `lockForUpdate` on SQLite is a no-op. Production MySQL uses GAP locking around index. Sentinel for that?
17. ZReportService:337 — `Order::withoutGlobalScope(BranchScope::class)->withTrashed()->where('branch_id', $branchId)`. Correct + includes soft-deleted (P0-FIX-1/2 NF525 continuity). Sentinel locking the `withTrashed()` requirement?
18. Did anyone run `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest` on HEAD `1e7c65ecc`? The doc says **20 models**, grep agrees. If a future PR adds `addGlobalScope(new BranchScope` on `Branch.php` (self-ref) that test would still pass, but business logic would loop. Sentinel does count, not target safety.

---

## D. Sync invariants verified GREEN

**21 models with branch isolation enforced** (HEAD 1e7c65ecc):

`addGlobalScope(new BranchScope)` (20):
CashDrawerSession, CashMovement, DeliveryBoyCashMovement, DeliveryBoyCashSession, DiningTable, FrontendOrder, ItemBranchAvailability, KioskMachine, Order, OrderItem, OrderPayment, OrderQuote, PaymentTerminal, PendingPaymentConfirmation, PosParkedOrder, Printer, PushNotification, StockLevel, StockMovement, User.

`addGlobalScope(new WizardProfileBranchScope)` (1):
ItemWizardProfile (nullable: NULL=global publish, INT=branch-bound).

**Exempted (documented)**: Branch (self-ref), Customer (Sanctum recursion). **V1.0.2 BASELINE exempted (10)**: FrontendDiningTable, ZReport, AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo, UpsellRule, ActionLog, DomainEvent.

**Drift protections active**: `BranchScopeCoverageSentinelTest` (Finder walk), `ClaudeMdBranchScopeCountSentinelTest` (WJ-7 doc-coherence), `F010BranchScopeQueueContextSentinelTest` (queue worker bypass lock), `FormRequestAuthzDriftSentinelTest` (69 baseline lock).

**Admin bypass logic verified**: `BranchScope.php:33-36` admin (branch_id=0) → no filter; staff → `WHERE branch_id = $userBranch`. `DashboardService.php:43-54` admin role → no filter, else explicit `where('branch_id', $branchId)`. `DefaultAccessService.php:47-51` non-admin branch_id write clamped to user's own branch. `DeliveryBoyCashSessionController:144-149` explicit cross-branch 403. `PosOrderController::show:117-121` explicit `abort_unless` post-fetch. `PaymentReconcileController:151-160` explicit branch echo + reject log.

**Order::restore() blocked** at `app/Models/Order.php:108-116` (RuntimeException) — no soft-delete bypass via restore.

---

## E. Out-of-scope or unverifiable

- **Z1 stock**: Did not audit `StockLevel`/`StockMovement` query call sites. Both DECLARE BranchScope. Sufficient for Z6.
- **Z2 order lifecycle / Z3 sync / Z4 pricing / Z5 fiscal / Z7 idempotency / Z8 refund**: lane-fenced. Z6 trusts they enforce their own invariants.
- **Customer endpoint enumeration**: P2-Z6-04 flagged but not exhaustively enumerated this session.
- **The 69 FormRequests with `return true;`** baseline (CLAUDE.md §9) — did not enumerate; baseline-lock sentinel already in place.
- **Raw SQL via `DB::statement`/`DB::raw`** — `grep -rln "DB::statement\|DB::raw" app/Http/Controllers` returned no obvious branch-bypass write paths in controllers. Did not scan services exhaustively (lane fence).
- **MultiTenantModelTrait** (`app/Traits/MultiTenantModelTrait.php`) used by User — did not read; structural assumption is it does NOT re-apply BranchScope (would recurse).
- **Cross-branch report intent at SyncOverviewController:178-202** flagged as documented admin pattern, did not deep-audit (Z3 lane).

---

## F. RED verdict

**Score**: **7.0 / 10** for V1 LOCAL Le Cayenne shippability of the BranchScope multi-tenant zone.

**Top 3 risks**:
1. **P0-Z6-01 PosLoyaltyController cross-branch loyalty redeem** — financial-mutation + audit-trail dirty. New endpoint introduced TODAY (2026-05-19, LOCK_POS_LOYALTY_REDEEM_UI) bypasses the pattern that PosOrderController has correctly enforced for months. ~3-line heal, no schema change, no frozen-zone touch.
2. **P1-Z6-03 `withoutGlobalScopes()` (plural) 17× sites** — chronic SoftDeletingScope kill risk. Each site needs audit + ideally migration to singular `withoutGlobalScope(BranchScope::class)`. V1 LOCAL impact low (single tenant + Order::restore() blocked) but a code-quality/safety drift.
3. **P1-Z6-02 FormRequestAuthzDriftSentinelTest 69 baseline** — global authz posture, not a single bug. V1.0.2 chip-away backlog. Not a V1 blocker, but a Z6 hygiene flag.

**Shippable V1?**  **GO-CONDITIONAL.** Heal P0-Z6-01 inline (3-line patch in `PosLoyaltyController::redeem` between line 41 and `try {` block at 50). Without it, V1 ships with a fresh-introduced cross-branch financial-mutation surface — owner exposure on his own Le Cayenne is null (one branch), but the regression discipline is broken and the code goes into production *worse* than what was there yesterday. **Heal first, then GO.**

**Cross-validation cue for adversarial team**: ask Z2 (order-lifecycle) and Z8 (refund) RED agents whether they independently flagged PosLoyaltyController:41 — if at least one corroborates, the P0 is hard-confirmed; if neither flags it, owner gets to decide whether the loyalty cross-branch is a "feature" (which the LOCK doc doesn't claim).
