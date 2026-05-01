# Claude Handoff - Product Composer / Catalogue / Stock / Kiosk-POS Sync Final Audit

Date: 2026-04-27
Prepared by: Codex audit-only pass
Requested next reviewer: Claude terminal / Claude orchestration

## 0. Verdict Codex

VERDICT: REWORK_REQUIRED_BEFORE_GLOBAL_PASS

Codex did not complete the full Product Composer + stock + central dashboard + device sync mission. Codex delivered safe, tested slices and one targeted correction, but the global system requested by the user is still not production-complete.

The safe slices are useful and validated:

- dashboard item detail now has a read-only `Composition` tab;
- kiosk/canonical menu projections now expose `itemAttributes` selection constraints;
- POS delivery fee was corrected to the user rule: 5 EUR per started 5 km tranche.

But the full mission still needs Claude orchestration because schema, stock, order-service integration, write UI, runtime wizard consumption, authz, and E2E/hardware gates are not complete.

## 1. Mandatory Reading Order For Claude

Read these before planning corrections:

1. `AGENTS.md`
2. `.cursor/ACTIVE_CYCLE.md`
3. `reports/audit/PRODUCT_COMPOSER_SYNC_DEEP_AUDIT_ORCHESTRATION_2026-04-27.md`
4. `reports/audit/PRODUCT_COMPOSER_SYNC_CONTINUATION_REPORT_2026-04-27.md`
5. `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-01A-GATE-BRIEFS.md`
6. `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE.md`
7. `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE.md`
8. `missions/PRODUCT-COMPOSER-SYNC-01A-GATE-BRIEFS/execute_brief.md`
9. `missions/PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE/execute_brief.md`
10. `missions/PRODUCT-COMPOSER-SYNC-03A-PROJECTION-CONSTRAINTS-LITE/execute_brief.md`

Claude must treat code and tests as truth over conversation memory.

## 2. What Codex Actually Delivered

### 2.1 Pending Human Gate Briefs

Created gate briefs, all still pending and not self-approved:

- `docs/gates/GATE_PRODUCT_COMPOSER_SCHEMA_2026-04-27.md`
- `docs/gates/GATE_STOCK_STOCKABLE_SCOPE_2026-04-27.md`
- `docs/gates/GATE_DASHBOARD_AUTHZ_CATALOG_OPS_2026-04-27.md`
- `docs/gates/GATE_FROZEN_ORDERSERVICE_UNLOCK_PRODUCT_COMPOSER_STOCK_2026-04-27.md`
- `docs/gates/GATE_E2E_HARDWARE_COMPOSER_SIGNOFF_2026-04-27.md`

These gates block migrations, stock V2, order-service decrement/release, dashboard write APIs, and final release signoff.

### 2.2 Dashboard Composition Summary

Files:

- `resources/js/components/admin/items/ProductComposerSummaryComponent.vue`
- `resources/js/components/admin/items/ItemShowComponent.vue`
- `tests/js/productComposerSummary.spec.js`

Behavior delivered:

- item detail page has a sixth tab: `Composition`;
- the tab consolidates existing catalogue bricks: product, category, source price display, photo state, wizard template, menu flag, item attributes, variations, extras, addons;
- it explicitly states that final price is backend/PricingService-authoritative;
- it is read-only, so it does not mutate catalogue state.

Important limitation:

- this is not the full Product Builder requested by the user;
- it does not create wizard profiles, wizard step ordering, stockable links, semantic addon roles, or a one-screen write workflow.

### 2.3 POS/Kiosk Projection Constraints

Files:

- `app/Services/Kiosk/KioskMenuService.php`
- `app/Services/Menu/MenuProjectionService.php`
- `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`

Behavior delivered:

- kiosk legacy menu payload includes `itemAttributes`;
- canonical POS/kiosk projection includes `itemAttributes`;
- projected attributes include `id`, `name`, `status`, `min_select`, `max_select`, `allow_repeat`;
- parity tests confirm POS/kiosk shared fields and kiosk legacy/canonical attribute ids.

Important limitation:

- frontend kiosk wizard still has legacy heuristic paths and must be audited/migrated to consume these constraints as runtime rules;
- exposing constraints is only the backend projection foundation, not the final composer runtime.

### 2.4 Delivery Fee Correction

Files:

- `app/Services/Delivery/DeliveryFeeService.php`
- `tests/Unit/Services/DeliveryFeeServiceTest.php`
- `tests/Feature/PosWalkInAndDeliveryFeeTest.php`

Problem found during this final audit:

- existing delivery fee logic charged effectively 1 EUR per km after 5 km;
- example before correction: `5.01 km => 6 EUR`;
- user requirement says 5 EUR per 5 km tranche.

Correction applied:

- fee is now `max(5, ceil(distance_km / 5) * 5)`;
- examples now covered by tests:
  - `0 km => 5 EUR`
  - `5 km => 5 EUR`
  - `5.01 km => 10 EUR`
  - `10 km => 10 EUR`
  - `10.01 km => 15 EUR`
  - invalid/negative distance => `0 EUR`

Claude must still audit if `0 km => 5 EUR` is business-approved. Codex preserved the previous minimum-fee behavior.

## 3. User-Reported Runtime Blockers Audit

### 3.1 Kiosk admin/caisse escape paths

Current findings:

- `/kiosk/payment` returned HTTP 200.
- `resources/js/router/modules/kioskRoutes.js` redirects route `admin` to `kiosk.idle`.
- `app/Http/Resources/SettingResource.php` returns `'kiosk_admin_pin' => null`.
- current payment component has no admin/caisse navigation in its template.

Remaining risk:

- `resources/js/components/frontend/kiosk/KioskAdminComponent.vue` still exists in the source tree.
- legacy public bundles may still contain older admin code depending on build/release hygiene.
- Claude must decide whether the source component is acceptable as dead code or must be removed/gated in a dedicated mission.

### 3.2 Kiosk payment screen

Observed current design:

- payment screen has a `Retour` button back to cart;
- payment methods include `Carte bancaire`, `Especes`, and `Titre restaurant`;
- cash path text says payment at caisse/counter.

This is not an admin/caisse escape, but Claude must ask the business/product question:

- is `Retour` acceptable as customer navigation inside a locked kiosk flow?
- is `Paiement a la caisse` acceptable on the kiosk, or should kiosk forbid cash/counter mode on some deployments?

Do not silently remove these without business decision; they are customer UX/payment policy, not only technical cleanup.

### 3.3 POS walk-in customer

Current findings:

- POS quote/store path normalizes missing `customer_id` via `WalkInCustomerResolver`.
- targeted test passes: `PosWalkInAndDeliveryFeeTest`.

Remaining risk:

- Claude should audit actual POS store path, not only quote, for all payment methods/order types.
- Confirm no UI still blocks order submission before backend normalization.

### 3.4 Delivery and Google Maps

Current findings:

- backend recomputes `delivery_charge` from `delivery_distance_km` in `PosOrderRequest` and `PosController`.
- delivery fee correction was applied.

Remaining risks:

- frontend POS still imports `calculateDeliveryChargeFromDistance`; this is acceptable only as UI preview if backend quote/store remains authoritative.
- Claude must audit Google Maps distance calculation path: if Maps/geocode fails, current fallbacks may still set distance `0` and fee `5 EUR`.
- Claude must decide if failed geocode should block delivery quote, require manual distance, or allow minimum fee.

## 4. Validations Run In This Audit

PASS:

- `npx vitest run tests/js/productComposerSummary.spec.js tests/js/userReportedBlockersRuntime.spec.js`
  - 2 files passed
  - 6 tests passed
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`
  - 4 tests passed
- `php artisan test tests/Feature/ItemAttributeComposerResourceTest.php`
  - 2 tests passed
- `php artisan test tests/Unit/Services/DeliveryFeeServiceTest.php`
  - 1 test passed
- `php artisan test tests/Feature/PosWalkInAndDeliveryFeeTest.php`
  - 1 test passed
- `php -l app/Services/Delivery/DeliveryFeeService.php`
  - PASS
- `php -l tests/Unit/Services/DeliveryFeeServiceTest.php`
  - PASS
- `php -l tests/Feature/PosWalkInAndDeliveryFeeTest.php`
  - PASS

Previously passed in the same implementation sequence:

- safety-check: PASS
- targeted diff-checks: PASS
- `npm run production`: PASS after Laravel Mix compilation; notification workers were stopped after success.

## 5. Known Incomplete Areas

Claude must not mark the global mission PASS until these are handled or explicitly rejected by human gate.

### 5.1 Product Composer schema is missing

Still missing:

- `item_wizard_profiles`;
- `item_wizard_steps`;
- normalized wizard step order;
- per-category default templates;
- per-item overrides;
- branch/channel visibility rules for composer steps.

### 5.2 Product Composer write workflow is missing

Still missing:

- one dashboard workflow to create/edit a full product composition;
- choose category template;
- choose product image;
- configure variation groups and constraints;
- configure extras by role/group;
- configure addons/menu components;
- preview POS/kiosk resulting wizard;
- publish with version bump.

Current dashboard work is read-only only.

### 5.3 Kiosk/POS runtime wizard consumption is incomplete

Still missing:

- make the customer/staff wizard consume composer profiles/steps as authority;
- remove or fence heuristic fallbacks for tacos/sandwich/plate when real profile exists;
- ensure POS and kiosk use same composition payload without frontend price authority.

### 5.4 Stock V2 is missing

Still missing:

- stock levels for stockable entities;
- stock movements append-only log;
- atomic decrement inside order transaction;
- idempotent release on cancel/reject/refund;
- branch isolation tests;
- rupture UI for kiosk/POS;
- stock realtime propagation.

### 5.5 OrderService / FrontendOrderService integration is blocked

No stock decrement/release was added to `OrderService` or `FrontendOrderService`.

Reason:

- frozen/order-service gate is pending;
- parity between the two services is mandatory;
- patching only one path would violate FoodKing invariants.

### 5.6 Catalog sync/eventing is still partial

Still missing:

- explicit catalog event contract for all category/product/variation/extra/addon mutations;
- version bump coverage for all catalog mutations;
- POS/kiosk live refresh tests for create/edit/delete/photo changes;
- dashboard connected-device version status.

### 5.7 Photos are not fully audited end-to-end

Known current state:

- existing item detail image tab can upload/save product image;
- composition summary reports whether image exists.

Still missing:

- prove that uploaded photo invalidates kiosk menu cache;
- prove kiosk tile changes without manual rebuild/refresh;
- prove image deletion/reset behavior;
- prove category image and product image behavior for POS vs kiosk.

### 5.8 Addon semantic roles are missing

Still missing:

- role enum/field for addons such as `drink`, `side`, `dessert`, `menu_component`, `upsell`;
- dashboard UX to show these roles clearly;
- runtime mapping to offer/menu display.

### 5.9 E2E and hardware signoff are missing

No full E2E proof yet for:

- admin creates category/product/composition/photo;
- kiosk sees it;
- POS sees it;
- customer/staff creates order;
- stock decrements;
- rupture appears;
- KDS receives ticket;
- OSS/client status updates;
- handover/finish path closes lifecycle.

No hardware signoff for:

- real kiosk;
- real TPE;
- real printer;
- real KDS display;
- real branch config.

## 6. Required Claude Orchestration

Claude should produce a correction mega-plan with strict bounded missions. Recommended sequence:

### Mission A - Audit Current Safe Slices

Goal:

- audit 02A/03A/delivery fix for regressions;
- verify no pricing frontend authority was introduced;
- verify branch isolation remains intact;
- verify no frozen zone was edited without gate.

Expected verdict:

- PASS for safe slices, or REWORK with minimal correction hunks.

### Mission B - Product Composer ADR / Schema

Precondition:

- human approves `HG-COMPOSER-SCHEMA-ADR`.

Required output:

- final schema decision;
- migrations;
- models/relations;
- tests for item/category/channel/branch scope;
- rollback and dry-run plan.

### Mission C - Dashboard Product Builder Write Flow

Precondition:

- schema and authz gates approved.

Required output:

- one real product builder screen;
- CRUD for product composition;
- photo upload/edit/delete proof;
- server-side validation;
- no frontend final price logic.

### Mission D - Runtime Wizard Migration

Required output:

- POS/kiosk wizard reads composer profiles/steps;
- category defaults and item overrides work;
- old heuristics only fallback when no composer profile exists;
- tests for tacos/sandwich/plate/assiette/offre.

### Mission E - Stock V2

Precondition:

- `HG-STOCK-STOCKABLE-SCOPE`;
- `HG-FROZEN-ORDERSERVICE-UNLOCK`.

Required output:

- stock model;
- atomic decrement;
- release on cancel/reject/refund;
- branch isolation;
- rupture UI;
- realtime propagation;
- parity between `OrderService` and `FrontendOrderService`.

### Mission F - Catalog Eventing / Sync

Required output:

- mutation events for category/product/variation/extra/addon/photo;
- menu version bumps;
- kiosk/POS refresh or diff fetch;
- tests for create/update/delete/photo propagation.

### Mission G - Delivery / Google Maps Hardening

Required output:

- decide policy for failed geocode;
- ensure backend quote/store always authoritative;
- ensure frontend helper cannot be trusted for final price;
- add tests for 0 km, 0.1 km, 5 km, 5.01 km, 10.01 km, failed geocode, forged delivery charge.

### Mission H - Kiosk Lockdown Release Audit

Required output:

- decide whether `KioskAdminComponent.vue` is dead code or must be removed;
- scan built bundles for admin/maintenance escape paths;
- verify `/kiosk/admin` redirects to idle;
- verify no visible logo/admin/go-to-caisse link on customer kiosk screens;
- decide whether `Retour` and cash/counter payment are acceptable.

### Mission I - E2E / Hardware

Precondition:

- all code missions green.

Required output:

- Playwright full flow;
- hardware UAT checklist;
- final Claude verdict;
- final Codex audit after Claude.

## 7. Non-Negotiable Invariants For Claude

Do not approve a plan that violates:

- backend is pricing SSOT;
- no frontend final price computation;
- `OrderStatus` enum only, no magic status strings;
- strict `branch_id` isolation;
- dispatch/events after DB commit;
- no order-service edits without symmetry note for `OrderService` and `FrontendOrderService`;
- no migrations/frozen zones without human gate;
- no global PASS without E2E/hardware signoff or explicit human deferral.

## 8. Final Request To Claude

Claude, be strict.

Do not accept the current state as globally complete. The delivered Codex work is a foundation, not the final system. Your job is to audit it, identify any incorrect logic, then produce bounded correction missions with allowlists, tests, gates, and PASS/REWORK criteria.

Required final Claude output:

- `AUDIT_VERDICT: PASS|REWORK|ESCALATE`
- detailed defects with file references;
- mission-by-mission correction plan;
- explicit gate list;
- explicit test matrix;
- clear statement whether Codex can continue implementation or whether human decisions are required first.
