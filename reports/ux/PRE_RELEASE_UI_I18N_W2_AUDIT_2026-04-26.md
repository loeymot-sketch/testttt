# Pre-Release UI / I18N / W2 Audit — 2026-04-26

TASK_ID: PHASE2_UI_I18N_W2_SIMULATION_AUDIT_2026-04-26  
EXECUTE_DELEGATION: codex-extension  
MODE: audit + targeted implementation + local simulation validation  
AUDIT_SCOPE: POS V4, kiosk live Vue, kiosk HTML prototypes, French release locale, simulated payment/printer readiness, W2 legacy bundle decision

## 1. Verdict

PRE_RELEASE_UI_I18N_W2_VERDICT: PASS_WITH_SCOPED_REMAINDERS

The V1 simulation target is technically usable for POS + kiosk in French with simulated payment/printer flows. I did not archive/delete any legacy kiosk bundle. The active kiosk is not the full red `Borne FoodKing.html` prototype, but it is a functional dark/red Vue kiosk with the real quote/order/payment/offline runtime. The correct next step is a controlled Vue design-port mission, not copying the HTML prototype over the live kiosk.

## 2. Human Decisions Applied

- Primary release language: French.
- Payment: simulated/manual confirmation for V1 validation; live PSP/TPE configuration remains a final hardware/business gate.
- Printer: simulated/test-print/healthcheck validation for now; real printer + cash drawer checked during hardware lab.
- Bangladesh/legacy payment routes: not enabled for France; cleanup should be a separate post-release or gated mission.
- Kiosk hardware: real devices exist, but software validation stays in simulation until the flow is proven.
- W2 bundle: no deletion. Any cleanup must be archive-with-manifest after human gate.

## 3. Implemented Corrections

### French release cleanup

- Filled missing French Vue keys and Laravel keys until audit reports `fr: 0 missing` for both stores.
- Changed visible POS labels from English to French, including:
  - `Best Sellers` -> `Meilleures ventes`
  - `Park` -> `Mettre en attente`
  - `Parked orders` -> `Commandes en attente`
  - `Hello` -> `Bonjour`
  - `Select Order Type` -> `Sélectionner le type de commande`
  - language display names `English/French` -> `Anglais/Français`
- Updated local default language to French and seeders so new installs default to French.
- Removed forced English locale in PDF reports.
- Replaced English category/menu demo labels such as `Chicken & Tenders` with French visible labels while preserving stable slugs.

### POS V4 shell stability

- Added POS V4 compatibility routes for shared layout links without importing the full admin router.
- Converted POS category pseudo-navigation from `router-link to="#"` to real buttons.
- Fixed `DefaultComponent` initial theme selection so POS V4 does not briefly mount frontend navbar links.
- Registered `vue-select` in the POS V4 slim entry because the POS surface actually uses it.
- Moved POS delivery autocomplete state out of `methods` and into component data.

### Kiosk start-order fix

- Fixed `KioskAppComponent.startOrder()` so the selected kiosk order type survives the reset before navigating to categories.
- Root cause: the idle screen set `KIOSK` / `TAKEAWAY`, emitted `start-order`, then the parent reset the cart and cleared the selected type, causing `KioskCategoriesComponent` to redirect back to idle.

### W2 legacy bundle audit

- `public/js/kiosk-wizard.js` is active in `public/mix-manifest.json`; do not archive it.
- `public/js/kiosk-shell.js`, `kiosk-admin.js`, `kiosk-errors.js`, and `kiosk-wizard-step.js` are active kiosk chunks.
- `public/js/kiosk.js` is legacy/candidate for W2 archive, but not moved in this mission.
- `public/js/pos-wizard.js` remains an active POS shim; not touched.

### Kiosk design audit

- Live Vue kiosk inspected:
  - `http://127.0.0.1:8000/kiosk/idle`
  - screenshot: `reports/ux/screenshots/live-kiosk-idle-final-production.png`
- Prototype inspected:
  - `http://127.0.0.1:8010/Borne%20FoodKing.html`
  - screenshot: `reports/ux/screenshots/mock-borne-foodking-http.png`
- Decision: live Vue kiosk must remain the source of functional truth. The red FoodKing prototype is the visual target for a controlled port into `resources/js/components/frontend/kiosk/*`.

## 4. Validation Evidence

### Build

- `npm run production` -> PASS.

### I18N

- `node tools/i18n/audit_locale_keys.mjs`
  - Vue `fr: 0 missing`
  - Laravel `fr: 0 missing`
  - Exit code remains non-zero because non-release locales still have legacy missing keys.

### POS browser validation

- Playwright authenticated POS V4:
  - URL: `http://127.0.0.1:8000/admin/pos-v4`
  - screenshot: `reports/ux/screenshots/live-pos-v4-auth-fr-final-production-v2.png`
  - non-WebSocket console errors: `[]`
  - forbidden English probes: `[]`

Visible sample after fixes:

```text
Bonjour Caissier Le Cayenne ... CAISSE ET COMMANDES ... Poulet croustillant ...
Meilleures ventes ... Mettre en attente ... Commandes en attente ...
Sélectionner le type de commande ... À Emporter ... Livraison ... Qté ... Sous-Total
```

### Kiosk browser validation

- Playwright kiosk idle:
  - screenshot: `reports/ux/screenshots/live-kiosk-idle-final-production.png`
  - English probes: `[]`
- Kiosk start click:
  - URL after click: `http://127.0.0.1:8000/kiosk/categories?cat=1`
  - screenshot: `reports/ux/screenshots/live-kiosk-after-start-final-production-v2.png`
  - non-WebSocket/CSP console errors: `[]`
  - English probes: `[]`

### JS targeted tests

- `npx vitest run tests/js/posPaymentComponentContract.spec.js tests/js/posA11y.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskGlobalErrors.spec.js`
  - 4 files PASS
  - 22 tests PASS
- `npx vitest run tests/js/kioskHardwareService.spec.js tests/js/posPrinter.spec.js tests/js/KioskPaymentRestyle.spec.js tests/js/kioskPaymentTpeTimeout.spec.js tests/js/kioskCartOfflinePaymentScope.spec.js tests/js/paymentComponent401Retry.spec.js tests/js/paymentComponentPropMutation.spec.js`
  - 7 files PASS
  - 28 tests PASS
- `npx vitest run tests/js/kioskOrderTypeExplicit.spec.js tests/js/kioskWizardNavigation.spec.js`
  - 2 files PASS
  - 13 tests PASS

### Playwright payment simulation

- `npx playwright test tests/e2e/05-pos-card.spec.js tests/e2e/02-pos-cash.spec.js --project=chromium`
  - 6 tests PASS
  - POS cash full cycle PASS
  - POS card surface PASS
  - no JS errors on card surface

### PHP payment validation

- `php artisan test tests/Feature/KioskPaymentStateMachineTest.php`
  - 5 tests PASS
- `php artisan test --filter='PaymentMethod|StripeActivationGuard|WebPaymentDisabled'`
  - 7 tests PASS

### Syntax / diff safety

- PHP lint PASS on modified PHP service/resource/seeders.
- Language JSON parse PASS.
- `git diff --check` PASS on targeted UI/i18n files.

## 5. Residual Risks / Remainders

1. Hardware UAT still pending:
   - TPE physical pairing.
   - Printer physical test-print.
   - Cash drawer opening on cash payment.
   - KDS screen on real network.
2. Live payment provider setup remains intentionally deferred. Current V1 simulation validates order/payment state behavior, not bank settlement.
3. Kiosk prototype port is not complete. The HTML design should be ported to Vue with allowlist:
   - `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`
   - `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
   - `resources/js/components/frontend/kiosk/KioskCartComponent.vue`
   - `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
   - kiosk design-system CSS/tokens only if needed
4. CSP report-only is currently emitted via meta in `resources/views/master.blade.php`; Chromium reports that Report-Only CSP via meta is ignored. Proper fix should move it to an HTTP header/middleware, not a UI patch.
5. Other locales (`en`, `ar`, `de`, `bn`) still have audit gaps. This is not blocking for France V1 if French is the signed primary language.

## 6. Release-Oriented Recommendation

Proceed with simulation validation using:

- POS: `http://127.0.0.1:8000/admin/pos-v4`
- Kiosk live: `http://127.0.0.1:8000/kiosk/idle`
- Prototype reference: `http://127.0.0.1:8010/Borne%20FoodKing.html`

Do not archive W2 bundles yet. Do not replace live kiosk with static HTML. Next best mission is:

TASK_ID: V1-KIOSK-FOODKING-DESIGN-PORT-2026-04-27  
Goal: port the red FoodKing prototype visual language into the active Vue kiosk screens while preserving quote token, branch isolation, offline queue, simulated payment, and printer/TPE healthcheck behavior.
