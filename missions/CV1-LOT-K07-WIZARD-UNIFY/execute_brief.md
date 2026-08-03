# Execute Brief — CV1-LOT-K07-WIZARD-UNIFY

Wave: Caisse V1 Wave 2 Option B  
Run order: 21/36  
Lot: K-07 (KIOSK)  
Status: `READY`

## Objective

Converger KioskWizardComponent et KioskPosWizardComponent pour éviter double implémentation pricing/wizard.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/**`
- `resources/js/helpers/kioskPricing.js`
- `resources/js/helpers/kioskPricingPreview.js`
- `tests/js/KioskWizard.spec.js`
- `tests/js/kioskWizardNavigation.spec.js`
- `tests/js/kioskSandwichSplit.spec.js`
- `tests/js/kioskTacosSize.spec.js`

## Gates

- No specific gate listed; still verify docs/gates/GATE_LOG.md before frozen edits.

## Tests

- `npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskSandwichSplit.spec.js tests/js/kioskTacosSize.spec.js`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-K07-WIZARD-UNIFY execute "allowlist from input.json" "W2 K-07"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
