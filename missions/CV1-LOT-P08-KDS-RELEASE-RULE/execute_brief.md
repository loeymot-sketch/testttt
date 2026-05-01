# Execute Brief — CV1-LOT-P08-KDS-RELEASE-RULE

Wave: Caisse V1 Wave 2 Option B  
Run order: 22/36  
Lot: P-08 (POS)  
Status: `READY_GATE_APPROVED`

## Objective

Règle release explicite KDS, expected_status et dedupe outbox.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Domain/Kds/KitchenReleaseRule.php`
- `app/Listeners/DispatchKdsTicket.php`
- `app/Services/KitchenDisplaySystemOrderService.php`
- `tests/Feature/KdsTransitionWhitelistTest.php`
- `tests/Feature/KdsExpectedStatusConflictTest.php`
- `tests/Feature/KitchenReleaseRuleTest.php`
- `tests/Feature/KdsPaginationOverflowTest.php`

## Gates

- GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25: Approved Option B

## Tests

- `php artisan test --filter='KdsTransitionWhitelistTest|KdsExpectedStatusConflictTest|KitchenReleaseRuleTest|KdsPaginationOverflowTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P08-KDS-RELEASE-RULE execute "allowlist from input.json" "W2 P-08"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
