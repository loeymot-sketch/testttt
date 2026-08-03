# PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE

## Intent

Deliver the first safe dashboard slice of the Product Composer work without claiming the full `02-DASHBOARD-COMPOSER` mission is complete.

## Scope

This mission adds a read-only composition summary tab to the product detail dashboard using already exposed backend resource fields:

- category wizard template;
- menu flag;
- backend-provided base price;
- image state;
- item attributes with `min_select`, `max_select`, `allow_repeat`;
- variations grouped by attribute;
- extras grouped by `group_label`;
- addons linked to the product.

## Non-goals

- No migrations.
- No new database schema.
- No `OrderService` or `FrontendOrderService` edits.
- No stock decrement or release logic.
- No POS/kiosk runtime wizard migration.
- No frontend final price calculation.

## Why This Exists

The full mission `PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER` depends on `01-SCHEMA-ADR` and human gates. This lite slice is allowed because it only surfaces existing data in the dashboard and creates an operator-visible control point for the next mission.

## Validation

- `bash .cursor/hooks/safety-check.sh`
- `npx vitest run tests/js/productComposerSummary.spec.js`
- `git diff --check -- resources/js/components/admin/items/ItemShowComponent.vue resources/js/components/admin/items/ProductComposerSummaryComponent.vue tests/js/productComposerSummary.spec.js reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-02A-DASHBOARD-COMPOSER-LITE.md reports/post_execute_latest.log`

## Exit Criteria

- Product detail has a `Composition` tab.
- The tab clearly states final pricing remains backend/PricingService-authoritative.
- Existing variations, extras, addons, and selection constraints are visible in one place.
- Full schema/stock/runtime work remains explicitly blocked and not claimed as done.
