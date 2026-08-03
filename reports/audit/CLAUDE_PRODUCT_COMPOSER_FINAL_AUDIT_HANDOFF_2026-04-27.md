# Claude Final Audit Handoff - Product Composer, Catalogue, Stock, POS/Kiosk Sync

Date: 2026-04-27
Author: Codex
Mode: final Codex audit handoff to Claude
Verdict: `PASS_ON_SAFE_SLICES__GLOBAL_MISSION_NOT_COMPLETE__CLAUDE_DEEP_AUDIT_REQUIRED`

## 1. Purpose For Claude

Claude must audit whether the current FoodKing catalogue/product/composer work is coherent, production-safe, and complete enough to continue. Codex has delivered only safe slices and gate briefs. Codex did not implement schema, Stock V2, order-service stock decrement, or full dashboard write API because the required gates are still pending.

Claude should not assume the global mission is complete. Claude should audit aggressively and produce the next correction plan if any issue is found.

## 2. Mandatory Reading Order

1. `AGENTS.md`
2. `.cursor/ACTIVE_CYCLE.md`
3. `docs/gates/GATE_LOG.md`
4. `reports/audit/PRODUCT_COMPOSER_SYNC_DEEP_AUDIT_ORCHESTRATION_2026-04-27.md`
5. `reports/audit/PRODUCT_COMPOSER_SYNC_EXECUTION_CONTROLLER_2026-04-27.md`
6. `reports/audit/PRODUCT_COMPOSER_SYNC_CONTINUATION_REPORT_2026-04-27.md`
7. `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-01A-GATE-BRIEFS.md`
8. `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE.md`
9. `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE.md`
10. `missions/PRODUCT-COMPOSER-SYNC-01A-GATE-BRIEFS/execute_brief.md`
11. `missions/PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE/execute_brief.md`
12. `missions/PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE/execute_brief.md`

## 3. Files Codex Changed In The Safe Slices

Product dashboard:

- `resources/js/components/admin/items/ItemShowComponent.vue`
- `resources/js/components/admin/items/ProductComposerSummaryComponent.vue`
- `tests/js/productComposerSummary.spec.js`

Menu projections:

- `app/Services/Kiosk/KioskMenuService.php`
- `app/Services/Menu/MenuProjectionService.php`
- `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`

Governance/gates:

- `docs/gates/GATE_PRODUCT_COMPOSER_SCHEMA_2026-04-27.md`
- `docs/gates/GATE_STOCK_STOCKABLE_SCOPE_2026-04-27.md`
- `docs/gates/GATE_DASHBOARD_AUTHZ_CATALOG_OPS_2026-04-27.md`
- `docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_PRODUCT_COMPOSER_STOCK_2026-04-27.md`
- `docs/gates/GATE_E2E_HARDWARE_COMPOSER_SIGNOFF_2026-04-27.md`

Mission/report artifacts:

- `missions/PRODUCT-COMPOSER-SYNC-01A-GATE-BRIEFS/`
- `missions/PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE/`
- `missions/PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE/`
- `reports/audit/PRODUCT_COMPOSER_SYNC_CONTINUATION_REPORT_2026-04-27.md`
- `reports/audit/PRODUCT_COMPOSER_SYNC_EXECUTION_CONTROLLER_2026-04-27.md`
- `reports/post_execute_latest.log`

Compiled assets from build:

- `public/js/admin-shell.js`
- `public/mix-manifest.json`

## 4. What Is Validated

### 4.1 Kiosk Payment Surface

Browser state checked through the Codex in-app browser on `http://127.0.0.1:8000/kiosk/payment`.

Observed DOM:

- payment heading: `Choisissez votre paiement`;
- total shown: `€21,80`;
- payment choices: card, cash, restaurant ticket;
- no backend/admin header in the DOM snapshot;
- no visible admin/caisse navigation from the kiosk shell;
- browser console warnings/errors: none returned by the in-app browser dev log query.

Important nuance:

- The payment option text still says `Paiement à la caisse` for cash. This is customer-facing payment wording, not admin navigation. Claude should decide whether the wording is acceptable or should be changed to a softer kiosk phrase like `Paiement au comptoir`.

### 4.2 Dashboard Product Composition Visibility

Validated:

- product detail now has a `Composition` tab;
- the tab centralizes existing product composition primitives:
  - wizard template;
  - menu flag;
  - product image state;
  - backend-provided base price display;
  - item attributes;
  - variations;
  - extras grouped by `group_label`;
  - addons;
- it explicitly states final pricing remains handled by backend `PricingService`.

Tests:

- `npx vitest run tests/js/productComposerSummary.spec.js`: PASS, 3 tests.

### 4.3 POS/Kiosk Projection Constraints

Validated:

- canonical menu projection now includes `itemAttributes`;
- legacy kiosk menu projection now includes `itemAttributes`;
- `min_select`, `max_select`, and `allow_repeat` are projected;
- legacy kiosk projection and canonical kiosk projection agree on projected attribute ids.

Tests:

- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`: PASS, 4 tests.
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php`: PASS, 2 tests.

### 4.4 Build And Safety

Validated:

- `bash .cursor/hooks/safety-check.sh`: PASS.
- targeted `git diff --check`: PASS.
- `jq empty` on new mission inputs: PASS.
- `npm run production`: PASS, Laravel Mix compiled successfully. The terminal notification worker was manually stopped after compilation because it held the shell open.

## 5. What Is Not Complete

These are not implemented and must not be treated as done:

1. `item_wizard_profiles` and `item_wizard_steps` schema.
2. Admin write API for `GET/PUT/POST /api/admin/item/{item}/composer`.
3. Full drag/order/edit dashboard builder for product steps.
4. POS/kiosk runtime consumption of explicit composer steps.
5. Stock V2 quantitative model.
6. Stock for extras/variations/addons/ingredients.
7. Atomic decrement/release in `OrderService` and `FrontendOrderService`.
8. Stock rupture UI contract across POS and kiosk.
9. POS live order board for POS+kiosk orders as part of the Product Composer program.
10. Final E2E proof: dashboard creates product -> POS sees -> kiosk sees -> order -> stock rupture -> KDS/OSS -> handover/payment.

## 6. Pending Human Gates

All of these exist as draft briefs and remain `PENDING_HUMAN_GATE`:

- `HG-COMPOSER-SCHEMA-ADR`
- `HG-STOCK-STOCKABLE-SCOPE`
- `HG-DASHBOARD-AUTHZ-CATALOG-OPS`
- `HG-FROZEN-ORDERSERVICE-UNLOCK`
- `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`

Claude must not mark them approved. Claude may recommend exact approval options, but a human must decide.

## 7. Suspected Or Residual Problems For Claude To Audit

1. Kiosk cash wording:
   - Current text: `Paiement à la caisse`.
   - Risk: user may consider any word like `caisse` inappropriate on customer kiosk.
   - Ask Claude to decide if wording should become `Paiement au comptoir`.

2. Legacy public bundles:
   - Previous explorer noted legacy `public/js/kiosk*.js` artifacts may still exist.
   - Risk: release could serve stale JS if manifest/cutover is wrong.
   - Ask Claude to audit `public/mix-manifest.json`, blade entries, and release strict bundle guards.

3. Google Maps / delivery:
   - Existing fallback may accept address without coordinates and apply minimum delivery fee.
   - Risk: if distance-based fees are business-critical, backend must fail closed or require distance.
   - Ask Claude to audit `DeliveryFeeService`, POS delivery UI, quote/store backend, and tests.

4. Full Product Composer semantics:
   - Addons exist but do not yet have semantic roles like `drink`, `side`, `dessert`, `upsell`, `bundle`.
   - Ask Claude to decide whether roles belong in composer steps, addon metadata, or category/item fields.

5. Frontend heuristics:
   - Kiosk wizard may still infer steps by names/groups in places.
   - Ask Claude to identify every remaining heuristic and plan migration to explicit `composer.steps`.

6. Stock:
   - Existing availability/quota is not Stock V2.
   - Ask Claude to choose and justify stockable polymorphic model vs item-only stock.

7. Worktree cleanliness:
   - Repo has many existing modified/untracked files from broader FoodKing work.
   - Ask Claude to audit only the files in this handoff first, then global drift separately.

## 8. Claude Required Output

Claude should return:

- `VERDICT: PASS | NEEDS_FIX | ESCALATE`
- `Validated Points`
- `Critical Findings`
- `Functional Gaps`
- `Gate Decisions To Ask Human`
- `Exact Next Missions`
- `Do Not Touch Yet`
- `Codex Implementation Prompts`

If Claude finds no critical issues in these safe slices, it should still produce the next mission split for the gated schema/stock/composer runtime work.

## 9. Suggested Next Mission Split For Claude

### Mission A - Audit/Fix Safe Slices

Scope:

- product summary tab;
- projection `itemAttributes`;
- kiosk payment wording;
- legacy bundle serving risk.

No migrations and no order services.

### Mission B - Schema ADR

Only after human gate:

- `item_wizard_profiles`;
- `item_wizard_steps`;
- model tests;
- projection tests.

### Mission C - Dashboard Composer Write API

Only after schema + authz gates:

- composer controller/request/resource/service;
- dashboard step editor;
- publish/preview flow.

### Mission D - Stock V2

Only after stock gate:

- stockable model;
- movements;
- branch isolation;
- reconciliation.

### Mission E - Order Integration

Only after frozen order-service unlock:

- atomic decrement;
- release on cancel/refund;
- POS/kiosk symmetry note;
- dispatch after commit.

### Mission F - Full E2E/Hardware

Only after all above:

- dashboard creates composed product;
- POS/kiosk order same quote;
- stock rupture;
- KDS/OSS lifecycle;
- payment/handover;
- final Claude + GPT audit.

## 10. Final Codex Verdict

`PASS_ON_SAFE_SLICES__GLOBAL_MISSION_NOT_COMPLETE__CLAUDE_DEEP_AUDIT_REQUIRED`

Codex advanced the mission without violating gates, but the full user demand is not complete until schema, dashboard write composer, stock, order integration, and E2E/hardware gates are handled.
