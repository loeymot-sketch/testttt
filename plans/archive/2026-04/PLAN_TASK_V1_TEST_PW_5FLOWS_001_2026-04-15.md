# Plan – TASK_V1_TEST_PW_5FLOWS_001 – 2026-04-15

## TASK_ID
TASK_V1_TEST_PW_5FLOWS_001

## PRIMARY_MODEL
Composer (routine — test files only, no application code changes)

## TEST_STRATEGY
`playwright-full-e2e` — the deliverable IS the test suite.

## PRIOR_CONTEXT
- Playwright is configured (`playwright.config.js`) with `testDir: './tests/e2e'`, baseURL `http://localhost:8000`.
- 4 existing specs are basic smoke tests (page-load + no-crash). They need to be upgraded to full flow tests.
- Login helper exists at `tests/e2e/helpers/login.js`.
- Credentials: `pos@lecayenne.fr / 123456`, `chef@lecayenne.fr / 123456`, `admin@lecayenne.fr / 123456`, kiosk: `kiosk-lecayenne / kiosk123`.

**Dependency note:** 3 of 4 declared dependencies (PRICING_SSOT, STATUS_MACHINE, MENU_86) are at gate. Tests are written against current behavior — refactors maintain same behavior, so tests remain valid after those tasks complete.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `tests/e2e/01-auth-refresh.spec.js` | Upgrade — add session persistence checks | Write | No | No |
| `tests/e2e/02-pos-cash.spec.js` | Upgrade — full order flow + payment | Write | No | No |
| `tests/e2e/03-kiosk-wizard.spec.js` | Upgrade — full kiosk order flow | Write | No | No |
| `tests/e2e/04-kds-status.spec.js` | Upgrade — status transitions | Write | No | No |
| `tests/e2e/05-pos-card.spec.js` | New — POS card payment flow | Write | No | No |
| `tests/e2e/helpers/login.js` | Extend — add kiosk login helper | Write | No | No |
| `playwright.config.js` | Minor tweaks (timeouts, retries) | Write | No | No |
| `.github/workflows/playwright.yml` | New — CI workflow | Write | No | No |
| `docs/PLAYWRIGHT_SUITE.md` | New documentation | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- All application code (backend + frontend) — no changes
- Database migrations
- Frozen zones

## INVARIANTS_AT_RISK
- None

## GATE_CONDITIONS
- None anticipated

## Execution Steps

### E1 — Upgrade existing specs to full flows

Upgrade the 4 existing spec files from smoke tests to deep flow tests while preserving the existing passing assertions.

**01-auth-refresh.spec.js:** Already good. Add: verify user info displayed after reload, verify no re-login prompt.

**02-pos-cash.spec.js:** Extend with full order cycle:
- Login POS → surface loaded
- Click a category → click an item → add to cart
- Verify cart shows item + price
- Click cash payment → confirm
- Verify order created (success message or order status visible)

**03-kiosk-wizard.spec.js:** Extend with kiosk order flow:
- Navigate to kiosk page (auto-login or login as kiosk)
- Browse categories → select item
- Go through wizard steps (if applicable)
- Add to cart → proceed to checkout
- Verify confirmation screen

**04-kds-status.spec.js:** Extend with transition flow:
- Login chef → KDS surface
- Verify orders visible (or seeded order)
- Click status transition buttons (if orders exist)

### E2 — New spec: 05-pos-card.spec.js

Create POS card payment spec — same as POS cash but selects card payment method.

### E3 — Extend login helper

Add `loginAsKiosk(page)` helper for kiosk machine authentication.

### E4 — CI workflow

Create `.github/workflows/playwright.yml` with MySQL, Redis services, seed, build, and run configuration.

### E5 — Documentation

Create `docs/PLAYWRIGHT_SUITE.md` with setup, run, and debug instructions.

### E6 — Validation

Run `php artisan test` to ensure no PHPUnit regressions. Playwright tests require a running server — validation notes that a server must be running for full e2e execution.

## SYMMETRY_NOTE
N/A

## SCOPE_PRESSURE


## ESCALATION


## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened
