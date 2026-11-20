# Plan — MOBILE-EXCELLENCE-PHASE4-OTP-RESEND-20261016 — 2026-10-16

## TASK_ID

MOBILE-EXCELLENCE-PHASE4-OTP-RESEND-20261016

## EXECUTION_TIER

complex — auth UX, OTP testability and real API boundary.

## PRIMARY_EXECUTION_MODEL

gpt-5.5-pro / xhigh via `codex-extension`, fallback `foodking-complex-implementer`.

REASONING_EFFORT: xhigh

## FILE-LEVEL SCOPE

| File | Action | Constraints |
|---|---|---|
| `mobile/screens-onboarding.jsx` | add resend state/cooldown and accessible feedback | no auth rule change |
| `mobile/index.html` | wire resend to `guestOtp` and refresh local dev code | no secret/OTP fallback |
| `tests/mobile-e2e/utils/realAuth.js` | use local API `dev_code` only | no hardcoded OTP |
| `mobile/tests/mobile-otp.spec.js` | add static contract assertions | no runtime writes |
| `tests/mobile-e2e/auth-otp-local.spec.js` | add local auth flow | local backend only |
| `reports/execution/.../report.md` | record evidence and blockers | no secrets/PII |

## OFF-LIMITS

Backend auth controllers/services, OTP database, SMS gateway settings/secrets, pricing,
catalogue, orders, loyalty, Mollie, migrations, POS/KDS/kiosk, native iOS packaging/signing.

## EXECUTION STEPS

1. Inspect current OTP UI and realAuth helper without changing backend auth semantics.
2. Add a real resend callback with a visible 30-second cooldown, disabled/loading states,
   and French error feedback for network/429 failures.
3. Update the local E2E helper to submit the `dev_code` returned by the local OTP request;
   fail clearly if local mode does not return it rather than guessing a value.
   The helper must refuse non-loopback `LC_API_BASE` values and sanitize all error messages;
   it must never serialize or print an API response containing an OTP, token or phone.
4. Add focused static and Playwright coverage for login, resend and verification, including
   a proof that cooldown blocks duplicate requests and that a post-expiry resend updates the
   local code.
5. Run the scoped checks and document the external SMS-provider blocker separately.

## RISKS / ACCEPTANCE

- A resend must not be possible while the cooldown is active.
- A second OTP must update the local `dev_code` used by the local test path.
- Production UI must never show a server-returned dev code because the current API only
  returns it in local mode and the UI already gates on `LC.isDev`.
- Run the existing `DevOtpExposureTest.php` to preserve the server-side guarantee that
  `dev_code` is local-only and absent from staging/production.
- Full Apple readiness remains false until a real SMS provider, native project/signing,
  Mollie return flow and backend/mobile catalogue parity are separately verified.

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS
FALLBACK_REASON: codex-extension unavailable after prior retries (broken local vendor binary, legacy model rejected, account usage limit)

## REMEDIATION_AUDIT_CYCLE

1. Audit REWORK: clear the prior local `dev_code` before every resend and add a regression
   assertion that a response without `dev_code` cannot leave or reuse stale OTP UI state.
