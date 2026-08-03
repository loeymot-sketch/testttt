# Execute Brief — CV1-LOT-K01-ROUTING-LEGACY

Wave: Caisse V1 Wave 2 Option B  
Run order: 3/36  
Lot: K-01 (KIOSK)  
Status: `READY`

## Objective

Verrouiller redirect kiosk.products/:categoryId vers kiosk.categories?cat= et telemetry legacy_route_hit.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/router/modules/kioskRoutes.js`
- `resources/js/helpers/kioskAnalytics.js`
- `tests/js/kioskLegacyRedirect.spec.js`
- `tests/Playwright/kiosk-legacy-redirect.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/kioskLegacyRedirect.spec.js tests/js/kioskAnalytics.spec.js`
- `npx playwright test tests/Playwright/kiosk-legacy-redirect.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K01-ROUTING-LEGACY execute "allowlist from input.json" "W2 K-01"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
