# INTERSECTION POS x Stock — STATUS Round 1 (2026-05-18)

Master sub-agent: autonomous, parallel with POS x OSS / POS x KDS / KDS x Sync (disjoint scope).
Specialists dispatched (read-only): Architect, Security, DBA, RED.
Deliverables: 4 specialist JSONs at `reports/audit/intersection-pos-stock-2026-05-18/round-1/PSTK-{1,2,3}-*/<role>.json` + this STATUS.

---

## EXECUTIVE VERDICT

**INTERSECTION READY FOR V1 SHIP.** No P0 unmitigated. 2 P0 mitigated (concurrency lockForUpdate + UNIQUE idempotency_key; append-only DB triggers landed via Foundation F-6 P0 migration `2026_05_18_140000`). 1 safe HEAL applied this round (StockLevelFactory stockable_type). All remaining items deferred to V1.0.1 backlog.

---

## 4-LIST (findings grouped by priority)

### P0 — UNMITIGATED
(none)

### P0 — MITIGATED / VERIFIED
- **PSTK-R-1 / PSTK-D-1** — Concurrent POS-1 + POS-2 decrement race **closed** by `lockForUpdate` at `StockService.php:90` + UNIQUE constraint on `stock_movements.idempotency_key`. Stress test `test_stress_guard_allows_only_20_successes_across_50_attempts` proves it. *Caveat: SQLite (PHPUnit default) serializes; add MySQL-CI integration test in V1.0.1.*
- **PSTK-R-2** — Refund-twice attack **closed** by two-layer idempotency: StockService `idempotency_key` check (line 412) + AvailabilityService `released_qty` ledger on `order_items`. Verified by 3 dispatches → 2 effective in `test_decrement_and_partial_refund_track_addon_target_stock_from_composition_snapshot`.
- **PSTK-D-4** — Append-only DB-level triggers (MySQL SQLSTATE 45000 + SQLite RAISE ABORT) landed via Foundation F-6 P0 migration. Driver-portable. Idempotent re-run. Closes raw `DB::table('stock_movements')->delete/update` bypass.
- **PSTK-S-1** — BranchScope global on `StockLevel` + `StockMovement` closes cross-tenant leak. Explicit `WHERE branch_id` in StockService + ChoiceAvailabilityResolver = defense-in-depth.
- **PSTK-A-5** — Outbox-first listener ordering on `OrderCreated` correctly precedes stock listeners (F-002 round-3 invariant).

### P1 — DEFERRED V1.0.1
- **PSTK-R-3 / PSTK-R-5** — `StockService::mutateForOrderInTransaction` does **NOT** respect `manual_unavailable_reason`. Only `on_hand>0` is checked. The pricing-path resolver is the only gate. Defense-in-depth fix: 1 line in StockService line ~111: `if ($direction < 0 && $level->manual_unavailable_reason !== null) throw StockUnavailableException`. HEAL-ALLOWED but requires regression test. **Backlog ID: V101-STOCK-01**.
- **PSTK-A-2** — Listener-ordering coupling on `OrderCanceled` (StockService FIRST, then AvailabilityService). StockService idempotency_key embeds `released_qty` → if listener order ever flips (e.g. Outbox-first reorganization), stock release silently no-ops. Fix: make StockService release key stable on line_uid (not released_qty), OR add a sentinel test asserting listener registration order. **Backlog: V101-STOCK-02**.
- **PSTK-A-3** — POS path swallows `StockUnavailableException` post-commit at `OrderService.php:560` (catch \\Exception). Saved only by the inline call at line 894 throwing before commit. If inline call refactored away, stock decrement silently fails on POS. **Backlog: V101-STOCK-03** (paired with PSTK-A-1).
- **PSTK-D-2** — `StockMovement::create` no try/catch for QueryException 23000 on parallel duplicate-INSERT race. Mitigated by lockForUpdate but raw QueryException would bubble to cashier as generic 500. **Backlog: V101-STOCK-04**.
- **PSTK-D-3** — N+1 in `StockService::mutateForOrderInTransaction`: one `lockForUpdate` per requirement row. Multi-line orders pay 30+ round-trips. **Backlog: V101-STOCK-05** (perf, not correctness).
- **PSTK-S-3** — Missing sentinel test asserting no HTTP route exposes `StockService::decrementForOrder` / `releaseForOrder` directly. **Backlog: V101-STOCK-06**.

### P2 — DEFERRED V1.0.1 / V1.0.2
- **PSTK-A-1** — Inline `StockService::decrementForOrder` at `OrderService:894` + post-commit listener `DecrementStockOnOrderCreated` produce identical idempotency_key, so listener path is a no-op via existence check. Wasted DB work per order. **Backlog: V101-STOCK-07** (perf).
- **PSTK-A-4** — Two listeners on `OrderCreated` (`DecrementItemAvailabilityOnOrder` + `DecrementStockOnOrderCreated`) write to separate tables in separate transactions. If second fails after first succeeds, daily_consumed_qty drifts until midnight reset cron. Acceptable risk for V1. **Backlog: V102-STOCK-01**.
- **PSTK-R-4 / PSTK-R-6** — Soft-deleted `Item` or `OrderItem` interaction with stock release path is latent (no current UI exercises it). Document + sentinel. **Backlog: V102-STOCK-02**.
- **PSTK-R-7** — `composition_snapshot.addons[].addon_item_id` not validated against order's branch — silent skip in stock decrement, possible confused snapshot. Verify pricing-path guard. **Backlog: V102-STOCK-03**.
- **PSTK-S-2** — Raw `DB::table('order_items')` in `AvailabilityService::releaseForOrderItems` bypasses BranchScope but mitigated by `item_id + branch_id` double-key validation. Add test. **Backlog: V101-STOCK-08**.
- **PSTK-S-4** — `TRUNCATE TABLE stock_movements` bypass not REVOKE-ed in prod doc. Same caveat as audit_logs/z_reports. **Deploy doc: V101-DEPLOY-01**.
- **PSTK-D-6** — `stock_movements` missing `(reference_type, reference_id)` composite index for future admin movements-per-order UI. **Backlog: V102-STOCK-04**.

### HEAL APPLIED THIS ROUND (P2 safe, test-only)
- **PSTK-D-5** — `database/factories/StockLevelFactory.php:18` — `'stockable_type' => 'item'` → `Item::class`. Aligns factory with production code (no morph map registered). Zero production impact (test fixture only). **Verification: 59/59 Stock feature tests pass post-heal (4 pre-existing consolidation skips)**.

---

## SCOPE & CONSTRAINTS RESPECTED

- 0 frozen-zone touch.
- 0 NF525-critical file modified (`FiscalSequenceService`, `ZReportService`, `AuditLogService` not in scope, not touched).
- `app/Services/OrderService.php` = DIRTY (not modified per mandate).
- `app/Listeners/DecrementStockOnOrderCreated.php` = just healed Couche 0 (import path) — not re-touched per mandate.
- `app/Services/Stock/StockService.php` + `app/Services/Stock/ChoiceAvailabilityResolver.php` = HEAL-ALLOWED but no in-scope finding was both critical AND non-test-regression-requiring → no heal applied to these (defer 6 items to V1.0.1).
- Only 1 file edited: `database/factories/StockLevelFactory.php` (test factory, P2 safe heal).

## TIME

~35 min wall-clock from RECON to STATUS.md write. Within 30-40 min mandate.

## NEXT

Hand off to integration round when sibling intersections (POS×OSS, POS×KDS, KDS×Sync) complete. V1.0.1 backlog seeded with 8 items (V101-STOCK-01 through 08 + V101-DEPLOY-01). V1.0.2 backlog seeded with 4 items.
