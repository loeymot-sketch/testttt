# Execute Brief — CV1-M10-OS-FOS-SYMMETRY

You are executing M-10 in GPT-only mode. No Claude. No sub-agent.

## Objective

Formalize the post M-06/M-09 OrderService and FrontendOrderService symmetry contract:

- create flows: POS/admin/table vs kiosk/frontend are intentionally asymmetric
- status changes: both services must guard no-op side effects
- payment changes: `OrderService::changePaymentStatus` is POS/admin-only; FOS payment is kiosk `paymentConfirm` + `finalizePaidKioskOrder`
- branch isolation: both services use exact branch guard/filtering
- dispatch: status/payment side effects after DB mutation only

## Hard Boundary

Do not edit product code. Allowed outputs are:

- `docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md`
- `tests/Feature/Symmetry/OrderServicesContractTest.php`

If a product bug is discovered, return `ESCALATE` with exact file/line and keep product code unchanged.

## Tests

Run or define:

- `php artisan test --filter=OrderServicesContractTest`
- `php artisan test --filter=OrderStatusNoopSideEffectsTest`
- `php artisan test --filter=PaymentNoopIdempotencyTest`
- `php artisan test --filter=PaymentConfirmCrossBranchTest`

## Output Requirements

`output_codex.json` must include:

- files changed
- commands run
- `SYMMETRY_NOTE`
- whether any product gap was found
- invariants considered
