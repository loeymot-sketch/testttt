# Report – TASK_V1_TEST_PW_5FLOWS_001 – 2026-04-15

## Summary
Upgraded 4 existing Playwright smoke tests to deeper flow tests and created 1 new spec. Added CI workflow, kiosk login helper, and documentation.

## Changes
| File | Change |
|---|---|
| `tests/e2e/01-auth-refresh.spec.js` | Extended — session persistence after F5 |
| `tests/e2e/02-pos-cash.spec.js` | Extended — full POS cash order cycle test |
| `tests/e2e/03-kiosk-wizard.spec.js` | Extended — kiosk navigation flow test |
| `tests/e2e/04-kds-status.spec.js` | Extended — KDS order list + no crash test |
| `tests/e2e/05-pos-card.spec.js` | **New** — POS card payment flow |
| `tests/e2e/helpers/login.js` | Extended — `loginAsKiosk()` helper |
| `.github/workflows/playwright.yml` | **New** — CI workflow (MySQL, Redis, seed, build, run) |
| `docs/PLAYWRIGHT_SUITE.md` | **New** — setup, run, debug documentation |

## Test Results
- PHPUnit: 216 tests PASSED
- Playwright CLI: requires live server (expected for e2e writing task)
- Post-execute hook: exit 0

## Notes
- Tests use resilient selectors since app lacks `data-test` attributes
- `loginAsKiosk` exported but not yet wired into kiosk spec (kiosk may auto-login server-side)
- POS card spec mirrors cash structure — card payment specifics need live server validation
- Dependency gap: PRICING_SSOT, STATUS_MACHINE, MENU_86 at gates — tests target current behavior

## Delegation
EXECUTE_DELEGATION: app-routine-implementer

## Audit: PASSED
