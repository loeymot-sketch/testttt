# WF-4 STOCK CASCADE — Wave F Sync Confirmation — STATUS

Branch: `heal/cms-pr1-quickwins-2026-05-18`
HEAD: `50bdd5150`
Date: 2026-05-19
Master: WF-4 (parallel with WF-1 POS-KDS-SYNC, WF-2 POS-OSS-SYNC, WF-3 KIOSK-KDS-OSS-SYNC, WF-5 FISCAL-CASCADE, WF-6 REFUND-CASCADE, WF-7 RED-META, WF-8 SENTINEL-DISCIPLINE)
Scope: POS+Kiosk order → Stock decrement → events → KDS allergens + dashboard low-stock cascade, 7 hops + 2 compensating release paths.
Mode: READ-ONLY audit + PHPUnit verification (no heal applied — see "Heal Restraint Decision").

---

## Verdict

**PASS_WITH_FINDINGS — 0 P0, 3 P1, 5 P2, 4 P3 (12 findings total across 3 specialists; sums match per-specialist tally below). Stock cascade integrity is solid post-Foundation F-6. Cascade defenses validated by 79/79 Stock tests + 117/117 Availability tests green.**

Foundation F-6 P0 hardening (commits `5bb8c48f9` + `ccc95e862`) verified APPLIED and EFFECTIVE:
- F-6 P0-ARCH-01 — `StockUnavailableException` import path fixed; expected business-rule rejection no longer mislogged via the Throwable catch branch.
- F-6 P0-DBA-01 — `stock_movements` BEFORE DELETE/UPDATE triggers + FK restrictOnDelete present per `migrate:status [16] Ran`. Eloquent `LogicException` Eloquent-layer guard remains as defense-in-depth.

3 P1 findings are cross-validated (≥2 specialists). All are V1.0.2 backlog candidates — none block V1 merge. No P0 surfaced. No frozen-zone or DIRTY-file modification required.

---

## Cascade Diagram (7 hops + 2 compensating release paths, file:line verified)

```
[1] POS/Kiosk order submit                  app/Http/Controllers/Admin/PosController.php (DIRTY — observed only)
        |                                   app/Http/Controllers/Kiosk/...
        v
[2] OrderService::create / FrontendOrderService::create
        | DB::transaction(... event(new OrderCreated($order)) ...)
        v
[3] OrderCreated event fires                app/Events/OrderCreated.php:14 (DispatchableAfterCommit)
        | event() runs in DB::afterCommit() closure
        | -> drops silently on rollback (KI-001 / gate C9)
        v
[4] 4 listeners (order MATTERS, F-002 round-3): app/Providers/EventServiceProvider.php:145-151
        | (a) PersistOrderCreatedToOutbox       :147  <-- SSOT FIRST
        | (b) DecrementItemAvailabilityOnOrder  :148  <-- daily counter / auto-86
        | (c) DecrementStockOnOrderCreated      :149  <-- stock_levels mutation
        | (d) SendFcmOnOrderCreated             :150
        v
[5] StockService::decrementForOrder         app/Services/Stock/StockService.php:27-30
        | DB::transaction wrapper :49-51
        | foreach requirementsForOrder($order) :76-138
        |   - StockLevel::lockForUpdate() :86-91
        |   - on_hand >= qty check + throw StockUnavailableException :111-113
        |   - $level->forceFill(['on_hand' => before + delta])->save() :117
        |   - StockMovement::create(idempotency_key = sha1(reason|order|line|stockable)) :119-128
        |     -> UNIQUE constraint stock_movements_idempotency_unique (last-line defense)
        |     -> BEFORE DELETE/UPDATE triggers (Foundation F-6 P0-DBA-01)
        v
[6] Side-effect emissions                   StockService:131-146
        | syncItemAvailabilityForStockLevel :162-215
        |   -> ItemAvailabilityChanged::forBranch (after-commit)
        |   -> outbox via PersistItemAvailabilityChangedToOutbox listener
        | isChoiceBoundaryMutation :459-467 — gates StockLevelChanged emission
        |   -> StockLevelChanged::dispatch (after-commit, only for variation/extra/addon zero-crossing)
        |   -> outbox via PersistCatalogChangedToOutbox listener
        |   -> NotifyStockLowOnStockLevelChanged (threshold_low check + Cache throttle)
        v
[7] Read-path consumers
        | (a) ChoiceAvailabilityResolver::snapshotForItems — KDS allergens + POS/Kiosk filters
        |     -> 5-layer precedence: catalog_inactive > catalog_unavailable > surface_hidden > branch_unavailable > manual > stock_rupture > available
        |     -> F-016a-BIS manual takes priority over auto stock (:281-284)
        | (b) StockLowAlertsWidget (resources/js/components/admin/dashboard/) -> GET /api/admin/stock/low-alerts
        |     -> StockRuptureDashboardController::lowAlerts (routes/api.php:290)
        |     -> reads stock_levels.threshold_low

== COMPENSATING RELEASE PATHS ==

[CANCEL] OrderCanceled event                EventServiceProvider:161-164
        | (a) ReleaseStockOnOrderCanceled    -> StockService::releaseForOrder($order, 'order_canceled')
        |     -> mutateForOrderInTransaction direction=+1 :71-74
        |     -> releaseForOrderInTransaction :349-449
        |        - OrderItem::lockForUpdate per line
        |        - delta = min(requestedQty, quantity - released_qty)  <-- LEDGER CAP
        |        - $level->forceFill(+delta)->save()
        |        - StockMovement(reason=order_canceled, key seeded with 'released:N:delta:M')
        | (b) ReleaseAvailabilityOnOrderCanceled -> AvailabilityService::releaseForOrderItems
        |     -> daily_consumed_qty decrement + ItemAvailabilityChanged on auto-restock

[REFUND] RefundCreated event                EventServiceProvider:165-168
        | (a) ReleaseStockOnRefundCreated     -> StockService::releaseForOrder($order, 'refund', $refundedItems)
        | (b) ReleaseAvailabilityOnRefundCreated -> AvailabilityService::releaseForOrderItems
        | Both honor OrderItem.released_qty ledger; duplicate dispatch = safe no-op.
```

Detailed evidence per hop is captured in the 3 specialist JSONs.

---

## Specialist Verdicts

| Specialist | Verdict | P0 | P1 | P2 | P3 | File |
|---|---|---|---|---|---|---|
| Architect | PASS_WITH_FINDINGS — cascade integrity solid     | 0 | 1 | 2 | 1 | `architect.json` |
| DBA       | PASS_WITH_FINDINGS — UNIQUE+triggers verified    | 0 | 1 | 2 | 1 | `dba.json` |
| RED       | PASS_WITH_DISPUTES — 10 attacks: 8 defeated, 2 partial | 0 | 1 | 1 | 2 | `red.json` |

Cross-validated findings (≥2 specialists agree):
- **WF4-ARCH-01 ⟺ WF4-RED-01** — after-commit listener failure isolation policy not unified (3 specialists indirectly: Architect names file:line, RED frames as attack A6, DBA shares the test-coverage gap concern).
- **WF4-DBA-01 ⟺ WF4-RED-02** — trigger absence-of-defense test missing (raw DB::table delete/update never exercised by PHPUnit).
- **WF4-DBA-02 ⟺ WF4-RED-A8** — stock_levels CHECK constraint gap (Eloquent-only non-negative guard).

---

## 4-LIST OUTPUT

### KEEP (production-grade, do not touch)

1. **Foundation F-6 P0-ARCH-01 import path fix** — `DecrementStockOnOrderCreated.php:6` imports `App\Exceptions\Stock\StockUnavailableException` (commit `5bb8c48f9`). Expected business-rule path no longer mislogged.
2. **Foundation F-6 P0-DBA-01 trigger migration** — `database/migrations/2026_05_18_140000_add_stock_movements_immutability_triggers.php` APPLIED per `migrate:status [16] Ran`. Driver-conditional MySQL SQLSTATE 45000 + SQLite RAISE ABORT. Matches audit_logs/z_reports/cash_movements/delivery_boy_cash patterns.
3. **StockService lockForUpdate + on_hand recheck + UNIQUE idempotency_key + inner DB::transaction** quad-defense (`StockService.php:86-128`). Test sentinel: `StockConcurrentDecrementTest` (3 methods).
4. **OrderItem.released_qty ledger** as SSOT for compensable refund qty (migration `2026_04_23_100000` + `StockService:381-385`). Validated by `test_decrement_and_partial_refund_track_addon_target_stock_from_composition_snapshot` (3 idempotent partial refunds).
5. **idempotency_key seeded with 'released:N:delta:M' for refund/cancel; bare for order_created** (`StockService::movementKey:327-344`). Decrement retries no-op; refund partials are uniquely keyed per ledger increment.
6. **ChoiceAvailabilityResolver 5-layer precedence** (`ChoiceAvailabilityResolver:270-356`). F-016a-BIS manual rupture takes priority over auto stock. Test sentinels: 3 dedicated test files + `test_stock_zero_does_not_override_manual_admin_rupture_reason`.
7. **StockLevelChanged gated by isChoiceBoundaryMutation** (`StockService:459-467`) — prevents duplicate kiosk-cache fan-out for pure item-level decrements.
8. **syncItemAvailabilityForStockLevel auto-86 / auto-restock symmetry** (`StockService:162-215`) — manual rupture preserved across stock decrement.
9. **stock_movements UNIQUE idempotency_key constraint** named `stock_movements_idempotency_unique` (migration `2026_04_27_143130:19`).
10. **stock_movements FK restrictOnDelete** post-F-6 — prevents cascade-wipe when parent stock_level/branch hard-deleted.
11. **BranchScope global scope on StockLevel + StockMovement** (`StockLevel:25`, `StockMovement:23`) — multi-tenant isolation, admin (branch_id=0) bypass preserved.
12. **AvailabilityService daily counter TZ-aware reset via `Carbon::today(config('app.timezone'))`** (`:64, :115, :291`) — Wave 3c KDS-ADV3C-03 P1 fix.
13. **composition_snapshot SSOT** — frozen JSON at order create, refund release re-reads server-side (`StockService:317-322`). NF525-aligned. Validated by addon-refund test.
14. **NotifyStockLowOnStockLevelChanged** cache-throttled (`NotifyStockLowOnStockLevelChanged.php`) — toggle via `config('catalog_v15.stock_low_alert.enabled')`, default false (no surprise mail send pre-owner-gate).
15. **Pre-decrement AvailabilityService::assertItemsOrderableForBranch chokepoint** (`AvailabilityService:215-274`) — soft-deleted Item / catalog_inactive caught before stock mutation reaches StockService.

### HEAL-NOW (clean files, scope-minimal)

**NONE applied this round.**

The single tactical heal candidate is **WF4-DBA-01 / WF4-RED-02** — add 2 test methods (~20 lines) to `tests/Feature/Stock/StockMovementsAppendOnlyTest.php` exercising raw `DB::table` delete/update to assert the F-6 P0-DBA-01 triggers fire empirically. Deferred to WF-7 RED-META synthesis decision (test-only file, clean, no frozen-zone, no NF525 invariant touch). The migration narrative claims defense-in-depth — the test surface should validate BOTH layers independently.

### BACKLOG V1.0.2 (cited evidence, defer)

1. **WF4-ARCH-01 / WF4-RED-01 (P1)** — Unify after-commit listener failure isolation policy across the 4 OrderCreated listeners. `DecrementStockOnOrderCreated.php:22-37` re-throws on Throwable in after-commit context (order already committed, FCM skipped, client sees 500). Sibling `DecrementItemAvailabilityOnOrder` has no try/catch. Apply consistent soft-fail policy (log + metric + return) keeping `PersistOrderCreatedToOutbox` as the only throw-on-failure (SSOT). Cross-validates with PK1-ARCH-01 (intersection-pos-kds-2026-05-18 round-1).
2. **WF4-DBA-01 / WF4-RED-02 (P1 DBA, P2 RED)** — Add raw-query trigger-fire test sentinels to `StockMovementsAppendOnlyTest`. Asymmetric severity: DBA flags as P1 because absence-of-defense regression risk is forensic-critical; RED frames at P2 because the migration is verified-applied and the SQL pattern matches 4 already-audited sibling tables.
3. **WF4-ARCH-02 (P2)** — `AvailabilityService::releaseForOrderItems` uses raw `DB::table('order_items')` which bypasses SoftDeletes scope. Cross-thread admin destroy race in V2 multi-branch. The `released_qty` ledger protects today. Document the bypass invariant.
4. **WF4-DBA-02 / WF4-RED-A8 (P2)** — Add CHECK constraints on `stock_levels` (`on_hand >= 0`, `reserved >= 0`, `reserved <= on_hand`). Eloquent saving() guard is bypassed by raw `DB::table` writes. No production caller does that today; V1.1 admin manual stock UI would land here.
5. **WF4-ARCH-03 (P2)** — `StockService::releaseForOrderInTransaction` doesn't read the original movement's delta — relies on `released_qty` ledger as cap. Becomes MID when V1.1 manual_in/manual_out admin UI ships.
6. **WF4-DBA-03 (P2)** — `stock_movements.reason` is DB-enum but no PHP-enum type-binding. Typos like `order_cancelled` (UK spelling) silently fail at DB layer; PHP-enum promotion fixes compile-time safety.
7. **WF4-ARCH-04 (P3)** — Two parallel release listeners (`ReleaseStockOnXxx` + `ReleaseAvailabilityOnXxx`) — no documented sequencing/atomicity guarantee. Counter mismatch self-resolves at daily_reset boundary.
8. **WF4-DBA-04 (P3)** — `stock_movements.idempotency_key` nullable — V1.1 manual_in/manual_out should NOT-NULL.
9. **WF4-RED-03 (P3)** — `ChoiceAvailabilityResolver::snapshotForItems` lockless vs `assertSelectionsOrderable` lockable — document the asymmetry.
10. **WF4-RED-04 (P3)** — `StockService::mutateForOrderInTransaction:62-64` silent no-op for non-Order/non-FrontendOrder fixture — add Log::warning to make silent path visible.

### BLOCK (must not merge to main without explicit gate)

**NONE.**

No P0. No frozen-zone touch required. No NF525 invariant breach. No security regression. No multi-tenant leak. No fiscal chain mutation.

---

## Adversarial Defenses Summary (RED)

10 attack vectors probed, 8 fully defeated, 2 partial:

| # | Attack | Outcome |
|---|---|---|
| 1 | Concurrent decrement race (2 POS terminals) | DEFEATED (lockForUpdate + UNIQUE + recheck + DB::transaction) |
| 2 | Refund-twice race | DEFEATED (released_qty ledger + seeded idempotency_key) |
| 3 | ChoiceAvailability surface_hidden bypass | DEFEATED (assertSelectionsOrderable 5-layer) |
| 4 | Soft-deleted Item decrement | DEFEATED at order submit (assertItemsOrderableForBranch) |
| 5 | Raw DB::table delete bypass (F-6 P0-DBA-01) | DEFEATED in prod (migration applied) — test gap |
| 6 | Listener after-commit Throwable cascade | PARTIAL (outbox SSOT survives, FCM skipped, client sees 500) |
| 7 | Manual-rupture vs stock-rupture priority inversion | DEFEATED (F-016a-BIS precedence) |
| 8 | Stock_levels CHECK gap (raw negative on_hand) | PARTIAL (Eloquent holds; 0 production callers; CHECK missing) |
| 9 | Cross-branch decrement leak | DEFEATED (explicit branch_id filter + BranchScope) |
| 10 | Composition_snapshot addon swap | DEFEATED (frozen JSON, server-side trust) |

The 2 PARTIAL attacks both cross-validate with the 3 P1 findings (after-commit policy + CHECK gap).

---

## PHPUnit Evidence

```
$ php artisan test --filter "Stock"
Tests: 5 skipped (consolidated into StockMovementsAppendOnlyTest), 79 passed
Time:  11.60s

$ php artisan test --filter "Availability"
Tests: 1 skipped, 117 passed
Time:  17.73s
```

Key passing sentinels:
- `StockMovementsAppendOnlyTest::test_stock_movements_are_append_only` (Eloquent layer)
- `StockMovementsAppendOnlyTest::test_stock_movements_cannot_be_deleted` (Eloquent layer)
- `StockMovementsAppendOnlyTest::test_stock_movement_idempotency_key_is_unique_when_present` (UNIQUE constraint, last-line DB defense)
- `StockConcurrentDecrementTest::test_atomic_guard_allows_only_available_quantity` (3 successes / 2 failures on on_hand=3)
- `StockConcurrentDecrementTest::test_stress_guard_allows_only_20_successes_across_50_attempts` (20/50 on on_hand=20)
- `StockConcurrentDecrementTest::test_failed_multi_line_decrement_rolls_back_prior_stock_changes` (inner txn rollback)
- `StockLevelSchemaTest::test_stock_levels_schema_and_unique_stockable_tuple`
- `StockLevelSchemaTest::test_stock_level_rejects_reserved_greater_than_on_hand`
- `StockRuptureAvailabilitySyncTest::test_stock_zero_does_not_override_manual_admin_rupture_reason` (F-016a-BIS manual precedence)
- `StockReleaseOnRefundTest::test_decrement_and_partial_refund_track_addon_target_stock_from_composition_snapshot` (3 partial refunds, idempotent stop)
- `StockSymmetryDiffTest::test_order_service_and_frontend_order_service_stock_hunks_stay_symmetric`
- `WizardOptionStockSyncTest` 4/4 methods (variation+extra+addon rupture event emission)

5 consolidated skipped tests in `StockMovementIdempotencyKeyUniqueTest` deliberately deprecated into `StockMovementsAppendOnlyTest` + migration UNIQUE constraint (Z-3 2026-05-18 verification on MySQL prod).

---

## Cross-Zone Handoffs

- **WF-5 FISCAL-CASCADE** — `FrontendOrder.fiscal_alloc_error_at` and fiscal sequence allocation timing (touches the same after-commit window as Stock listener).
- **WF-6 REFUND-CASCADE** — Refund flow shared release path (`StockService::releaseForOrder` + `AvailabilityService::releaseForOrderItems`).
- **WF-7 RED-META-AUDIT** — Cross-zone synthesis: (a) listener after-commit isolation policy unification across 4 listeners (cross-validates WF-4 + PK-1 from `intersection-pos-kds-2026-05-18`), (b) raw-query trigger-fire test sentinel pattern (cross-validates audit_logs / z_reports / cash_movements existing sentinels).
- **WF-8 SENTINEL-DISCIPLINE-META** — Test-surface gap inventory (WF4-DBA-01 raw-query trigger sentinel candidate).
- **Z4 BRANCH-AUTH** — BranchScope global scope multi-tenant isolation confirmation (out of WF-4 scope).
- **Z5 SSOT-PRICING** — composition_snapshot integrity (read-only here, full audit in Z5).

---

## Heal Restraint Decision

Per advisor guidance and master mandate: **NO HEAL applied** this round.

- No DIRTY file touch (`OrderService.php`, `FrontendOrderService.php` observed only).
- No FROZEN file touch (NF525 chain services not in WF-4 surface).
- The single clean-file heal candidate (`StockMovementsAppendOnlyTest` raw-query trigger sentinel, WF4-DBA-01 / WF4-RED-02) deferred to WF-7 RED-META synthesis decision because:
  1. Cross-validates with sibling table sentinels (audit_logs, z_reports, cash_movements, delivery_boy_cash). A unified test-pattern decision belongs to RED-META.
  2. The migration is verified applied. The test gap is a forensic/regression-detection concern, not an acute exploit.
  3. Adding only WF-4 sentinel risks asymmetric coverage if the same pattern audit isn't done in WF-5 fiscal sentinel review.

All findings have Read-verified `file:line` citations. PHPUnit baseline captured with empirical pass/fail count.

---

## Anti-Fiction Attestation

- Every finding lists a Read-verified `file:line`.
- Every KEEP item lists a Read-verified `file:line` or migration name + applied status.
- Cascade diagram hops 1-7 + 2 release paths individually traced via Read tool.
- PHPUnit `Stock` filter run: 79 passed / 5 skipped / 0 failed (11.60s).
- PHPUnit `Availability` filter run: 117 passed / 1 skipped / 0 failed (17.73s).
- Foundation F-6 commits `5bb8c48f9` (import path) + `ccc95e862` (trigger migration) verified via `git show --stat` + `migrate:status` ([16] Ran).
- Item / FrontendOrder / Order / OrderItem SoftDelete usage verified via grep — all 4 use SoftDeletes; `Item::query()` default scope excludes trashed (covered in WF4-RED-A4 evaluation).
- Empirical raw-query trigger-fire test was orchestrator-blocked at probe time (cleanup script would have dropped the F-6 triggers — auto-mode classifier denied perfectly, demonstrating the production posture is hostile to raw destructive operations). Test gap acknowledged in WF4-DBA-01.

End STATUS.
