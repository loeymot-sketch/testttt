# Plan — MOBILE-EXCELLENCE-PHASE14-NEW-ARRIVALS-20261016

## Objective

Align the home « Nouveautés » section with the live mobile catalog metadata. Include explicit `is_new` products and the `NOUVEAU` tag, retain editorial `TOP` picks as fallback/secondary discovery, and keep the visible rail bounded to four products.

## Allowlist

- `mobile/screens-main.jsx`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE14-NEW-ARRIVALS-20261016.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE14-NEW-ARRIVALS-20261016_2026-10-16.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE14-NEW-ARRIVALS-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Execution

`EXECUTION_TIER: routine` — small presentation-only selection change, no critical business invariant in scope.

`EXECUTE_DELEGATION: foodking-routine-implementer`

If implementation reveals that catalog metadata itself must change, stop and escalate; do not modify `mobile/data/menu.js` in this lot.
