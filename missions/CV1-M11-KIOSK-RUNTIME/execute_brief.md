# Execute Brief — CV1-M11-KIOSK-RUNTIME

Mode: GPT-only, no Claude, no sub-agent.

## Objective

Deliver kiosk runtime safety for Caisse V1:

- Replace the kiosk cancellation literal `status: 16` with the shared order status enum.
- Keep every local offline order reference strictly `offline_...`.
- Enforce signed offline gate Option A: kiosk may keep read-only menu behavior, but CB/TR offline payment must be disabled/refused and must not invoke TPE or backend confirmation.
- Enforce signed fiscal kiosk gate Option B: POS finalizes; kiosk must not self-fiscalize.

## Scope

Allowed files:

- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/helpers/kioskOfflineQueue.js`
- `app/Http/Controllers/Frontend/OrderController.php`
- M11 tests only under `tests/js/`, `tests/Playwright/sentinels/`, and `tests/Feature/KioskOfflinePaymentScopeTest.php`

## Requirements

- No frontend price authority. Existing display totals may remain UI-only; no payload total/subtotal/tax/tva authority.
- No OrderService/FrontendOrderService edit unless the output explicitly escalates with blockers.
- Do not edit built assets under `public/js/**`.
- If adding a UI message, prefer existing i18n keys; avoid expanding locale files unless absolutely required and documented.
- Preserve POS finalize boundary: kiosk card/TR confirmation may create/mark paid intent only where existing backend contract allows; M11 must not add direct fiscal seal/Z behavior.

## Validation

- `npm test -- tests/js/sentinels/kioskOfflineIdPrefix.spec.js tests/js/kioskCartOfflinePaymentScope.spec.js tests/js/KioskPaymentRestyle.spec.js`
- `php artisan test --filter=KioskOfflinePaymentScopeTest`
- `npx playwright test tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js`

If Playwright cannot run because no dev server/browser is available, record the exact blocker in `output_codex.json` and self-audit; do not fake the result.

## Invariants

- pricing_ssot: backend remains final pricing authority.
- order_status_enum: no magic `status: 16` in M11 kiosk code.
- branch_id: do not weaken kiosk machine branch resolution.
- dispatch_after_commit: no new dispatch path.
- frozen_zones: gates offline/fiscal approved in `docs/gates/GATE_LOG.md`.
- OS/FOS symmetry: N/A unless either service is edited.
