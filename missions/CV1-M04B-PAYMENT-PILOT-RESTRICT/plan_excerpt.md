# PLAN EXCERPT — CV1-M04B-PAYMENT-PILOT-RESTRICT

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`

## M-04B — CAISSE_V1_PAYMENT_PILOT_RESTRICT_2026-04-25

Gate: `GATE_PAYMENT_LEDGER_V1_2026-04-25` approved on 2026-04-25.

Human decision: Option B — Restricted pilot.

Goal: explicit server refusal outside pilot, disabled/non-enabled surfaces where applicable, audit attempts, and no silent `.env` bypass.

Allowlist:

- `app/Services/PaymentService.php` (frozen — gate approved through Option C surface)
- `app/Http/Controllers/Frontend/PaymentController.php` (restricted pilot UI/server guard)
- `app/Http/Requests/PaymentMethodRequest.php` (new)
- routes guard (`routes/web.php` / `routes/api.php` only if wiring requires it)
- `config/payment.php`
- tests `PaymentMethodRestrictedTest.php`, `PaymentMethodAttemptAuditTest.php`

Do not execute M-04A. Do not add full payment ledger migrations.
