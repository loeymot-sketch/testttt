# GPT Self Audit - CV1-LOT-K08-GLOBAL-ERRORS

## Scope

- TASK_ID: `CV1-LOT-K08-GLOBAL-ERRORS`
- Lot: K-08 KIOSK
- Delegation: `codex-extension`
- Objective: centralize kiosk error routing via `goToKioskError(code, payload)` and harmonize error screen telemetry/CTA contracts.

## Changes

- Added `KIOSK_ERROR_CODES`, `normalizeKioskErrorCode`, and `trackKioskErrorEvent` to `resources/js/helpers/kioskAnalytics.js`.
- Added `resolveKioskErrorRoute` and `goToKioskError(code, payload)` to `resources/js/store/modules/kioskCart.js`.
- Updated the four kiosk error screens to use `trackKioskErrorEvent` rather than direct `axios.post('/frontend/kiosk/event', ...)`.
- Added `tests/js/kioskGlobalErrors.spec.js` and `tests/Playwright/kiosk-errors.spec.js`.

## Invariants

- Pricing backend SSOT: PASS. No price, total, quote, or payment authority changed.
- OrderStatus enum: PASS. No order status logic changed.
- branch_id isolation: PASS. Error helpers do not read branch from URL and do not send branch_id.
- Dispatch after commit: PASS. No backend event/dispatch code changed.
- OS/FOS symmetry: PASS. Neither `OrderService.php` nor `FrontendOrderService.php` was modified.
- Frozen zones/gates: PASS. No frozen backend, schema, payment ledger, or admin POS code changed.
- Payment Ledger Option B: PASS. No M-04A/full ledger work.

## Validation

- `git diff --check -- resources/js/components/frontend/kiosk/KioskErrorLayoutComponent.vue resources/js/components/frontend/kiosk/KioskErrorMenuUnavailableComponent.vue resources/js/components/frontend/kiosk/KioskErrorNetworkComponent.vue resources/js/components/frontend/kiosk/KioskErrorPaymentRefusedComponent.vue resources/js/components/frontend/kiosk/KioskErrorProductRemovedComponent.vue resources/js/helpers/kioskAnalytics.js resources/js/store/modules/kioskCart.js tests/js/kioskGlobalErrors.spec.js tests/Playwright/kiosk-errors.spec.js` - PASS.
- `npx vitest run tests/js/kioskGlobalErrors.spec.js` - PASS, 5 tests.
- `npx playwright test tests/Playwright/kiosk-errors.spec.js` - NO_TESTS_FOUND because root Playwright config uses `testDir: ./tests/e2e`.
- `npx playwright test --config tests/Playwright tests/Playwright/kiosk-errors.spec.js` - PASS, 2 tests.
- `npx vitest run tests/js/KioskPhase3Screens.spec.js tests/js/KioskPhase3EdgeCases.spec.js tests/js/kioskGlobalErrors.spec.js` - PASS, 33 tests.

## Residual Risk

- The mission's exact Playwright command remains incompatible with the root config path; this is an existing test collection issue, not a product failure.
- `kioskCart.js` has pre-existing uncommitted Wave 2 changes from earlier lots; K-08 preserved them and only added the global error route helpers.

VERDICT: PASS
