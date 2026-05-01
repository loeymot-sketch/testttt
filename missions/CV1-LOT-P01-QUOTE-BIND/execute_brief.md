# Execute Brief — CV1-LOT-P01-QUOTE-BIND

Wave: Caisse V1 Wave 2 Option B  
Run order: 2/36  
Lot: P-01 (POS)  
Status: `READY_WITH_FROZEN_GATE_CHECK`

## Objective

Forcer la consommation quote_token dans posOrderStore avec binding branch_id + actor_id + items_hash, rejet expiré/replayé.

## Option B Rule

Payment Ledger Option B restricted pilot is active. Do not launch or recreate `CV1-M04A-PAYMENT-LEDGER-FULL`. Do not expand to full ledger scope without a new human gate.

## Allowlist

- `app/Services/OrderService.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Http/Requests/PosOrderRequest.php`
- `tests/Feature/Pos/QuoteBindingTest.php`
- `tests/Feature/QuoteReplayIdempotencyTest.php`
- `tests/Feature/QuoteTamperTest.php`
- `tests/Feature/QuoteExpirationTest.php`

## Gates

- GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25: Approved Option C required before touching OrderService.php

## Tests

- `php artisan test --filter='QuoteBindingTest|QuoteReplayIdempotencyTest|QuoteTamperTest|QuoteExpirationTest|PosDiscountForgeryTest'`

## Execution Contract

- One TASK_ID equals one run.
- Start activity log before product edits:
  `bash scripts/agent-activity-log.sh start codex-extension CV1-LOT-P01-QUOTE-BIND execute "allowlist from input.json" "W2 P-01"`
- If a required file outside allowlist is needed, return `SCOPE_PRESSURE`; do not edit it.
- If a gate is unmet, return `BLOCKED_GATE`; do not edit frozen/schema/payment-ledger scope.
- Trace `EXECUTE_DELEGATION: codex-extension`.
- If touching `OrderService.php` or `FrontendOrderService.php`, add `SYMMETRY_NOTE` to output/report.
