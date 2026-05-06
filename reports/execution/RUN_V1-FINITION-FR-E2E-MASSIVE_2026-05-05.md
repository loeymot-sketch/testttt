# RUN — V1-FINITION-FR-E2E-MASSIVE

Date: 2026-05-05  
Status: PASS_LOCAL  
Goal: finition V1 en français métier, audit visuel par captures, corrections ciblées, tests E2E massifs.

## Preflight

- `npm run verify:boucle`: PASS conditionnel; binaire Claude present; API smokes non lances; warning connu `PHASE=(none)`.
- Activity log: collision récente sur `BAND-KDS-OSS-V1`; KDS/OSS traité après libération ou preuve stale.
- Graphiti: facts lus pour POS/Kiosk/KDS/fiscal/sync.

## Execution trace

- EXECUTE_DELEGATION: explicit-prompt-bind (human-acknowledged)
- Invariants considered: pricing backend SSOT, OrderStatus enum, branch_id isolation, dispatch after commit, OS/FOS symmetry, frozen zones.

## Loop Checklist

| Surface | Audit screenshots | Fixes | Tests | Verdict |
| --- | --- | --- | --- | --- |
| POS / Caisse | `tests/e2e/__screenshots__/pos/` | Libellés FR métier, contrastes caisse, dates et boutons | `02-pos-cash`, `05-pos-card`, `pos/tacos-4-viandes-cash-flow`, Vitest POS | PASS |
| Borne / Kiosk | `tests/e2e/__screenshots__/kiosk/` | Parcours borne FR, cartes produits accessibles, idle/categories/cart/payment/error | `03-kiosk-wizard`, lockdown, black-screen guard, auto-return, Vitest kiosk | PASS |
| KDS / Cuisine + OSS | `tests/e2e/__screenshots__/kds/`, `tests/e2e/__screenshots__/oss/` | Libellés cuisine FR, `Menu.Undefined`, contraste, heures 24h, instructions commande | `04-kds-status`, `pos-receipt-kds-instruction-sync`, Vitest KDS/OSS | PASS |
| Admin produits / stock / commandes | `tests/e2e/__screenshots__/admin/` | Admin FR, stock rupture, catalogue, dashboard, commandes, labels datepicker, contrastes, liens accessibles | D4 admin design, central-management CRUD runtime, Vitest admin ciblés | PASS |
| Global E2E | reports `c3-runtime-multi-surface`, `global-pos-kiosk-order-trace` | Validation transfert POS/borne vers KDS/OSS et audit backend | `c3-runtime-multi-surface`, `global-pos-kiosk-order-trace` | PASS |

## Validation Commands

- POS design: `PLAYWRIGHT_NO_WEB_SERVER=1 DESIGN_AUDIT_ITERATIONS=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/design/pos/d2-pos-design-audit.spec.js`
- POS functional: `PLAYWRIGHT_NO_WEB_SERVER=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/02-pos-cash.spec.js tests/e2e/05-pos-card.spec.js tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts`
- Kiosk design: `PLAYWRIGHT_NO_WEB_SERVER=1 DESIGN_AUDIT_ITERATIONS=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js --retries=0`
- Kiosk functional: `PLAYWRIGHT_NO_WEB_SERVER=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/03-kiosk-wizard.spec.js tests/e2e/kiosk-lockdown.spec.js tests/e2e/kiosk-spa-black-screen-guard.spec.js tests/e2e/kiosk-post-payment-auto-return.spec.js`
- KDS design: `PLAYWRIGHT_NO_WEB_SERVER=1 DESIGN_AUDIT_ITERATIONS=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js --retries=0`
- KDS functional: `PLAYWRIGHT_NO_WEB_SERVER=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/04-kds-status.spec.js tests/e2e/pos-receipt-kds-instruction-sync.spec.js --retries=0`
- Admin design: `PLAYWRIGHT_CHANNEL=chrome PLAYWRIGHT_NO_WEB_SERVER=1 DESIGN_AUDIT_ITERATIONS=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/design/admin/d4-admin-management-design-audit.spec.js --retries=0`
- Admin functional: `PLAYWRIGHT_CHANNEL=chrome PLAYWRIGHT_NO_WEB_SERVER=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/central-management-dashboard-crud.spec.js --retries=0`
- Global: `PLAYWRIGHT_CHANNEL=chrome PLAYWRIGHT_NO_WEB_SERVER=1 E2E_BACKEND=1 npx playwright test -c playwright.config.js tests/e2e/global-pos-kiosk-order-trace.spec.js tests/e2e/c3-runtime-multi-surface.spec.js --retries=0`

## Reports

- `reports/antigravity/d2-pos-design-audit.json`: `PASS_LOCAL_D2_SMOKE`
- `reports/antigravity/d1-kiosk-design-audit.json`: `PASS_LOCAL_D1_SMOKE`
- `reports/antigravity/d3-kds-oss-design-audit.json`: `PASS_LOCAL_D3_SMOKE`
- `reports/antigravity/d4-admin-management-design-audit.json`: `PASS_LOCAL_D4_SMOKE`, 5 screens, 0 serious axe issues
- `reports/antigravity/central-management-dashboard-crud.json`: `PASS_DASHBOARD_CRUD_RUNTIME_LOCAL`
- `reports/antigravity/c3-runtime-multi-surface.json`: `PASS_RUNTIME_LOCAL`
- `reports/antigravity/global-pos-kiosk-order-trace.json`: `PASS_GLOBAL_POS_KIOSK_TRACE`

## Notes

- Travail de finition uniquement. Pas d'amélioration concurrentielle Splash360 dans ce cycle.
- Les noms produits techniques visibles dans certaines captures proviennent des fixtures E2E locales, pas des libellés d'interface.
- `PLAYWRIGHT_CHANNEL=chrome` a été ajouté pour permettre un fallback navigateur quand `chromium_headless_shell` reste bloqué localement.
