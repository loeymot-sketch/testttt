# MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016

## Mandate

Fix the complete mobile loyalty consent loop so opt-out and reactivation work without relying on the prototype-only `window.LC.dev` namespace. Persist the local consent/state through the public storage and loyalty state APIs, then re-render the loyalty screen.

## Scope

- `mobile/screens-main.jsx`
- `mobile/index.html`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/loyalty-11-opt-out.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016/report.md`

## Explicit boundary

This lot does not invent or change the business policy for a 30-day restoration window, points preservation, backend deletion, or consent audit. It only fixes the currently broken local reactivation control. A backend policy lot remains required for production-grade retention/reactivation semantics.

## Invariants

- No price, order, payment, branch, dispatch, database, backend, or native packaging change.
- Opt-out remains explicit and clears the local loyalty state as currently specified.
- Reactivation emits the existing loyalty change event and restores the opted-in UI state.
- No direct dependence on `window.LC.dev` is required for either customer-facing consent control.
- No backend 30-day retention policy is invented by this remediation.
