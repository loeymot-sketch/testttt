# CODEX VA-SYS-04 — Dashboard Builder UX Hooks

Date: 2026-04-30
Executor: Codex cursor-session

## Verdict

VA_SYS_04_VERDICT: PASS_LOCAL

Dashboard central management is now stable enough for VA-SYS-05 full E2E automation on the software/system scope.

## Scope

Hardware, payment terminal, fiscal printer, and Google Maps live-provider validation remain deferred to final hardware/industrial UAT.

This mission only hardens management UI testability and operator control points:

- Product list/create/edit surface selectors.
- Product photo upload selectors.
- Category list/create/edit/delete surface selectors.
- Variation and extra list/create/edit/delete selectors.
- Addon association create/delete selectors.
- Availability toggle/status selectors.
- Composer profile and step editor selectors.

## Implementation Summary

Added stable `data-testid` hooks to the admin management surfaces needed for a full central flow:

- Product list rows/actions: `admin-items-list`, `admin-item-row-{id}`, `admin-item-view-{id}`, `admin-item-edit-{id}`, `admin-item-delete-{id}`.
- Product create form: `admin-item-form-name`, `admin-item-form-price`, `admin-item-form-category`, `admin-item-form-image`, `admin-item-form-save`.
- Product photo tab: `admin-item-tab-image`, `admin-item-photo-input`, `admin-item-photo-save`.
- Category list/actions/form: `admin-category-list`, `admin-category-row-{id}`, `admin-category-create-open`, `admin-category-view-{id}`, `admin-category-edit-{id}`, `admin-category-delete-{id}`, `admin-category-form-*`.
- Variation and extra forms/actions: `admin-variation-*`, `admin-extra-*`.
- Addon association replacement flow: `admin-addon-add`, `admin-addon-row-{id}`, `admin-addon-delete-{id}`, `admin-addon-form-*`.
- Availability: `admin-availability-toggle-{item.id}`, `admin-availability-status-{item.id}`.
- Composer profile/steps: `admin-composer-root`, `admin-composer-template`, publish/unpublish/draft buttons, step field hooks, step preview hook.

Important design note: addon has no update API in the current backend contract. Modification is represented as delete-and-recreate, so no `admin-addon-edit-{id}` hook is claimed.

## Files Changed

- `resources/js/components/admin/items/ItemListComponent.vue`
- `resources/js/components/admin/items/ItemCreateComponent.vue`
- `resources/js/components/admin/items/ItemShowComponent.vue`
- `resources/js/components/admin/items/variation/ItemVariationListComponent.vue`
- `resources/js/components/admin/items/variation/ItemVariationCreateComponent.vue`
- `resources/js/components/admin/items/extra/ItemExtraListComponent.vue`
- `resources/js/components/admin/items/extra/ItemExtraCreateComponent.vue`
- `resources/js/components/admin/items/addon/ItemAddonListComponent.vue`
- `resources/js/components/admin/items/addon/ItemAddonCreateComponent.vue`
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue`
- `resources/js/components/admin/items/composer/StepEditorComponent.vue`
- `resources/js/components/admin/items/composer/StepPreviewComponent.vue`
- `resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue`
- `resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue`
- `tests/js/productComposerEditor.spec.js`
- `tests/e2e/helpers/central-management-selectors.js`
- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`

## Validation

PASS:

- `npx vitest run tests/js/productComposerEditor.spec.js` — 7 tests passed.
- `npx vitest run tests/js/kioskWizardGenericComposer.spec.js tests/js/posWizardComposerProfile.spec.js` — 8 tests passed.
- `npm run production` — Laravel Mix production build compiled successfully.
- Scoped `git diff --check` — passed.

## Invariants Checked

- Backend pricing SSOT: not touched.
- Order status enum: not touched.
- Branch isolation: not loosened.
- Dispatch after commit: not touched.
- OrderService / FrontendOrderService parity: not touched.
- Frozen zones: not touched.

## Audit

AUDIT_CHANNEL: cursor-session

AUDIT_FALLBACK_REASON: subagent quota was unavailable in the current window; the mission was audited locally against the selector map and build/test results.

AUDIT_VERDICT: PASS

## Next Step

VA-SYS-05 should now run a real browser flow:

1. Create category.
2. Create product with image.
3. Add variation/extra/addon association.
4. Configure composer profile with stockable and non-stockable steps.
5. Publish composer profile.
6. Verify menu projection on kiosk/POS/KDS surfaces.
7. Toggle product and ingredient rupture.
8. Verify dynamic synchronization and no frontend pricing authority.
