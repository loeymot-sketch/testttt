# Plan — MOBILE-EXCELLENCE-PHASE3-CATALOG-REALIGN-20261016 — 2026-10-16

## TASK_ID

MOBILE-EXCELLENCE-PHASE3-CATALOG-REALIGN-20261016

## EXECUTION_TIER

complex — refonte UI multi-fichiers, parité catalogue, assets et tests E2E.

## PRIMARY_EXECUTION_MODEL

gpt-5.5-pro / xhigh via `codex-extension`, fallback documenté `foodking-complex-implementer`.

## SUBSYSTEMS_TOUCHED

mobile catalog data and assets, mobile visual components, compact allergen presentation,
mobile local validation, mobile critical-flow E2E.

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
PLAN_REVIEW_VERDICT: PENDING

## PRIOR_CONTEXT

- `mobile/` is the authoritative mobile UI target; the sibling `app/` is Laravel backend.
- Current catalogue has 15 backend drinks, with four local neutral fallbacks and three
  owner-requested drink names absent from the backend catalogue.
- Existing Phase 1/2 work already established category photos, canonical phone and logo;
  this lot must preserve those changes.

## EXECUTION STEPS

1. Reserve and inspect the declared mobile files; record pre-existing modifications.
2. Audit every category, featured/new item and drink asset; retain neutral fallback for
   missing exact packshots and add no invented product.
3. Refine shared image rendering so real photos are fully visible, accessible and never
   replaced by emoji; keep a deliberate neutral fallback for missing assets.
4. Keep allergen disclosure in item detail/recap while removing compact-card markers.
5. Add/update tests for categories, novelties, drinks, phone/brand continuity, visual
   containment and order payload invariants.
6. Run Node checks, Playwright critical flow and diff/scope guards.

## SYMMETRY_NOTE

Not applicable: `OrderService` and `FrontendOrderService` are out of scope; order payload
builder is read-only and must remain unchanged.

## VALIDATION

- `node mobile/tests/mobile-catalog-visual.spec.js`
- `node mobile/tests/mobile-identity.spec.js`
- `node mobile/tests/navigation.spec.js`
- `node mobile/tests/menu.spec.js`
- `node mobile/tests/ordersParity.spec.js`
- targeted Playwright mobile catalog/personality flow at `127.0.0.1:8088`
- `git diff --check`
- scope and delegation guards

