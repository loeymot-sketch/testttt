# MOBILE-EXCELLENCE-PHASE14-NEW-ARRIVALS-20261016

## Mandate

Make the mobile home « Nouveautés » rail reflect the catalog's actual new-arrival metadata, including products marked `is_new` or `NOUVEAU`, while retaining the existing TOP products as discoverable editorial picks.

## Scope

- `mobile/screens-main.jsx`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE14-NEW-ARRIVALS-20261016/report.md`

## Invariants

- No menu data, price, order payload, backend, payment, auth, loyalty, branch, or native packaging change.
- Product order and catalog objects remain unchanged; only the home selection predicate changes.
- The home rail remains limited to four cards and each card navigates to its product.

## Verification

- `bash .cursor/hooks/safety-check.sh`
- `node mobile/tests/mobile-ui-excellence.spec.js`
- `node mobile/tests/mobile-catalog-visual.spec.js`
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js`
- `git diff --check`
