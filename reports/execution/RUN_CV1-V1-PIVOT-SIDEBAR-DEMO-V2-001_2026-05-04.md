# RUN — CV1-V1-PIVOT-SIDEBAR-DEMO-V2-001 — 2026-05-04

EXECUTE_DELEGATION: foodking-complex-implementer

## Scope Delivered

- Sidebar V1 reduced to four primary admin entries in `BackendMenuComponent.vue`: Stock, Catalogue, Ingrédients, Commandes.
- Wizard per-item Demo V2 preserved but isolated behind `FEATURE_WIZARD_PER_ITEM_DEMO=false` by default.
- Demo V2 frontend access is guarded via `window.foodkingConfig.features.wizard_per_item_demo`.
- Demo launcher is available at `/admin/demo/wizard-launcher` only when the feature flag is true.

## Backend Feature Flag

- Config path: `catalog_v15.features.wizard_per_item_demo.enabled`
- Env flag: `FEATURE_WIZARD_PER_ITEM_DEMO=false`
- Frontend injection pattern: `resources/views/master.blade.php` injects `window.foodkingConfig.features.wizard_per_item_demo` from Laravel `config('catalog_v15.features.wizard_per_item_demo.enabled')`.
- Decision: use existing `window.foodkingConfig` pattern, not a new `window.foodking_config` object.

## Routes Protected

Protected by `wizard.per_item_demo` middleware:

- `GET /api/admin/composer/items/{item}/profile`
- `POST /api/admin/composer/items/{item}/profile`
- `POST /api/admin/composer/items/{item}/apply-template`
- `GET /api/admin/composer/items/{item}/available-sources`

Left open because they are required by the category wizard V1 or shared profile editing flow:

- `GET /api/admin/composer/categories/{category}/profile`
- `POST /api/admin/composer/categories/{category}/profile`
- `POST /api/admin/composer/categories/{category}/apply-template`
- `PUT|PATCH /api/admin/composer/profiles/{profile}`
- `GET /api/admin/composer/profiles/{profile}/diff`
- `POST /api/admin/composer/profiles/{profile}/unpublish`
- `POST /api/admin/composer/profiles/{profile}/steps`
- `PUT|PATCH /api/admin/composer/steps/{step}`
- `DELETE /api/admin/composer/steps/{step}`
- `POST /api/admin/composer/profiles/{profile}/publish`

## Sidebar Hidden Keys

`V1_HIDDEN_MENU_MODULES` now hides:

- `customers`
- `coupons`
- `offers`
- `creditBalanceReport`
- `deliveryBoys`
- `onlineOrders`
- `tableOrders`
- `waiters`
- `diningTables`
- `settings.mail`
- `settings.loyalty-setup`
- `settings.notification`
- `settings.theme`
- `settings.item-categories`
- `settings.item-attributes`
- `settings.permission`
- `settings.role`
- `settings.tax`
- `settings.charge`
- `settings.translation`
- `settings.activity-log`
- `settings.languages`
- `settings.otp`
- `settings.notification-alert`
- `settings.social-media`
- `settings.cookies`
- `settings.analytics`
- `settings.time-slots`
- `settings.sliders`
- `settings.pages`
- `settings.sms-gateway`
- `settings.payment-gateway`
- `settings.license`

`V1_HIDDEN_BACKEND_MENU_URLS` keeps `items` hidden as a parent row so the virtual Catalogue child remains the visible catalogue entry.

## Validation

- `bash .cursor/hooks/safety-check.sh` — PASS.
- `npx vitest run tests/js/sidebarV1Cleanup.spec.js tests/js/demoV2FeatureFlag.spec.js tests/js/v1HiddenMenuModules.spec.js` — PASS, 3 files / 12 tests.
- `php artisan test --filter=WizardPerItemDemoMiddlewareTest` — PASS, 3 tests.
- `npx vitest run` — PASS, 191 files / 1149 passed / 2 skipped.
- `php artisan test` — PASS, 1407 passed / 24 skipped.
- `npm run dev` — PASS, Laravel Mix compiled successfully.

## Limitations / TODO V1.5

- Manual UX screenshot validation remains a Cycle 6 gate item from the master audit; no browser screenshot was produced in this execute step.
- Existing per-item composer Vue code and tests remain preserved for Demo V2; runtime V1 should continue to prefer category wizard resolution.
- Route name for the legacy direct composer URL remains `admin.items.composer` to avoid breaking existing internal links; the route is marked with `meta.v2Demo` and guarded by `beforeEnter`.
