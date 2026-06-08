# W6 — Stock deep-validation VERDICT
**Date:** 2026-06-08 · GOAL_WIZARD_DYNAMIC §5 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push)
**Method:** empirical — code-path read + executed test suites (sqlite :memory:, `vendor/bin/phpunit`) + live DB state (`foodking_e2e` clone) + a new verdict-locking test. No assumptions carried from the prior workflow finding; the "swallowed post-commit rupture" claim was re-verified against the *current* code as the advisor required.

## VERDICT: V1-SOUND. No live oversell path. The "swallowed post-commit rupture" finding is DORMANT (not a live bug); the live anti-oversell gate is hard, multiply-enforced, and lock-protected.

---

## What is actually LIVE in V1 (empirical)
**Anti-oversell = manual-86 `is_available` flag, enforced PRE-COMMIT.**
- `AvailabilityService::assertItemsOrderableForBranch` (`app/Services/Menu/AvailabilityService.php:216`) rejects with **HTTP 422** (`lockForUpdate` by default) when an item is: missing, inactive (`status != ACTIVE`), catalog-`is_available=false`, or **per-branch 86'd** (`item_branch_availability.is_available=false`, with reason).
- It is called from the **FROZEN `PricingService` SSOT** (`PricingService.php:50,102`) — so **every** POS/kiosk/online order traverses it — **plus** `OrderService.php:446/880/1431` and `FrontendOrderService.php:339`. Choice-level rupture: `assertSelectionsOrderable` (`PricingService.php:546`). Multiply-redundant.

## What is DORMANT in V1 (empirical — `foodking_e2e` 2026-06-08)
- **`stock_levels` = 0 rows.** `StockService::mutateForOrder` does `if (! $level) continue;` (`StockService.php:52-53`) → the numeric decrement is a **no-op for every V1 item**. The `StockUnavailableException` (`:70-71`) **cannot fire** with no levels.
- **`max_daily_qty` = 0 items.** STOCK-3-01 daily-quota oversell at the cap boundary is **non-triggerable** (no item has a cap).

## The "swallowed post-commit rupture" finding — re-graded HONESTLY
`DecrementStockOnOrderCreated.php:14-31` is the **reworked WG-2/PK1-ARCH-01** version (its own comment flags the *old* re-throw rationale as "load-bearing-wrong"). Current behaviour: `OrderCreated` fires AFTER commit, so a `StockService` failure is **caught → structured `Log::error` → dispatches `StockDecrementFailedEvent` → returns** (does NOT re-throw). This is **deliberate and correct**: re-throwing post-commit would roll back nothing, skip the Outbox SSOT (breaking KDS/kiosk/POS sync), and 500 a paid order that already exists.
- **It is swallowed at the HTTP layer, but it is NOT silent** (log + ops event).
- **It is dormant in V1** anyway (no `stock_levels` → nothing throws).
- The real gate is the **pre-commit 422 assert** above, which fires *before* the order exists.

## Concurrency (Sub 3.3) — honest note
The strongest concurrency tests run single-process SQLite (`AvailabilityDecrementConcurrencyTest`, `StockConcurrentDecrementTest`). For **V1 single-box** this is adequate: `lockForUpdate` + `stock_movements.idempotency_key` UNIQUE provide in-process transaction correctness, and a single box has no true multi-worker stock contention. **Multi-instance (cloud/ALB) would require a MySQL multi-worker proof** — tracked as a V1.0.x cloud-prep item, NOT a V1 blocker.

## Evidence (executed)
- `tests/Feature/Stock` **68/68** (4 skipped) — append-only (`StockMovementsAppendOnlyTest`), idempotency (`StockMovementIdempotencyKeyUniqueTest`), idempotent release (`StockReleaseOnCancel/Refund`), branch isolation, after-commit dispatch, rupture→sync.
- `tests/Feature/Menu/Availability*` + `tests/Feature/Availability` **7/7**.
- **NEW** `tests/Feature/Stock/StockV1OversellGateValidationTest` **3/3** — branch-86 → 422, available → passes, inactive → 422 (regression-locks the live gate).

## G-STOCK-1 (owner decision: hard-vs-soft) — RE-FRAMED
The "soft pre-flight lag / swallowed decrement" concern applies only to **numeric/daily-cap stock, which is unconfigured in V1**. The live V1 gate (manual-86 pre-flight 422, lockForUpdate) is **hard**. ⇒ **No owner decision is needed for V1.** G-STOCK-1 becomes relevant only if/when the owner enables numeric stock or daily caps (a V1.0.x choice): at that point, decide whether to add a numeric/quota re-check inside the pre-flight assert (hard) or keep the observable post-commit isolation (soft).
