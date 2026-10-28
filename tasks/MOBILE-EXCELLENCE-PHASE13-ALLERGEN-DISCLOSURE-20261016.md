# MOBILE-EXCELLENCE-PHASE13-ALLERGEN-DISCLOSURE-20261016

## Mandate

Improve the Le Cayenne mobile catalog so allergen information is available and understandable without placing warning-like pictograms on every product card or making the product photography look flagged. Keep the legal disclosure available on the product detail and final recap.

## Bounded scope

- `mobile/screens-main.jsx`
- `mobile/screens-item-steps.jsx`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE13-ALLERGEN-DISCLOSURE-20261016/report.md`

## Invariants

- No menu item, price, option, order payload, branch, auth, payment, loyalty, database, or backend change.
- The aggregate allergen set remains computed from the selected product, supplements, and drinks.
- The disclosure remains keyboard accessible and screen-reader labelled.
- No product image slot receives an allergen icon, emoji warning, or overlay.

## Verification

- `bash .cursor/hooks/safety-check.sh`
- `node mobile/tests/mobile-ui-excellence.spec.js`
- `node mobile/tests/mobile-catalog-visual.spec.js`
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js`
- `git diff --check`

## Execution

`EXECUTION_TIER: complex`

`PRIMARY_EXECUTION_MODEL: gpt-5.5-pro`

`EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)`

The implementation must stay limited to the files above; any request to touch payment, auth, loyalty, pricing, database, or native packaging is an escalation.
