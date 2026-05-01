# Kiosk FoodKing Design Port - 2026-04-27

TASK_ID: KIOSK-DESIGN-PORT-2026-04-27
EXECUTE_DELEGATION: codex-extension
MODE: FRONTEND_UI_EXECUTE
AUDIT_VERDICT: PASS_WITH_RESERVES

## Objective

Port the stronger full-screen red/dark FoodKing kiosk prototype language into the active Vue kiosk, without replacing the working order, quote, payment, offline, waiting, or confirmation flows.

The implementation keeps the live Vue route/component architecture and applies the prototype as a visual system: stronger red/dark composition, tactile category/product cards, larger payment amount emphasis, clearer order steps, and a persisted dark/light kiosk theme toggle.

## Prototype Inputs

- `borne (Remix)/foodking/atoms.jsx`
- `borne (Remix)/foodking/screens-main.jsx`
- `borne (Remix)/foodking/screens-pay.jsx`
- Live Vue route: `/kiosk/*`
- Current browser target during execution: `http://127.0.0.1:8000/kiosk/categories?cat=1`

## Scope Edited

Vue kiosk components:

- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue`

Generated assets from `npm run production`:

- `public/js/kiosk-shell.js`
- `public/js/kiosk-wizard.js`
- `public/mix-manifest.json`

Audit artifacts:

- `reports/ux/KIOSK_FOODKING_DESIGN_PORT_2026-04-27.md`
- `reports/ux/screenshots/kiosk-design-port-*.png`
- `reports/ux/screenshots/kiosk-design-port-*-summary-2026-04-27.json`

## Design Decisions

1. Theme root
   - Added a persisted kiosk visual theme at `KioskAppComponent.vue`.
   - Storage key: `foodking:kiosk-theme`.
   - Default: `dark`.
   - Root markers: `kiosk-theme--dark|light` and `data-kiosk-theme`.
   - Toggle control: `data-testid="kiosk-theme-toggle"`.

2. Idle
   - Ported the prototype's immersive red/dark start screen into the active idle component.
   - Kept existing locale and accessibility controls.
   - Preserved the explicit "Sur place" / "A emporter" order-type flow.

3. Catalogue
   - Rebuilt the visual density around the prototype: large left category rail, high-contrast product cards, large add buttons, stronger product image panels, and red active state.
   - Kept current live menu loading, category route query, filters, product availability, wizard handoff, and cart bottom bar.

4. Cart
   - Restyled order-type selector, cart rows, quantity controls, promo block, loyalty CTA, summary, and checkout buttons.
   - Kept quote-before-upsell and existing cart/order-type logic.

5. Payment
   - Added a prototype-style amount hero card.
   - Restyled payment method cards and confirmation CTA.
   - Kept payment selection, quote refresh, offline electronic-payment guard, TPE waiting, cash flow, and failure routing.

6. Secondary screens
   - Restyled upsell, waiting, confirmation, and cash-instruction screens with the same theme tokens.
   - Kept polling, cancellation, receipt, print, cash instruction, and route guards intact.

## Flow Preservation Check

- Backend pricing SSOT: no frontend price calculation was added beyond existing display totals.
- Quote/payment/offline flow: no backend endpoint or payload contract was changed in this UI pass.
- Branch isolation: no backend branch logic touched.
- Order status enum: no new hardcoded status strings introduced in this UI pass.
- Dispatch after commit: not touched.
- Hardware/payment integration: not activated or changed; payment remains compatible with simulated/manual terminal validation policy.

## Validation

Static:

- `git diff --check -- <touched kiosk components>`: PASS.
- Token scan on touched kiosk files for conflict markers/TODO/FIXME/color-mix: PASS.

Build:

- `npm run production`: PASS.
- Laravel Mix generated kiosk assets successfully.

Vitest targeted:

```text
npx vitest run tests/js/kioskOrderTypeExplicit.spec.js \
  tests/js/KioskCategoriesRestyle.spec.js \
  tests/js/KioskCartRestyle.spec.js \
  tests/js/KioskPaymentRestyle.spec.js \
  tests/js/KioskUpsellOrderSummaryRestyle.spec.js \
  tests/js/KioskPhase3Screens.spec.js \
  tests/js/KioskPhase3EdgeCases.spec.js \
  tests/js/kioskWaitingAudioFallback.spec.js \
  tests/js/kioskCartOfflinePaymentScope.spec.js \
  tests/js/kioskGlobalErrors.spec.js \
  tests/js/kioskOfflineQueue.spec.js \
  tests/js/kioskOfflineQueueMigration.spec.js \
  tests/js/kioskOfflineQueueV2.spec.js \
  tests/js/sentinels/kioskOfflineIdPrefix.spec.js
```

Result: 14 files PASS, 103 tests PASS.

Vitest kiosk full pattern:

```text
npx vitest run tests/js/kiosk*.spec.js tests/js/Kiosk*.spec.js
```

Result: 64 files PASS, 546 tests PASS.

Live server:

- `curl -I http://127.0.0.1:8000/kiosk/idle`: 200 OK.

Playwright visual coverage, 1080x1920:

- `reports/ux/screenshots/kiosk-design-port-idle-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-idle-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-categories-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-categories-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-cart-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-cart-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-payment-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-payment-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-upsell-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-upsell-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-waiting-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-waiting-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-confirmation-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-confirmation-light-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-cash-instruction-dark-2026-04-27.png`
- `reports/ux/screenshots/kiosk-design-port-cash-instruction-light-2026-04-27.png`

Browser notes:

- CSP warning is pre-existing/report-only: policy delivered by meta is ignored by Chromium.
- WebSocket errors are expected locally because Soketi/Pusher is not running on `127.0.0.1:6001`.
- `/kiosk/waiting/123` screenshot uses a demo order id and produces an expected 404 while still validating the waiting screen visual shell.

## Reserves

1. `bash .cursor/hooks/safety-check.sh` currently halts on a pre-existing staged frozen file:
   - `app/Services/OrderService.php`
   - This UI mission did not touch that file.

2. The worktree was already very dirty before this UI pass. The component files also contain prior functional edits from the POS/Kiosk chain. This report covers the visual integration pass and its validation, not the entire historical diff in those files.

3. Generated public assets changed because `npm run production` was executed. Deployment can either track those built files or rebuild assets in the target environment.

## Follow-Up

- Run one hardware-lab pass on the real kiosk screen/touch device after this UI pass is accepted.
- Run the payment simulation route with real configured terminal/mock hardware once the manual payment policy is finalized.
- If the dark/light toggle is accepted, document dark as the default kiosk visual mode and light as staff/customer preference fallback.

