# EXECUTE BRIEF — CV1-M04B-PAYMENT-PILOT-RESTRICT (M-04B)

## Inviolable

1. Read `AGENTS.md`, this mission `input.json`, this `plan_excerpt.md`, and the M-04B section of the parent plan before editing.
2. Payment Ledger gate is approved only as Option B — Restricted pilot.
3. Do not implement M-04A. Do not create migrations, schemas, ledger tables, or full payment ledger flows.
4. Touch only the allowlist.

## Objective

Implement a restricted payment pilot with explicit server refusal outside the pilot and auditable blocked attempts.

## Implementation requirements

- `config/payment.php`:
  - Add explicit pilot restriction settings with conservative defaults.
  - Avoid silent behavior where an `.env` value alone enables unsupported methods without code-side allowlist checks.
- `app/Http/Requests/PaymentMethodRequest.php`:
  - Create or update request validation for payment method pilot restrictions where route/request-level validation is appropriate.
- `routes/api.php`:
  - Add route guard/wiring only if needed by the existing payment path.
- `app/Services/PaymentService.php`:
  - Enforce restricted pilot server-side.
  - Return explicit refusal for unsupported/out-of-pilot methods.
  - Audit blocked attempts with method, reason, branch context when available, and actor/device context when available.
  - Preserve backend pricing SSOT and branch isolation.
- `app/Http/Controllers/Frontend/PaymentController.php`:
  - Do not show non-pilot payment gateways on the server-rendered payment page.
  - Reject and audit unsupported methods before invoking any external gateway.
- Tests:
  - `PaymentMethodRestrictedTest.php` proves unsupported methods are rejected and pilot methods are allowed.
  - `PaymentMethodAttemptAuditTest.php` proves blocked attempts are auditable.

## Validation

Run:

```bash
php artisan test --filter=PaymentMethodRestrictedTest
php artisan test --filter=PaymentMethodAttemptAuditTest
```

If implementation discovers the existing payment API cannot support this without schema/migration, stop and mark `ESCALATE` in JSON rather than expanding scope.

## Output contract

Return one JSON object with `files_to_modify`, `implementation_steps`, `code_blocks`, `risks`, `notes`, and `execution_trace`. Include `execution_trace.delegation = "codex-extension"` and list the invariants checked.
