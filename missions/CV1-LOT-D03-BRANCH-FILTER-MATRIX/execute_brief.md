# Execute Brief — CV1-LOT-D03-BRANCH-FILTER-MATRIX

Wave: Caisse V1 Wave 2 Option B  
Run order: 7/36  
Lot: D-03 (DATA)  
Status: `READY_WITH_FROZEN_GATE_CHECK`

## Objective

Matrice branch_id sur listes POS, admin order, KDS; ajouter un test par filtre critique.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/OrderService.php`
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Http/Controllers/Admin/PosOrderController.php`
- `docs/orchestration/BRANCH_FILTER_MATRIX_CAISSE_V1_2026-04-26.md`
- `tests/Feature/Sentinels/OrderListBranchExactnessSentinelTest.php`
- `tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php`
- `tests/Feature/KdsBranchFilterExactTest.php`

## Gates

- GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching services

## Tests

- `php artisan test --filter='OrderListBranchExactnessSentinelTest|OrderShowBranchGuardSentinelTest|KdsBranchFilterExactTest|OrderBranchIsolationTest'`
- `bash scripts/lint-fk-branch-isolation.sh`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D03-BRANCH-FILTER-MATRIX execute "allowlist from input.json" "W2 D-03"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
