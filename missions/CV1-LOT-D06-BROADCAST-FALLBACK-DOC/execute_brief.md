# Execute Brief — CV1-LOT-D06-BROADCAST-FALLBACK-DOC

Wave: Caisse V1 Wave 2 Option B  
Run order: 16/36  
Lot: D-06 (DATA)  
Status: `READY`

## Objective

Documenter fallback polling et ajouter config/UI hint quand broadcast est off.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `docs/REALTIME_SETUP.md`
- `docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md`
- `config/broadcasting.php`
- `resources/js/store/modules/posOrder.js`
- `tests/js/realtimeBroadcastFallback.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/realtimeBroadcastFallback.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-D06-BROADCAST-FALLBACK-DOC execute "allowlist from input.json" "W2 D-06"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
