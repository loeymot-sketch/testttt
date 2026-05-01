# Codex — VA-SYS-01 Dashboard Workflow Discovery — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-01`

## Verdict

`VA_SYS_01_VERDICT: PASS_DISCOVERY_WITH_SELECTOR_REWORK_FOR_VA_SYS_04`

`NEXT_CODEX_MISSION: VA-SYS-02`

VA-SYS-01 is discovery-only. It maps the real dashboard management workflows and records the selector gaps that must be fixed before the full VA-SYS-05 E2E.

## Route Map

| Workflow | Vue route | Component |
| --- | --- | --- |
| Product list | `/admin/items` / `admin.items.list` | `resources/js/components/admin/items/ItemListComponent.vue` |
| Product show/photo/tabs | `/admin/items/show/:id` / `admin.item.show` | `resources/js/components/admin/items/ItemShowComponent.vue` |
| Product composer | `/admin/items/show/:id/composer` / `admin.items.composer` | `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` |
| Category list | `/admin/settings/item-categories/list` / `admin.settings.itemCategory.list` | `resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue` |
| Category show | `/admin/settings/item-categories/show/:id` / `admin.settings.itemCategory.show` | `ItemCategoryShowComponent.vue` |

## API / Store Map

| Workflow | Store action | API |
| --- | --- | --- |
| Product list | `item/lists` | `GET /api/admin/item` |
| Product create | `item/save` | `POST /api/admin/item` |
| Product update | `item/save` while editing | `POST|PUT|PATCH /api/admin/item/{item}` |
| Product delete | `item/destroy` | `DELETE /api/admin/item/{item}` |
| Product show | `item/show` | `GET /api/admin/item/show/{item}` |
| Product photo | `item/changeImage` | `POST /api/admin/item/change-image/{item}` |
| Category list | `itemCategory/lists` | `GET /api/admin/setting/item-category` |
| Category show | `itemCategory/show` | `GET /api/admin/setting/item-category/show/{itemCategory}` |
| Category create/update/delete | `itemCategory/save/destroy` | `POST /api/admin/setting/item-category`, `DELETE /api/admin/setting/item-category/{id}` |
| Category sort | `itemCategory/sortCategory` | `POST /api/admin/setting/item-category/sort/category` |
| Availability toggle | `itemAvailability/toggle` | `POST /api/admin/menu/availability/toggle` |
| Composer load | `composer/show` or direct axios | `GET /api/admin/composer/items/{item}/profile` |
| Composer save | `composer/save` or direct axios | `POST /api/admin/composer/items/{item}/profile` |
| Composer publish | `composer/publish` or direct axios | `POST /api/admin/composer/profiles/{profile}/publish` |
| Composer unpublish | `composer/unpublish` or direct axios | `POST /api/admin/composer/profiles/{profile}/unpublish` |
| Composer step mutate | direct axios/API | `POST /api/admin/composer/profiles/{profile}/steps`, `PUT|PATCH /api/admin/composer/steps/{step}`, `DELETE /api/admin/composer/steps/{step}` |

## Component Map

| Area | Files |
| --- | --- |
| Product shell/list/create/show/upload | `ItemComponent.vue`, `ItemListComponent.vue`, `ItemCreateComponent.vue`, `ItemShowComponent.vue`, `ItemUploadComponent.vue` |
| Product availability | `AvailabilityToggleComponent.vue` |
| Product summary/composer entry | `ProductComposerSummaryComponent.vue` |
| Composer builder | `ProductComposerEditorComponent.vue`, `StepEditorComponent.vue`, `StepPreviewComponent.vue` |
| Variations | `variation/ItemVariationCreateComponent.vue`, `variation/ItemVariationListComponent.vue` |
| Extras | `extra/ItemExtraCreateComponent.vue`, `extra/ItemExtraListComponent.vue` |
| Addons | `addon/ItemAddonCreateComponent.vue`, `addon/ItemAddonListComponent.vue` |
| Categories | `ItemCategoryComponent.vue`, `ItemCateogryListComponent.vue`, `ItemCategoryCreateComponent.vue`, `ItemCategoryShowComponent.vue`, `CategoryUploadComponent.vue` |

## Selector Audit

Current admin surfaces are **not stable enough for a reliable full Playwright E2E**.

Observed current selectors:

- repeated IDs: `#name`, `#price`, `#image`, `#active`;
- generic modals: `#sidebar`, `#categoryModal`, `#modal`, `#extraModal`, `#addonModal`;
- translated button text: `Publier brouillon`, `Publier`, `Depublier`, `Ajouter une etape`;
- icon components without workflow-specific test IDs.

New helper artifact:

- `tests/e2e/helpers/central-management-selectors.js`

It records current route/API maps, fragile selectors, and required stable `data-testid` hooks.

## Required `data-testid` Hooks Before VA-SYS-05

Minimum hooks:

- `admin-items-list`
- `admin-item-row-{id}`
- `admin-item-create-open`
- `admin-item-edit-{id}`
- `admin-item-delete-{id}`
- `admin-item-view-{id}`
- `admin-item-form-name`
- `admin-item-form-price`
- `admin-item-form-category`
- `admin-item-form-image`
- `admin-item-form-save`
- `admin-item-tab-image`
- `admin-item-photo-input`
- `admin-item-photo-save`
- `admin-category-list`
- `admin-category-row-{id}`
- `admin-category-create-open`
- `admin-category-view-{id}`
- `admin-category-edit-{id}`
- `admin-category-delete-{id}`
- `admin-category-form-name`
- `admin-category-form-image`
- `admin-category-form-save`
- `admin-variation-add`
- `admin-variation-row-{id}`
- `admin-variation-edit-{id}`
- `admin-variation-delete-{id}`
- `admin-variation-form-name`
- `admin-variation-form-price`
- `admin-extra-add`
- `admin-extra-row-{id}`
- `admin-extra-edit-{id}`
- `admin-extra-delete-{id}`
- `admin-extra-form-name`
- `admin-extra-form-price`
- `admin-addon-add`
- `admin-addon-row-{id}`
- `admin-addon-edit-{id}`
- `admin-addon-delete-{id}`
- `admin-addon-form-name`
- `admin-addon-form-price`
- `admin-availability-toggle-{itemId}`
- `admin-availability-status-{itemId}`
- `admin-composer-root`
- `admin-composer-template`
- `admin-composer-save-draft`
- `admin-composer-publish`
- `admin-composer-unpublish`
- `admin-composer-add-step`
- `admin-composer-step-{index}-key`
- `admin-composer-step-{index}-label`
- `admin-composer-step-{index}-source-type`
- `admin-composer-step-{index}-source-ref`
- `admin-composer-step-{index}-min`
- `admin-composer-step-{index}-max`
- `admin-composer-step-{index}-addon-role`

## Findings

### P1 — VA-SYS-05 would be flaky if started now

Reason: admin product/category/composer surfaces do not expose stable test hooks. A full E2E would rely on repeated IDs and translated button text.

Action: VA-SYS-04 must add operator-safe UX and stable `data-testid` hooks before VA-SYS-05.

### P1 — Composer builder lacks explicit browser-test contract

The editor can save/publish/unpublish and add steps, but the UI currently exposes minimal semantic hooks and no obvious browser-level validation contract.

Action: VA-SYS-03/04 should lock no-wizard/simple-wizard/complex-wizard behavior and add test hooks.

### P2 — Existing E2E file name is misleading for this scope

`tests/e2e/composer-mega-flow.spec.js` is useful for B9/cash-at-counter, but it is not a Dashboard product/composer CRUD E2E.

Action: VA-SYS-05 must create a new dedicated central-management E2E.

## Validation

- Static route/API/component inspection completed.
- `tests/e2e/helpers/central-management-selectors.js` added and syntax-checked.
- Adversarial P2 cleanup applied: category show route/API, full composer step API prefixes, category action hooks, and variation/extra/addon CRUD hook requirements.
- `node --check tests/e2e/helpers/central-management-selectors.js`: PASS.
- `git diff --check` scoped VA-SYS-01 files: PASS.

## Next Mission Guidance

Proceed to `VA-SYS-02` for backend/API contract hardening while preserving the VA-SYS-01 selector findings for `VA-SYS-04`.

Do **not** start VA-SYS-05 until `VA-SYS-04` adds stable test hooks.
