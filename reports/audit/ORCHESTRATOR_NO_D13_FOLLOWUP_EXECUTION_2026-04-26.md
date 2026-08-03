# Orchestrator No-D13 Follow-Up Execution - 2026-04-26

TASK_ID: ORCHESTRATOR_NO_D13_FOLLOWUP_EXECUTION
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8
D13_SCOPE: NOT_TOUCHED_BY_USER_REQUEST

FINAL_VERDICT: NO_D13_PLAN_APPLIED_TECHNICAL_PASS_WITH_EXPECTED_D13_SENTINEL
RELEASE_VERDICT: HOLD_UNTIL_D13_AND_GOVERNANCE

## 1. Audit Delta

After the previous orchestrator pass, the remaining executable no-gate residual in the massive audit was:

- `P2-UX-09 - Reorder POS expose les prix historiques`.

The risk was not that historical prices are displayed. That is useful for re-importing a past cart. The risk was an unproven contract: a reordered cart must always be re-quoted through backend SSOT before commit, and historical row prices must never become payment authority.

Schema/gate items intentionally not touched:

- D-M13 `(branch_id, queue_number)` uniqueness.
- `kiosk_machines` unique `(branch_id, machine_id)`.
- Phase A persistence/untracked triage.
- Active primary and memory-policy governance.

## 2. Implementation

Changed `app/Http/Controllers/Admin/PosOrderController.php`:

- `reorderItems()` now loads `orderItems.orderItem`, matching the actual `OrderItem::orderItem()` relation.
- Removed eager loading of nonexistent `item`, `itemVariations`, and `itemExtras` relations.
- Added snapshot-aware variation/extra normalization:
  - prefer immutable `composition_snapshot.lines` and `composition_snapshot.extras`;
  - fall back to legacy `item_variations` / `item_extras` JSON columns.

Added `tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php`:

- Builds a historical order line with old unit price `1.00` while the current catalog price is `10.00`.
- Asserts reorder returns the historical unit price for display/re-import.
- Commits the reordered cart via POS quote binding.
- Asserts the new persisted order and order item use the current backend SSOT price `10.00`, not the historical `1.00`.

## 3. Validation

Syntax:

- `php -l app/Http/Controllers/Admin/PosOrderController.php` => PASS
- `php -l tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php` => PASS

Targeted:

- `php artisan test tests/Feature/Sentinels/PosReorderHistoricalPricingSentinelTest.php tests/Feature/POSComprehensiveTest.php --filter='reorder|PosReorder'` => 1 passed
- `php artisan test tests/Feature/POSComprehensiveTest.php --filter=test_pos_can_reorder_items` => 1 passed
- `php artisan test --filter='PosReorderHistoricalPricingSentinelTest|PosOrderRequestNullableTotalTest|QuoteBindingTest|PosSubtotalForgerySentinelTest|PosPricingSsotProofTest'` => 12 passed

Full backend:

- `php artisan test` => 1080 passed, 8 skipped, 1 failed.
- Remaining failure: `Tests\Feature\Sentinels\QueueNumberUniquenessSentinelTest`.
- Interpretation: expected D-M13 schema gate. The test was not changed and the schema gate was not attacked.

Static:

- `git diff --check` on scoped files/reports => PASS.

## 4. Invariants

| Invariant | Result |
| --- | --- |
| Backend pricing SSOT | Preserved and proven for POS reorder. |
| branch_id isolation | Preserved; no scope widening. |
| OrderStatus enum | Not touched. |
| Dispatch after commit | Not touched. |
| OrderService / FrontendOrderService symmetry | Not affected; this is POS reorder display + POS quote commit proof. |
| Frozen/migrations | No migration touched; D-M13 untouched. |

## 5. Residuals

All no-gate residuals identified by the latest massive audit are now either implemented or proven by tests.

Remaining blockers are intentionally outside this request:

1. D-M13 queue-number DB uniqueness.
2. Phase A persistence/untracked governance.
3. Quote subsystem persistence decision.
4. Legacy kiosk bundle release decision.
5. Active primary and memory-policy cleanup.

EXECUTION_RESULT: PASS_WITH_EXPECTED_D13_GATE
