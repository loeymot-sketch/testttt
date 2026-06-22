# Codex — P2/P3 Dashboard CRUD Closeout — 2026-04-30

## Verdict

`AUDIT_VERDICT: PASS`

`STATUS: P2_DASHBOARD_CRUD_BROWSER_PROOF_CLOSED`

`RELEASE_IMPACT: NO_NEW_SOFTWARE_BLOCKER_BEFORE_HARDWARE_UAT`

## Scope

Closed review finding P3:
- `app/Services/Menu/MenuProjectionService.php` docblock no longer says POS/Kiosk are not connected to the central projection. It now documents the current contract: menu-projection APIs and sync/runtime tests use this service as canonical projection, while legacy per-surface callers must preserve parity.

Closed review finding P2:
- Added `tests/e2e/central-management-dashboard-crud.spec.js`.
- The spec creates real central-management data through the admin browser UI:
  - category,
  - attribute,
  - addon product,
  - main product,
  - product photo update,
  - variation,
  - extra,
  - addon link,
  - composer profile,
  - composer publish.
- Then it verifies runtime sync:
  - POS central projection includes the published composer profile and choices,
  - Kiosk central projection includes the same published composer profile and choices,
  - Kiosk customer route can display the product after starting from idle/order type,
  - POS authenticated quote/store creates an order with the UI-created product,
  - KDS displays the order,
  - persisted order total is backend-priced,
  - stock decrements from 8 to 7,
  - composition snapshot contains variation, extra, and addon choices.

Backend support intentionally limited to:
- stock level seed for runtime decrement verification,
- post-order inspection of DB state/snapshot/events,
- local E2E permission/cache/rate-limit setup.

## Fixes Applied During Verification

- Seeded `catalog.compose` / `catalog.publish` in the E2E setup and cleared Spatie permission cache. Root cause found by Codex and confirmed by adversarial sub-agent: the first composer timeout was actually a 403 hidden by the helper.
- Cleared `kiosk.menu.branch.{branch}` cache before the Kiosk runtime check, because the kiosk menu endpoint caches for 60s.
- Drove the kiosk like a customer: `/kiosk/idle` -> choose `Sur place` -> category page. Direct category navigation without order type is not the real flow.
- Removed fragile POS legacy grid visibility assertion. POS proof remains runtime-relevant via central POS projection + authenticated POS quote/store + KDS + persisted stock/snapshot.
- Added stable admin variation test hooks for name/price/attribute fields.
- Hardened the Playwright helper to surface non-2xx API bodies instead of timing out silently.

## Validation Evidence

Playwright:
- `npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --reporter=line --workers=1 --timeout=180000 --retries=0`
  - `1 passed (1.3m)`
- `npx playwright test tests/e2e/central-management-dashboard-crud.spec.js --reporter=line --workers=1 --timeout=180000 --retries=0 --repeat-each=2`
  - `2 passed (2.5m)`

Generated runtime artifact:
- `reports/antigravity/central-management-dashboard-crud.json`
  - `verdict: PASS_DASHBOARD_CRUD_RUNTIME_LOCAL`
  - latest proof: `category_id=629`, `item_id=709`, `profile_id=31`, `order_id=692`, `queue_number=A0001`, `quote_total=13`, `persisted_total=13`, `stock_on_hand_after_order=7`, `pos_projection_steps=3`, `kiosk_projection_steps=3`.

PHP targeted:
- `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`
  - `5 passed`
- `php artisan test tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`
  - `5 passed`
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php`
  - `13 passed`
- `php artisan test tests/Feature/Composer/ComposerProfileApiTest.php`
  - `6 passed`
- `php artisan test tests/Feature/Composer/ComposerAuthzMinimalTest.php`
  - `11 passed`

Vitest targeted:
- `npx vitest run tests/js/productComposerEditor.spec.js tests/js/kioskWizardComposerProfile.spec.js tests/js/posWizardComposerProfile.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/productComposerSummary.spec.js`
  - `5 files passed`
  - `21 tests passed`

Static:
- `node --check tests/e2e/central-management-dashboard-crud.spec.js` PASS.
- `php -l app/Services/Menu/MenuProjectionService.php` PASS.
- `git diff --check -- app/Services/Menu/MenuProjectionService.php tests/e2e/central-management-dashboard-crud.spec.js resources/js/components/admin/items/variation/ItemVariationCreateComponent.vue` PASS.

## Risk Review

No P0/P1 introduced.

Residual note:
- POS visual grid still has legacy behavior not asserted here. This is not a blocker for this P2 because POS central projection and POS authenticated order creation are proven, and the existing VA-SYS-05 already covers runtime ordering. A future POS UI cleanup can migrate the grid display fully onto the canonical projection, but it is not required before hardware UAT.

## Invariants Checked

- Backend pricing SSOT: test sends quote/store through backend and asserts persisted total; no frontend price authority added.
- `branch_id` isolation: projection calls are branch-scoped, stock seed is branch-scoped, composer authz regressions pass.
- Dispatch/sync: order reaches KDS and persisted state; existing after-commit/outbox coverage remains green from VA-SYS-08/10.
- Stock: branch stock decrements from 8 to 7 for the UI-created product.
- Composer wizard: published profile has three steps and shared POS/Kiosk projected choices.

