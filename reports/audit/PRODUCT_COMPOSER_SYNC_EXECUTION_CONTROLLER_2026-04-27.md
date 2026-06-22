# Product Composer Sync - Execution Controller - 2026-04-27

TASK_ID: PRODUCT-COMPOSER-SYNC-EXECUTION-CONTROLLER-2026-04-27
MODE: audit, orchestration, sequenced execution
VERDICT: EXECUTE_SAFE_FIRST_SLICE

## 1. Current Reality

The broad user request is not a single safe patch. It contains independent risk tiers:

- already corrected runtime blockers: kiosk lock, noisy connection banner on POS/kiosk, POS walk-in customer, delivery fee V1, active branch selector;
- safe dashboard/catalog visibility work: can proceed without migration or order-service edits;
- schema/stock/order work: must wait for explicit human gates and mission-by-mission execution;
- final release proof: requires browser, Playwright, backend tests, frontend tests, and Claude/GPT audit handoff.

## 2. Gate State

Known approved gates from `docs/gates/GATE_LOG.md`:

- `HG-FROZEN-ORDER-HUNKS-TRAIN-A-2026-04-27`
- `HG-POS-WALKIN-CUSTOMER-V1`
- `HG-DELIVERY-FEE-V1`
- `HG-DASHBOARD-AFTER-TRAIN-A`
- `HG-KIOSK-LOCKED-CUSTOMER-SURFACE`

Still missing for the full Product Composer/Stock V2 plan:

- `HG-COMPOSER-SCHEMA-ADR`
- `HG-STOCK-STOCKABLE-SCOPE`
- `HG-FROZEN-ORDERSERVICE-UNLOCK` for stock decrement/release hunks
- `HG-DASHBOARD-AUTHZ-CATALOG-OPS`
- `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`

No model may self-approve these gates.

## 3. Immediate Execution Decision

Proceed with a first safe implementation slice:

`PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER-LITE`

This slice only adds a dashboard composition/readiness tab based on data already exposed by `ItemResource`:

- category wizard template;
- menu flag;
- image preview path;
- item attributes with min/max/repeat;
- variations grouped by attribute;
- extras grouped by `group_label`;
- addons with linked addon items.

This slice does not:

- add migrations;
- touch `OrderService` or `FrontendOrderService`;
- change pricing;
- create stock decrement behavior;
- alter kiosk/POS order runtime.

## 4. Execution Rules

- Backend remains pricing SSOT.
- The dashboard may display base/additional prices already returned by backend resources, but must not calculate final order price.
- POS and kiosk parity is not claimed by this slice; it only makes existing composition primitives visible and manageable from the product page.
- Any Stock V2 or order-service work remains blocked until the gates above exist.

## 5. Validation Plan

Mandatory for this slice:

- `bash .cursor/hooks/safety-check.sh`
- `npx vitest run tests/js/productComposerSummary.spec.js`
- `git diff --check -- resources/js/components/admin/items/ItemShowComponent.vue resources/js/components/admin/items/ProductComposerSummaryComponent.vue tests/js/productComposerSummary.spec.js reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER.md reports/post_execute_latest.log`

Optional if time permits:

- `npm run production`
- in-app browser check on admin item detail page.

## 6. Audit Loop

After implementation:

- create `reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER.md`;
- append `EXECUTE_DELEGATION: explicit-prompt-bind (human asked Codex to orchestrate/execute with subagents in this session)` to `reports/post_execute_latest.log`;
- record remaining blocked missions explicitly instead of claiming global PASS.

## 7. Current Verdict

VERDICT: PASS_TO_IMPLEMENT_FIRST_SAFE_SLICE
