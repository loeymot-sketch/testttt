# Execute Brief — CV1-LOT-P15-E2E-MATRIX

Wave: Caisse V1 Wave 2 Option B  
Run order: 36/36  
Lot: P-15 (POS)  
Status: `READY_AFTER_ALL_PRIOR_LOTS`

## Objective

Matrice E2E complète POS page-par-page jusqu'au KDS.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `tests/e2e/pos-full-journey/**`
- `tests/Playwright/pos-full-journey/**`
- `reports/baseline/POS_V4_E2E_BASELINE_2026-04-26.md`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx playwright test tests/Playwright/pos-full-journey --reporter=line --workers=1`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P15-E2E-MATRIX execute "allowlist from input.json" "W2 P-15"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
