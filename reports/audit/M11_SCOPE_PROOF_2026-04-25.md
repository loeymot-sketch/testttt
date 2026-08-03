# M11 Scope Proof — CV1-M11-KIOSK-RUNTIME

TASK_ID: CV1-M11-KIOSK-RUNTIME  
DATE_UTC: 2026-04-25T21:59:43Z  
MODE: GPT-only, no Claude, no sub-agent

## Changed In M11

- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `tests/js/kioskCartOfflinePaymentScope.spec.js`
- `tests/Feature/KioskOfflinePaymentScopeTest.php`

## Explicitly Not Changed

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `database/**`
- `routes/**`
- `public/js/**`
- Backend pricing services
- Fiscal sealing/Z services

## Gate Evidence

- Offline gate: Approved Option A — read-only menu / CB+TR refused offline.
- Fiscal kiosk gate: Approved Option B — POS finalize.

## Validation Evidence

- Vitest kiosk scoped suite: PASS, 3 files / 9 tests.
- PHPUnit `KioskOfflinePaymentScopeTest`: PASS, 2 tests.
- Playwright sentinel with `-c tests/Playwright`: PASS, 1 test.
- Scoped `git diff --check`: PASS.

VERDICT: PASS
