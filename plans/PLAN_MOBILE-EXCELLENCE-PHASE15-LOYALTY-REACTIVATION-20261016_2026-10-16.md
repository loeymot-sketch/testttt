# Plan — MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016

## Objective

Make the complete local loyalty consent loop portable in the mobile app build: both « Désactiver mon compte fidélité » and « Réactiver mon compte » must use public storage/state APIs rather than the dev-helper namespace.

## Implementation

1. Add a small customer-facing reactivation helper in the loyalty screen.
2. Persist both `opted_in` and `opted_out` through `LC.storage.setConsent`.
3. Update `LC.loyalty` consent state through `_replaceAccount` and emit `lc:loyalty-changed` for both transitions.
4. Keep the current zero-balance behavior and current copy until the owner approves a backend 30-day policy.
5. Add a browser assertion that opt-out → reactivation changes consent and restores the QR without `dev-helpers`.

## Allowlist

- `mobile/screens-main.jsx`
- `mobile/index.html`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/loyalty-11-opt-out.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016_2026-10-16.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Execution

`EXECUTION_TIER: complex` — loyalty state and consent are business-sensitive; no backend mutation is authorized in this lot.

`PRIMARY_EXECUTION_MODEL: gpt-5.5-pro`

`EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)`

## Remediation audit cycle

`REMEDIATION_AUDIT_CYCLE: 1/5`

`REWORK_REASON: fallback audit found that the opt-out path still depended on window.LC.dev; the customer-facing consent loop must be portable before the cycle can pass.`
