# Branch Filter Matrix — Caisse V1 Wave 2 D-03

TASK_ID: `CV1-LOT-D03-BRANCH-FILTER-MATRIX`  
Date: 2026-04-26  
Scope: POS order lists, POS/admin order show, KDS list and KDS item board branch isolation.

## Gate Evidence

`docs/gates/GATE_LOG.md` records `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` as `Approved — Option C — Partial allowlist by method/surface` for `app/Services/OrderService.php` and `app/Services/KitchenDisplaySystemOrderService.php`.

No product patch was required during D-03 because the baseline sentinels already pass.

## Matrix

| Surface | Code Path | Branch Rule | Evidence Test |
|---|---|---|---|
| POS/admin order list | `OrderService::list` | Accepted `branch_id` filter uses exact equality via `applyOrderFilter`, never substring matching. | `OrderListBranchExactnessSentinelTest` |
| Customer/order-derived lists | `OrderService::userOrder`, `deliveredOrder`, `deliveryBoyOrder`, `salesReportOverview` | Accepted `branch_id` filter is routed through the same exact equality helper; user/delivery filters remain actor-scoped. | `OrderBranchIsolationTest` |
| POS/admin order show | `PosOrderController::show` + `OrderService::show` | Controller loads without `BranchScope` only to find the row, then service denies non-global-admin staff if the order branch differs. | `OrderShowBranchGuardSentinelTest` |
| KDS order list | `KitchenDisplaySystemOrderService::list` | Branch staff are pinned to their authenticated branch; explicit `branch_id` filter uses integer equality for admin views. | `KdsBranchFilterExactTest` |
| KDS item board | `KitchenDisplaySystemOrderService::orderItems` | Branch staff are pinned to their authenticated branch before items are flattened and grouped. | Covered by service source contract and D-03 matrix; add a dedicated sentinel if this path changes. |

## Negative Patterns Guarded

- No `branch_id LIKE '%x%'` filters.
- No trust in client branch id for kiosk order writes.
- No global branch visibility for branch staff.
- No masking of POS/admin show 403 as a 422 response.

## Validation

- `php artisan test --filter='OrderListBranchExactnessSentinelTest|OrderShowBranchGuardSentinelTest|KdsBranchFilterExactTest|OrderBranchIsolationTest'` — PASS, 4 tests.
- `bash scripts/lint-fk-branch-isolation.sh` — PASS.

## Symmetry Note

`SYMMETRY_NOTE`: `OrderService.php` was inspected but not modified in D-03. Existing OS/FOS symmetry for exact branch filtering is already documented in `docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md` and verified by `OrderBranchIsolationTest`.
