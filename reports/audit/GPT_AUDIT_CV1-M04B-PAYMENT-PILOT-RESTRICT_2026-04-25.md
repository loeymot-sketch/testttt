# GPT AUDIT — CV1-M04B-PAYMENT-PILOT-RESTRICT

Date: 2026-04-25
Audit channel: GPT/Codex local only
Claude: not used

## Scope

Human gate decision: `GATE_PAYMENT_LEDGER_V1_2026-04-25` Approved — Option B — Restricted pilot.

Implemented M-04B only. M-04A/full ledger and migrations were not added.

## Invariants

- pricing_ssot: OK — payment capture still uses persisted backend `order->total`; no frontend pricing logic added.
- order_status: OK — no order status strings added.
- branch_id: OK — blocked-attempt audit writes the order branch and actor branch where available.
- commit_before_dispatch: N/A — no dispatch path added or moved.
- frozen_zones: OK — `PaymentService` is opened by the frozen gate Option C surface, and no schema/fiscal/KDS/offline/web-stripe gate was consumed.

## Validation

PASS:

- `php -l app/Services/PaymentService.php`
- `php -l app/Http/Controllers/Frontend/PaymentController.php`
- `php -l app/Http/Requests/PaymentMethodRequest.php`
- `php -l config/payment.php`
- `php -l tests/Feature/Payment/PaymentMethodRestrictedTest.php`
- `php -l tests/Feature/Payment/PaymentMethodAttemptAuditTest.php`
- `php artisan test --filter='PaymentMethodRestrictedTest|PaymentMethodAttemptAuditTest'` — 4 passed
- `php artisan test tests/Feature/KioskPaymentStateMachineTest.php` — 5 passed
- `php artisan test tests/Feature/PosTicketRestaurantPaymentTest.php` — 1 passed

## Review

- Unsupported method capture is rejected before transaction creation and before `payment_status=PAID`.
- The server-rendered payment page filters non-pilot gateways, and POST payment attempts are rejected before external gateway invocation.
- Audit evidence is inserted in `audit_logs` with action `payment.method_restricted`.
- `PAYMENT_LEDGER_PILOT_METHODS` cannot silently enable a method because the allowlist is code-owned in `config/payment.php`.

## Verdict

VERDICT: PASS

M-04B can close. Remaining full-ledger work belongs to M-04A and is intentionally not selected because the signed gate chose Option B.
