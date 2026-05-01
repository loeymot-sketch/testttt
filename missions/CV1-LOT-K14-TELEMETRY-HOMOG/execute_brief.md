# Execute Brief — CV1-LOT-K14-TELEMETRY-HOMOG

Wave: Caisse V1 Wave 2 Option B  
Run order: 35/36  
Lot: K-14 (KIOSK)  
Status: `READY`

## Objective

Homogénéiser kiosk_event UX avec schéma stable.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/helpers/kioskAnalytics.js`
- `app/Http/Controllers/Frontend/KioskEventController.php`
- `tests/js/kioskAnalytics.spec.js`
- `tests/Feature/KioskEventStoreSchemaTest.php`
- `tests/Feature/KioskEventTest.php`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/kioskAnalytics.spec.js`
- `php artisan test --filter='KioskEventStoreSchemaTest|KioskEventTest|KioskEventAbilityTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K14-TELEMETRY-HOMOG execute "allowlist from input.json" "W2 K-14"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
