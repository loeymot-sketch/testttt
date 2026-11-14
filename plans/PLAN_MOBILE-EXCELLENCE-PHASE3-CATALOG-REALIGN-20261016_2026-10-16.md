# Plan — MOBILE-EXCELLENCE-PHASE3-CATALOG-REALIGN-20261016 — 2026-10-16

## TASK_ID

MOBILE-EXCELLENCE-PHASE3-CATALOG-REALIGN-20261016

## EXECUTION_TIER

complex — refonte UI multi-fichiers, parité catalogue, assets et tests E2E.

## PRIMARY_EXECUTION_MODEL

gpt-5.5-pro / xhigh via `codex-extension`, fallback documenté `foodking-complex-implementer`.

REASONING_EFFORT: xhigh

## SUBSYSTEMS_TOUCHED

mobile catalog data and assets, mobile visual components, compact allergen presentation,
mobile local validation, mobile critical-flow E2E.

### File-level scope

| Fichier | Action | Prix/options | branch_id/dispatch |
|---|---|---|---|
| `mobile/data/menu.js` | read/write image references only | lecture seule, aucun prix | aucun |
| `mobile/shared.jsx` | read/write image fallback/accessibility | aucun | aucun |
| `mobile/screens-main.jsx` | read/write visual cards/categories | affichage seulement | aucun |
| `mobile/screens-item-steps.jsx` | read/write compact/detail allergen presentation | aucun | aucun |
| `mobile/styles.css` | read/write visual layout | aucun | aucun |
| `mobile/tests/mobile-catalog-visual.spec.js` | read/write regression assertions | assertions only | aucun |
| `mobile/tests/mobile-identity.spec.js` | read-only regression assertion | assertions only | aucun |
| `mobile/tests/menu.spec.js` | read/write regression assertions | assertions only | aucun |
| `mobile/tests/navigation.spec.js` | read/write regression assertions | assertions only | aucun |
| `tests/mobile-e2e/catalog-visual-critical.spec.js` | add E2E coverage during EXECUTE | assertions only | aucun |
| `runtime API parity probe (no repository file)` | read-only comparison/report evidence | captures backend prices/options only | `branch_id=1`, no dispatch |
| `reports/execution/.../report.md` | write report | n/a | n/a |

## SUBSYSTEMS_OFF_LIMITS

backend pricing/order/auth/payment/loyalty, migrations, branch isolation, dispatch,
POS/caisse/kiosk frozen zones, native iOS packaging and signing.

## INVARIANTS_AT_RISK

Backend pricing SSOT; no frontend price logic; order payload parity; no brand/image
substitution; allergen disclosure remains available in detail/recap; no cross-branch data.

## GATE_CONDITIONS

No gate is opened for this UI/catalogue lot. Any request to add absent backend products,
change prices/options, wire SMS, change loyalty policy, enable Mollie in native, or edit
backend/native packaging must become a separate gated mission.

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS
FALLBACK_REASON: codex-extension unavailable after prior retries (broken local vendor binary, legacy model rejected, account usage limit)

## PRIOR_CONTEXT

- `mobile/` is the authoritative mobile UI target; the sibling `app/` is Laravel backend.
- Current catalogue has 15 backend drinks, with four local neutral fallbacks and three
  owner-requested drink names absent from the backend catalogue.
- Existing Phase 1/2 work already established category photos, canonical phone and logo;
  this lot must preserve those changes.
- The existing frontend price mirror and the stale historical order parity assertion are
  known baseline risks; this lot must not alter either to force green tests.

## EXECUTION STEPS

1. Reserve and inspect the declared mobile files; record pre-existing modifications.
2. Audit every category, featured/new item and drink asset; retain neutral fallback for
   missing exact packshots and add no invented product.
3. Refine shared image rendering so real photos are fully visible, accessible and never
   replaced by emoji; keep a deliberate neutral fallback for missing assets.
4. Keep allergen disclosure in item detail/recap while removing compact-card markers.
5. Add/update tests for categories, novelties, drinks, phone/brand continuity, visual
   containment and order payload invariants.
6. Create the planned `tests/mobile-e2e/catalog-visual-critical.spec.js` during EXECUTE;
   its absence at PLAN_REVIEW is intentional and is not a pre-existing test failure.
7. Run Node checks, the dedicated Playwright critical flow and diff/scope guards.

## KNOWN BASELINE / ESCALATIONS

- `mobile/data/menu.js` contains pre-existing offline price data and `priceFor` logic. This
  plan does not expand or normalize that architecture; a backend-driven catalog mission is
  required before claiming complete borne/app price parity.
- `node mobile/tests/ordersParity.spec.js` currently reports the historical C-1234 Tacos L
  snapshot at 7,90 € versus the current catalog at 8,90 €. Historical charged totals must
  remain snapshots; this unrelated baseline is recorded but is not an acceptance command
  for this visual/catalogue lot and must not be "fixed" by rewriting the order or current price.
- Missing exact Hawaï, Fuze Tea, Perrier, Orangina and owner-requested absent references are
  `BLOCKED_ASSET` until verified local assets or owner-provided rights-cleared packshots exist.
- Loyalty reactivation, SMS provider, Mollie native return and iOS packaging are explicitly
  out of scope and require separate plans/gates.

## SYMMETRY_NOTE

Not applicable: `OrderService` and `FrontendOrderService` are out of scope; order payload
builder is read-only and must remain unchanged.

## VALIDATION

- `node mobile/tests/mobile-catalog-visual.spec.js`
- `node mobile/tests/mobile-identity.spec.js`
- `node mobile/tests/navigation.spec.js`
- `node mobile/tests/menu.spec.js`
- read-only API parity probe against `http://127.0.0.1:8766/api/frontend/item` using the
  existing public app key and `branch_id=1`, plus the item-details endpoint for the visible
  catalog, recording HTTP status, item count, IDs, slugs, backend `price`, and option groups
  with required counts/limits in the execution report; the comparison must report zero drift
  or an explicit `ESCALATE_BACKEND_PARITY` finding, and must not write to the backend
- targeted Playwright mobile catalog/personality flow with
  `npx playwright test --config=tests/mobile-e2e/playwright.config.js
  tests/mobile-e2e/catalog-visual-critical.spec.js`, whose dedicated web server is 8087;
  port 8088 remains manual preview only and is not the acceptance target
- `git diff --check`
- scope and delegation guards

## PLAN_REVIEW_RECONCILIATION

- The planned E2E file is a required EXECUTE artifact, not an existing file accidentally
  omitted from PLAN_REVIEW.
- The historical `ordersParity` failure is a known baseline and is excluded from this lot's
  acceptance gate; it remains an explicit escalation for a future backend/order-parity mission.
- Historical mobile activity reservations have matching `EVENT=done` entries; no live
  reservation collision was found for this TASK_ID. The next EXECUTE reservation must still
  use the exact allowlisted files above.

