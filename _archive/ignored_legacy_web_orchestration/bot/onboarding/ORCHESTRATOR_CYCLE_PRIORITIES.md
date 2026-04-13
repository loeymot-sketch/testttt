# FoodKing — Orchestrator Cycle Priorities

> Actionable priority queue for the Claude orchestrator.
> Updated: 2026-04-12 (post-audit).
> Review and re-rank after each completed cycle.

---

## Tier 1 — Inspect Next (blocks safe autonomous work)

These must be resolved before Claude can confidently approve plans on the affected surfaces.

### P1-01 — Fix doc/code status value mismatch

**What**: `BUSINESS_RULES.md`, `DATABASE_SCHEMA_CORE.md`, `.cursor/rules/safety.mdc` all use wrong enum integers (5/10/14/17 instead of 1/4/7/8/10/13/16/19/22). Missing `OUT_FOR_DELIVERY`, `CANCELED`, `REJECTED`, `RETURNED`.
**Why first**: Every plan that references status values risks using wrong numbers. This is the single biggest false-approval vector.
**Cycle type**: `local-validation` — update docs, verify consistency.
**ORB ref**: ORB-025, ORB-037
**Blast radius**: docs only — zero code changes
**Human gate**: No

### P1-02 — Classify and fix `OrderService::changeStatus` dispatch ordering

**What**: Staff path (`$auth=false`) dispatches `Send*` events before `$order->save()` outside a transaction. Customer path (`$auth=true`) omits `OrderStatusChanged` entirely. Delivery boy path has same pre-save dispatch issue.
**Why urgent**: Phantom notifications on save failure (staff/delivery boy). Silent KDS/OSS desync on customer cancel (customer path).
**Cycle type**: `local-validation` — requires human answer on intent first (is pre-save dispatch intentional?), then targeted fix.
**ORB ref**: ORB-005, ORB-011, ORB-018
**Blast radius**: `app/Services/OrderService.php` — 3 methods
**Human gate**: **Yes** — human must classify intent before fix plan

### P1-03 — Write `ValidStatusTransition` exhaustive test

**What**: No dedicated unit test for the rule that guards the entire order lifecycle. 81 transition combinations (9×9) untested. Admin bypass, POS shortcuts, same-status edge case all unverified.
**Why urgent**: Any change to transition logic without this test is flying blind.
**Cycle type**: `local-validation` — pure test creation, no code change.
**ORB ref**: ORB-030
**Blast radius**: `tests/` only
**Human gate**: No

---

## Tier 2 — Fix Next (high-value improvements)

These improve system reliability and reduce orchestrator judgment risk.

### P2-01 — Verify production `.env` for `BROADCAST_DRIVER` and `QUEUE_CONNECTION`

**What**: Defaults are `null` (broadcast) and `sync` (queue). If production uses defaults, realtime is silently dead and FCM blocks HTTP requests.
**Why important**: P0 documented risk. The entire KDS/OSS/POS realtime layer depends on correct configuration.
**Cycle type**: `human-verification` — operator must check production `.env`.
**ORB ref**: ORB-013, ORB-014
**Blast radius**: Configuration only
**Human gate**: **Yes** — requires production access

### P2-02 — Fix kiosk auto-accept transaction gap

**What**: `FrontendOrderService::myOrderStore` commits the order, then performs auto-accept (PENDING→ACCEPT) in a separate save outside any transaction. Crash between commit and auto-accept leaves order stuck in PENDING.
**Why important**: Kiosk-heavy deployments need reliable auto-accept. PENDING orders never appear on KDS.
**Cycle type**: `local-validation` — wrap auto-accept in creation transaction or add recovery mechanism.
**ORB ref**: ORB-010
**Blast radius**: `app/Services/FrontendOrderService.php` — one method
**Human gate**: No

### P2-03 — Write `CouponService` tests and fix `start_date` gap

**What**: Zero test coverage on `CouponService`. `couponChecking()` does not validate `start_date`. `OrderCoupon.discount` stores combined coupon + loyalty amount.
**Why important**: Revenue impact — early redemption possible, accounting ambiguity.
**Cycle type**: `local-validation` — add `start_date` check + write test suite.
**ORB ref**: ORB-006, ORB-007, ORB-031
**Blast radius**: `app/Services/CouponService.php` + `tests/`
**Human gate**: No

### P2-04 — Fix placeholder and source-scanning tests

**What**: `BranchIsolationTest` → `assertTrue(true)`. `CouponSecurityTest` → partial placeholder. `FrontendOrderServiceTest` and `OrderServiceSecurityTest` read PHP source as text, don't execute services.
**Why important**: These create false confidence in CI. "All tests pass" means nothing when assertions are trivial.
**Cycle type**: `local-validation` — rewrite with real assertions.
**ORB ref**: ORB-034, ORB-035, ORB-036
**Blast radius**: `tests/` only
**Human gate**: No

### P2-05 — Fix `RefreshTokenController` token accumulation

**What**: New token minted on refresh without revoking old one. If no Sanctum expiration configured, tokens live forever.
**Why important**: Security risk — stolen tokens cannot be invalidated by refreshing.
**Cycle type**: `local-validation` — add old token revocation + verify Sanctum `expiration` config.
**ORB ref**: ORB-042
**Blast radius**: `app/Http/Controllers/Auth/RefreshTokenController.php` + `config/sanctum.php`
**Human gate**: No

---

## Tier 3 — Strengthen (harden architecture)

These improve long-term maintainability and reduce drift risk.

### P3-01 — Produce `Order` vs `FrontendOrder` diff matrix

**What**: Document all differences in `$fillable`, `$casts`, relationships, scopes. Decide if a shared trait is warranted.
**Cycle type**: `static-inspection`
**ORB ref**: ORB-001

### P3-02 — Audit `BranchScope` bypass patterns

**What**: Grep for direct queries on `OrderItem`, `OrderCoupon`, `Transaction`, `Item` outside relationship chains. Grep for `withoutGlobalScope` usage.
**Cycle type**: `static-inspection`
**ORB ref**: ORB-002

### P3-03 — Update `API_MAP.md` and `AUTHZ_MATRIX.md`

**What**: Fix OSS auth description (Sanctum + permission, not api-key only). Fix KDS filter description (ACCEPT + PREPARING + PREPARED). Fix kiosk "bloque nativement" claim.
**Cycle type**: `local-validation` — doc updates only
**ORB ref**: ORB-026, ORB-027

### P3-04 — Add `TableOrderController::tokenCreate` to permission middleware

**What**: Missing Spatie permission check — any authenticated user can call it.
**Cycle type**: `local-validation` — one-line fix + test
**ORB ref**: ORB-043

### P3-05 — Add logging to `FirebaseService` inner catch

**What**: Per-token FCM failures are completely invisible.
**Cycle type**: `local-validation` — add `Log::warning` in empty catch
**ORB ref**: ORB-016

---

## Tier 4 — Clean Up (low-risk debt)

### P4-01 — Remove stale routes to missing `destroy` methods
`OnlineOrderController@destroy`, `TableOrderController@destroy` — routes exist, methods don't.
**ORB ref**: ORB-020

### P4-02 — Remove dead import `AmountType` in `AppLibrary`
`app/Libraries/AppLibrary.php` imports nonexistent `App\Enums\AmountType`.
**ORB ref**: ORB-022

### P4-03 — Remove redundant `DB::rollBack()` in `OrderService` catch blocks
Redundant after `DB::transaction` closure, can break savepoint nesting.
**ORB ref**: ORB-024

### P4-04 — Remove dead import `Log` in `SendFcmOnOrderStatusChange`
Imported but never used.

### P4-05 — Regenerate `TEST_PLAN.md` from actual test inventory
Current plan says "OrderFlowTest à écrire" — it already exists. 30+ test files unlisted.
**ORB ref**: ORB-029

---

## Do Not Touch Yet

| Area | Reason | When to revisit |
|------|--------|-----------------|
| Payment gateways | Frozen zone — external integration | Only if payment flow bugs surface |
| `PushNotificationService` internals | Frozen zone | Only if push reliability becomes P0 |
| Admin analytics module | Frozen zone — low priority | Not in current scope |
| Delivery boy module | Frozen zone — low priority | Not in current scope |
| `ShouldBroadcastNow` → `ShouldBroadcast` migration | High blast radius, needs queue infrastructure verified first | After P2-01 confirms queue config |
| `Order`/`FrontendOrder` trait extraction | Needs P3-01 diff matrix first | After P3-01 complete |
| Graphify / Graphiti POC | Not needed yet — simple memory is sufficient | When cycle volume proves need |
| Multi-tenant SaaS evolution | Vision-level, not operational yet | When "Le Cayenne" is stable |

---

## Human Gates (explicit human approval required)

| ID | Decision | Why human must decide |
|----|----------|----------------------|
| HG-01 | Is pre-save notification dispatch in `OrderService::changeStatus` intentional? | Legacy pattern vs regression — changes behavior if fixed |
| HG-02 | Is `OrderService::changeStatus($auth=true)` reachable in production? | If yes, missing `OrderStatusChanged` is a silent KDS/OSS gap |
| HG-03 | Production `.env` broadcast/queue driver values | Requires server access |
| HG-04 | Any frozen zone modification | Architecture boundary |
| HG-05 | Sanctum token expiration policy | Security decision |

---

## Cycle Completion Checklist

After each cycle, verify:

- [ ] Were all affected `changeStatus` paths checked?
- [ ] Were both `Order` and `FrontendOrder` models checked if order-related?
- [ ] Were all 4 store methods checked if pricing-related?
- [ ] Were status values verified against `app/Enums/OrderStatus.php`?
- [ ] Were test assertions read (not just "tests pass")?
- [ ] Were event dispatch positions verified (before/after commit)?
- [ ] Were doc/code mismatches flagged for update?
- [ ] Was branch isolation impact assessed?
- [ ] Were frozen zones respected?
