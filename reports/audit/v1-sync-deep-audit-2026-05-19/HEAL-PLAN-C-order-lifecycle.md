# HEAL-PLAN-C — Order Lifecycle Foundation (Cluster C)
**Date**: 2026-05-19 · **Mode**: PLAN-ONLY (no code mutations) · **Branch**: `v1-0-1-hardening-2026-05-17`
**Source findings**: `RED-Z2-order-lifecycle.md` §B P1-listener-idempotency + P1-DispatchableAfterCommit + cross-ref `RED-Z1-stock-cascade.md`
**Schema note**: No prior HEAL-PLAN-A existed in `reports/audit/v1-sync-deep-audit-2026-05-19/`. Sections below designed in-place, not mirrored.

---

## 1. SCOPE STATEMENT

Two Z2 P1 findings, both latent (need duplicate dispatch OR bad refactor to bite). Owner mandate: conservative, no fancy features, frozen §7 + NF525 §8 strict.

- **C.1** — Sibling-listener idempotency on `OrderCreated` cascade (3 listeners audited).
- **C.2** — `DispatchableAfterCommit` dead-code on 5 order-creation sites.

Decision summary (justified §6):
- **C.1**: HEAL V1 — narrow scope to the ONE non-idempotent listener (`DecrementItemAvailabilityOnOrder`). Add cache-keyed guard + sentinel. Drop stock listener from heal scope (already idempotent via `stock_movements.idempotency_key`). FCM = defer V1.0.2.
- **C.2**: DEFER V1.0.2 + add placement sentinel. Lock current state so a future "tidy" refactor can't silently flip semantics.

---

## 2. INVESTIGATION FINDINGS (verified this session, not speculative)

### 2.1 Listener-by-listener idempotency verdict (from primary code reads)

| Listener | Idempotent today? | Evidence |
|---|---|---|
| `DecrementStockOnOrderCreated` | **YES** | `app/Services/Stock/StockService.php:102` short-circuits via `StockMovement::where('idempotency_key',$movementKey)->exists()`. `$movementKey` derived from `(reason='order_created', order_class, order_id, stockable_type, stockable_id)` at line 97 (`movementKey()`). Re-fire = noop. Z1 RED §D triple-defense confirms. |
| `DecrementItemAvailabilityOnOrder` | **NO** | `app/Services/Menu/AvailabilityService.php:296-315` does raw SQL `daily_consumed_qty + {$qty}` UPDATE filtered only by `whereNotNull('max_daily_qty')`. ZERO per-order idempotency key. Re-fire = double-count toward daily quota → premature 86 flip (`>= max_daily_qty` predicate at l.325). |
| `SendFcmOnOrderCreated` | N/A (non-destructive) | Duplicate push = annoying, not corrupting. Defer V1.0.2 per task framing. |

### 2.2 Is the `max_daily_qty` quota feature reachable in V1?

`grep max_daily_qty`:
- `app/Models/ItemBranchAvailability.php:19` (fillable)
- `app/Http/Controllers/Admin/AvailabilityController.php:116,131-142` — admin UI accepts + persists
- `app/Services/Menu/AvailabilityService.php:116-133` — `toggle()` sets/clears it
- `app/Services/Catalog/CatalogWarningService.php:128-129` — UI dashboard reads daily quota %
- `app/Console/Commands/ResetStaleDailyQuotaCommand.php:19,49` — daily reset cron

Verdict: feature IS live, UI surface present, daily reset cron scheduled. V1 Le Cayenne can use it. The double-count bug is reachable — heal C.1 must touch code, not be sentinel-only.

### 2.3 Cross-listener Throwable isolation

Both `DecrementStockOnOrderCreated` (lines 32-62) and `DecrementItemAvailabilityOnOrder` (lines 33-67) post-WG-2 wrap their bodies in `try/catch Throwable` + log + `event(StockDecrementFailedEvent)`. Sibling listeners survive a throw. This means a NEW try/catch wrapper around the cache check is safe — it cannot break the rest of the cascade.

### 2.4 Five `OrderCreated::dispatch` sites verified OUTSIDE `DB::transaction({...})`

| File | Line | Outside `});`? |
|---|---|---|
| `OrderService.php` | 572 (web/app POST-PAY path) | YES — closure ends line 561, dispatch line 572 |
| `OrderService.php` | 1075 (POS cash close path) | YES — closure ends line 1066, dispatch line 1075 |
| `OrderService.php` | 1385 (dine-in QR path) | YES — closure ends line 1377, dispatch line 1385 |
| `FrontendOrderService.php` | 604-605 (kiosk paid path, via `dispatchNewOrderSignals`) | YES — closure ends ~line 594, helper call line 605 |
| `FrontendOrderService.php` | 1226 (helper `dispatchNewOrderSignals`) | YES — called from POST-commit sites |

`DispatchableAfterCommit.php:31-39` confirmed: `transactionLevel()===0` falls through to `event(new static(...))` at line 41 — immediate dispatch, guard inactive.

### 2.5 WIP check

`git status`: 1 mod PHP `tests/Feature/Outbox/OutboxReplayAuditTest.php` (unrelated — Wave 3 SYNC-ADV3-04 batch-continuity tests). No sentinel name collision. Heal plan is free of WIP interference.

---

## 3. C.1 HEAL — `DecrementItemAvailabilityOnOrder` per-order guard

### 3.1 Files touched (scope-minimal)

| File | Lines | Change kind |
|---|---|---|
| `app/Services/Menu/AvailabilityService.php` | 285-342 (`decrementForOrder`) | Add early-return cache guard keyed on `(branch_id, order_id, order_class)` |
| `tests/Feature/AfterCommitDispatchTest.php` OR new `tests/Feature/Sync/AvailabilityIdempotencySentinelTest.php` | new test method | Sentinel locks the guard's presence + dispatch-once invariant on `OrderCreated` |

### 3.2 Before / After sketch (PLAN-ONLY, illustrative)

**Current** (`AvailabilityService.php:285-342`):
```php
public function decrementForOrder(Model $order): void
{
    $branchId = (int) $order->branch_id;
    $today = Carbon::today(config('app.timezone'))->toDateString();
    foreach ($order->orderItems as $line) {
        // ... daily_consumed_qty + {$qty} UPDATE ...
    }
}
```

**After (heal)**:
```php
public function decrementForOrder(Model $order): void
{
    $branchId = (int) $order->branch_id;
    if ($branchId <= 0) return; // mirror StockService:67 invariant

    // [HEAL-C.1] Per-order idempotency guard. OrderCreated may fire
    // twice (queue replay, listener resurrection, bad refactor). Stock
    // listener is idempotent via stock_movements.idempotency_key; this
    // listener uses raw UPDATEs with no per-order key. Cache::add() is
    // atomic SETNX-equivalent → second call short-circuits.
    $guardKey = sprintf(
        'availability:decremented:%s:%d:%d',
        $order::class === \App\Models\FrontendOrder::class ? 'fe' : 'o',
        $branchId,
        (int) $order->id
    );
    if (! Cache::add($guardKey, 1, now()->addHours(24))) {
        return; // already decremented this order
    }

    $today = Carbon::today(config('app.timezone'))->toDateString();
    foreach ($order->orderItems as $line) { /* unchanged */ }
}
```

### 3.3 Why `Cache::add` (SETNX) and not a marker column?

- **No migration** — V1 Le Cayenne LOCAL is mid-stabilization. Schema-touch costs more than the guard's value.
- **Atomic** — `Cache::add` on Redis/array driver is SETNX semantics: first writer wins, second sees `false`. Race-safe across queue workers.
- **24h TTL** — beyond the queue retry horizon (`tries=6, backoff=[1,5,15,60,300]` ≈ 6.4min) by 200×. Plenty of margin.
- **Cold-cache failure mode** — if Redis flushes mid-day, the guard becomes a no-op for unflagged orders. RED-dispute §7 addresses: V1 single-resto + 24h TTL means flush during business day = exceedingly rare, and observable via quota anomaly. NOT fiscal — `stock_movements` (NF525-adjacent) remains the SSOT for stock truth; `daily_consumed_qty` is UX-quota only.

### 3.4 Why NOT touch `DecrementStockOnOrderCreated`

Already idempotent at the StockService layer (`StockService.php:102`). Adding a listener-level guard would be redundant defense-in-depth — and we've been bitten by redundant guards drifting (see Z1 §C-3 owner question on "dead weight"). Keep the surface minimal.

### 3.5 Sentinel test (anti-regression)

New `tests/Feature/Sync/AvailabilityIdempotencySentinelTest.php`:

1. `test_decrement_for_order_is_idempotent_on_duplicate_dispatch`:
   - Seed Item + ItemBranchAvailability(`max_daily_qty=10, daily_consumed_qty=0`).
   - Create Order with 1 OrderItem qty=2.
   - Call `AvailabilityService::decrementForOrder($order)` TWICE.
   - Assert `daily_consumed_qty === 2` (not 4).
2. `test_availability_service_decrement_uses_cache_add_guard`:
   - Reflection / source-grep: assert `AvailabilityService::decrementForOrder` body contains `Cache::add(` + the guard key prefix `availability:decremented:`. Locks the mechanism.

Why source-grep + behavioral test pair: behavioral catches the bug; source-grep catches a "clever" refactor that removes the guard but still passes the behavioral test by accident.

### 3.6 Acceptance criteria

- `vendor/bin/phpunit --filter AvailabilityIdempotencySentinelTest` → green.
- `vendor/bin/phpunit --filter AfterCommitDispatchTest` → still green (regression-safety on related sentinels).
- `vendor/bin/phpunit --filter F0` stock+availability test suite → green (no regression).
- `git diff --stat`: 1 file PHP service + 1 file new test. Zero frozen-zone touch. Zero NF525 touch. Zero migration.

### 3.7 Rollback

Single revert commit. Cache key has no persistence — TTL expires within 24h. No data correction needed.

---

## 4. C.2 DECISION — DEFER V1.0.2 + placement sentinel

### 4.1 Why defer the code change

- **5 hot-path sites** in OrderService + FrontendOrderService. OrderService is heavily-touched (POS cash close, web POST-PAY, dine-in QR). Regression risk MEDIUM per RED §B.
- **DomainEvent UNIQUE absorbs the broadcast row** (migration `2026_05_09_180000:40`). Z1 stock listener idempotent; C.1 heal closes the availability listener. Once C.1 lands, the only surviving non-idempotent side-effect is FCM (annoying, not corrupting).
- **The advertised guard ("dropped on rollback")** is currently inert. The current pattern (dispatch AFTER `});` inside outer try/catch) is also correct — it just achieves the same goal via control flow instead of via the trait. NOT a correctness bug today; latent semantic debt.
- **Conservative V1 stance** per owner mandate: don't move hot-path code if a cheaper sentinel locks the current state.

### 4.2 Placement sentinel — what it locks

New `tests/Feature/Sync/OrderCreatedDispatchPlacementSentinelTest.php`:

For each of the 5 sites, source-grep:

1. The `OrderCreated::dispatch(` (or via `dispatchNewOrderSignals(`) call appears OUTSIDE the `DB::transaction(function ... { ... });` closure of the surrounding method.
2. Encoded by: extract the file content, locate the closing `});` of the relevant transaction block, locate the dispatch call. Assert dispatch offset > closing offset.

Mechanism per site:
- `OrderService.php` — 3 methods (`webOrderStore` ~l.500-580, `posOrderStore` ~l.1000-1080, `tableOrderStore` ~l.1320-1390). For each, locate the method's signature, then the FIRST `});` after the `DB::transaction` opening, then the `OrderCreated::dispatch` invocation. Assert ordering.
- `FrontendOrderService.php` — 1 explicit invocation l.1226 inside helper `dispatchNewOrderSignals`. Helper has NO transaction — so the placement test for FrontendOrderService asserts the CALLERS of `dispatchNewOrderSignals` (the kiosk paid path l.604-605 + the deferred-confirmation path) are outside their respective `});`.

### 4.3 PHPDoc on `OrderCreated.php`

Append note to class docblock (zero functional change):
```
* [HEAL-C.2 DEFER 2026-05-19] All current dispatch sites fire OUTSIDE
* DB::transaction({...}). The DispatchableAfterCommit guard at
* DispatchableAfterCommit.php:33 (transactionLevel>0 → afterCommit) is
* therefore INERT on the hot path; correctness comes from the outer
* control-flow pattern (dispatch after `});` inside outer try/catch).
* If a future refactor moves dispatch inside the closure, the guard
* re-engages — semantically equivalent, but the
* OrderCreatedDispatchPlacementSentinelTest will fail-loud so the change
* is conscious, not silent. V1.0.2 backlog: pick one canonical pattern.
```

### 4.4 RED self-dispute — "Isn't a sentinel locking the WRONG state codifying a bug?"

Counter: it makes ambient state VISIBLE. Today the gap is documented in a comment in `OrderCreated.php:14-17` only — easy to overlook. A failing sentinel is a hard stop. The V1.0.2 hardening pass can flip code + sentinel together (move dispatches INSIDE closure, swap the sentinel to assert inside). Silent semantic regression on a hot path is strictly worse than visible latent debt.

### 4.5 Acceptance criteria

- `OrderCreatedDispatchPlacementSentinelTest` → green at current state.
- PHPDoc on `OrderCreated.php` updated.
- Zero code-path change. Zero behavior change. Zero migration.

### 4.6 Rollback

Single revert commit (sentinel + PHPDoc only).

---

## 5. FROZEN §7 + NF525 §8 ATTESTATION

- **Frozen §7**: `AvailabilityService.php` is NOT in §7 list. `OrderCreated.php` is NOT in §7 list. `OrderService.php` / `FrontendOrderService.php` are NOT in §7 list (POS Vanilla JS wizard at `public/js/pos-wizard.js` IS — untouched here). Zero §7 lines.
- **NF525 §8**: zero touch to `FiscalSequenceService`, `ZReportService`, `AuditLogService`, audit_log triggers, fiscal_sequence_no logic, composition_snapshot, HMAC chains. C.1 cache guard is keyed only on `(branch_id, order_id, order_class)` — does not influence fiscal allocation, chain hash, or pricing.

---

## 6. V1 vs V1.0.2 DECISION JUSTIFICATION

### Heal V1 (C.1 availability listener guard)
- **Severity**: latent until duplicate dispatch occurs; double-count flips item to `unavailable_reason='out_of_stock'` prematurely → kiosk hides item, kitchen confused, customer 409.
- **Trigger**: `tries=6` queue retry on transient broadcast failure; future refactor moving listener to `ShouldQueue`; bad merge resurrecting an old code path.
- **Cost**: ~15 LOC + 1 sentinel test. No migration. No frozen-zone touch.
- **Benefit**: closes the only V1-reachable correctness hole in the cascade.

### Defer C.2 (move dispatches inside closure)
- **Severity**: zero today (control-flow correctness identical to trait semantics).
- **Latent risk**: bad refactor moving dispatch inside `});` would re-engage trait; net behavior identical, no regression.
- **Cost of healing now**: 5 site edits in hot path × OrderService heavily-touched. MEDIUM regression risk.
- **Cost of deferring**: 1 sentinel + PHPDoc. Sentinel fail-loud on accidental flip.
- **Verdict**: defer code change, lock state via sentinel + doc.

### Defer C.1 FCM listener
- **Severity**: duplicate push notification = annoying, not destructive. No data corruption. No frozen-zone or NF525 surface.
- **Cost of healing now**: per-`SendFcmNotificationJob` idempotency key adds queue surface; out of V1 scope.
- **Verdict**: V1.0.2 (or accept as ambient).

---

## 7. RED SELF-DISPUTE (labeled section)

**Q1**: "Sentinel locks the WRONG state (outside-closure) — isn't that codifying a bug?"
A: Codifies VISIBILITY. Comment-driven trust is invisible to PR review; failing sentinel is not. V1.0.2 can flip code + sentinel together.

**Q2**: "Cache::add fails on cold cache — guard is not fiscal-grade."
A: Correct, and acceptable. `daily_consumed_qty` is a UX-quota (auto-86 flip), not a fiscal counter. `stock_movements` is NF525-adjacent and remains idempotent via DB UNIQUE. Cold-cache during business day on a V1 LOCAL single-resto is exceedingly rare; the failure mode (single double-count on one order until 24h TTL) is observable (quota dashboard) and bounded (one order, not cascading).

**Q3**: "Why not heal C.2 alongside C.1 — both touch the same hot path?"
A: They don't. C.1 touches `AvailabilityService::decrementForOrder` (called via listener, post-commit). C.2 would touch 5 different sites in 2 different services (OrderService + FrontendOrderService). Different blast radius, different review surface. Bundling = inflate scope. Owner mandate: conservative.

**Q4**: "What if a third sibling listener is added in V1.0.2 and it's also non-idempotent?"
A: That's a future concern. Z2 P1 secondary fix proposal — `IdempotentDomainEvent` interface consulted by dispatcher before fan-out — is the cleaner long-term answer. Out of scope V1.

**Q5**: "C.2 sentinel uses source-grep — fragile to whitespace / refactor."
A: Acceptable. The sentinel asserts a placement invariant, and any refactor of `OrderService` will visit those lines anyway. False positive on a cosmetic edit triggers a 5-second eyeball check — cheap. False negative (semantic flip going undetected) is what we're paying to prevent.

**Q6**: "Quota feature actually used at Le Cayenne, or theoretical?"
A: `max_daily_qty` migration applied, admin UI provisioned (`AvailabilityController:116`), warning dashboard reads it (`CatalogWarningService:128`), daily-reset cron registered (`ResetStaleDailyQuotaCommand`). Whether owner has set non-null values on items is operational — but the column is live and the code path executes on every order. Heal-once-it-can-bite is cheaper than heal-after-it-bit.

**Q7**: "Why a NEW sentinel test file vs extending `AfterCommitDispatchTest`?"
A: `AfterCommitDispatchTest` asserts the trait is USED. The new sentinel asserts dispatch PLACEMENT is OUTSIDE — semantically opposite. Mixing them in one file would confuse future readers about what's enforced. One file per invariant.

**Q8**: "What about `OrderStatusChanged` cascade — same risk?"
A: Out of scope (Cluster C is OrderCreated only). `OrderStatusChanged` cascade has its own idempotency story (correlation_id-keyed dedupe — `PersistOrderStatusChangedToOutbox.php:27-33`). Z2 RED §B did not flag a P1 there.

---

## 8. EXECUTION ORDER (when heal greenlit — NOT in this PLAN-ONLY pass)

1. Land C.2 sentinel + PHPDoc FIRST. Establishes baseline lock.
2. Run full smoke (`AfterCommitDispatchTest` + new placement sentinel) → green.
3. Land C.1 cache guard + sentinel.
4. Run `AvailabilityIdempotencySentinelTest` + stock/availability suite → green.
5. Update `PROJECT_BRAIN.md` §3 LAST DONE with HEAL-C reference.

NO frozen-zone change. NO NF525 change. NO migration. NO cloud action.

---

## 9. ATTESTATION

- This plan: PLAN-ONLY, zero code edits.
- File reads this session: `RED-Z2-order-lifecycle.md`, `RED-Z1-stock-cascade.md`, `SYNTHESIS.md`, `EventServiceProvider.php`, `PersistOrderCreatedToOutbox.php`, `DecrementItemAvailabilityOnOrder.php`, `DecrementStockOnOrderCreated.php`, `SendFcmOnOrderCreated.php`, `StockService.php`, `DispatchableAfterCommit.php`, `OrderCreated.php`, `AvailabilityService.php`, `OrderService.php` (3 sites), `FrontendOrderService.php` (2 sites), `AfterCommitDispatchTest.php`.
- WIP check: `tests/Feature/Outbox/OutboxReplayAuditTest.php` modified (Wave 3 SYNC-ADV3-04) — no naming collision with proposed sentinels.
- Frozen §7 diff: 0 lines (read-only investigation).
- NF525 §8 diff: 0 lines.
- No `php artisan` or test execution this session.
