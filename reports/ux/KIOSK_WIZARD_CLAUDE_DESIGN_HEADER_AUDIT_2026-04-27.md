# Kiosk Wizard Claude Design Header Audit - 2026-04-27

TASK_ID: KIOSK-WIZARD-CLAUDE-DESIGN-HEADER-2026-04-27  
EXECUTE_DELEGATION: codex-extension  
MODE: FRONTEND_UI_DESIGN_EXECUTE  
VERDICT: PASS_WITH_EXTERNAL_SAFETY_BLOCKER

## Objective

Apply the Claude Design wizard visual language to the active Vue kiosk wizard while preserving the current FoodKing selection logic, card behavior, cart flow, quote flow, payment flow, offline behavior, and backend pricing authority.

## Scope

Product source touched in this pass:

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`

Generated assets from `npm run production`:

- `public/js/kiosk-shell.js`
- `public/js/kiosk-wizard.js`
- `public/mix-manifest.json`

No backend file, route, database migration, payment flow, quote/HMAC flow, or order service was edited for this design pass.

## Before Audit

- The active wizard still inherited the dark kiosk theme inside the wizard shell.
- The Claude Design reference uses a warm white product-composition surface, a thin red top rail, stronger product title, tactile circular step icons, and a clearer progress treatment.
- The first browser rerun exposed a concrete contrast issue: in dark theme the step question was white on a light band.
- The user explicitly asked to keep the current selection logic and selection controls; only the visual shell/header/progress/colors were in scope.

## Implementation

- Scoped the wizard itself to the warm Claude Design palette:
  - white surface,
  - warm `#FFFBF5` background,
  - black text,
  - FoodKing red accent,
  - light borders and shadows.
- Kept the rest of the kiosk dark/light shell intact.
- Reworked the header into a compact Claude Design style:
  - thin red top rail,
  - `Vous composez` eyebrow,
  - stronger uppercase product title,
  - circular close button.
- Enlarged and softened the step icon row:
  - 78px circular icons,
  - red active ring,
  - stronger active label,
  - completed check badge retained.
- Kept the existing non-duplicative segmented progress line instead of reintroducing a second row of numbered circles.
- Did not change any Vue method, computed pricing behavior, selection update path, payload structure, or backend endpoint.

## Browser Validation

Playwright local route:

- Start: `http://127.0.0.1:8000/kiosk/idle`
- Flow: idle -> `Sur place` -> categories -> product -> wizard.

Screenshots:

- `reports/ux/kiosk-wizard-claude-design-tacos-final-2026-04-27.png`
- `reports/ux/kiosk-wizard-claude-design-wizard-final-2026-04-27.png`

CSS proof from browser:

- Header background: `rgb(255, 255, 255)`
- Header text color: `rgb(26, 26, 26)`
- Question text color: `rgb(26, 26, 26)`
- Step progress segment height: `8px`
- Step progress font size: `0px` (numbers hidden, line-style progress)
- Step icon size: `78px x 78px`
- Multi-step tacos wizard visible with 6 active step icons.

Known local browser noise:

- Report-only CSP warning from the local page.
- WebSocket connection refused on `127.0.0.1:6001` because realtime server is not running locally.
- No new wizard JS exception was observed.

## Automated Validation

```text
git diff --check -- resources/js/components/frontend/kiosk/KioskWizardComponent.vue
Result: PASS
```

```text
npx vitest run tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/KioskCategoriesRestyle.spec.js
Result: PASS - 3 files, 113 tests.
```

```text
npm run production
Result: PASS - Laravel Mix compiled successfully.
```

```text
bash .cursor/hooks/safety-check.sh
Result: HALT - pre-existing staged frozen file app/Services/OrderService.php.
```

## Invariants

- Backend pricing SSOT: preserved, no pricing authority moved to frontend.
- Branch isolation: not touched.
- OrderStatus enum: not touched.
- Dispatch after commit: not touched.
- OrderService / FrontendOrderService symmetry: not applicable, no order service edit.
- Wizard selection logic: preserved.
- Quote/payment/offline flows: not touched.

## Residual Risk

The global safety-check remains blocked by a pre-existing staged frozen file:

- `app/Services/OrderService.php`

This design pass did not stage or edit that file. The blocker must be handled by the active governance/gate flow, not by this UI patch.

FINAL_STATUS: PASS_WITH_EXTERNAL_SAFETY_BLOCKER
