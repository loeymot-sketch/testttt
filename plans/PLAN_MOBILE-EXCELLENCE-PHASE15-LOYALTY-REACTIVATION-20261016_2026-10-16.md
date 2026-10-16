# Plan — MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016

## Objective

Make the disabled-loyalty screen's « Réactiver mon compte » button functional in the mobile app build by using public storage/state APIs rather than the dev-helper namespace.

## Implementation

1. Add a small customer-facing reactivation helper in the loyalty screen.
2. Persist `opted_in` through `LC.storage.setConsent`.
3. Update `LC.loyalty` consent state through `_replaceAccount` and emit `lc:loyalty-changed`.
4. Keep the current zero-balance behavior and current copy until the owner approves a backend 30-day policy.
5. Add a browser assertion that opt-out → reactivation changes consent and restores the QR.

## Allowlist

- `mobile/screens-main.jsx`
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
