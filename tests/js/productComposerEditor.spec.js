import { describe, expect, it } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = process.cwd();

function read(file) {
    return fs.readFileSync(path.join(root, file), 'utf8');
}

describe('product composer editor contract', () => {
    it('keeps editor payload price-free and calls composer APIs', () => {
        const source = read('resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue');

        // Relative to axios baseURL (.../api) — no leading slash (avoids /api + /admin → wrong host path).
        expect(source).toContain('admin/composer/items/${this.resolvedEntityId}/profile');
        expect(source).toContain('admin/composer/categories/${this.resolvedEntityId}/profile');
        expect(source).toContain('admin/composer/profiles/${this.profile.id}/publish');
        expect(source).not.toContain('v-model="form.price"');

        // NF525 invariant — refined 2026-06-08 (GOAL_WIZARD_DYNAMIC_BUILDER W5 "page
        // personnalisée"). The blanket not.toContain('price:') was a coarse proxy for
        // "no price on the wizard step/profile". The personal-page builder LEGITIMATELY
        // carries a PER-OPTION price (`price: 0` row default → ItemExtra.price catalog
        // SSOT), which is the whole point of the feature and is NF525-correct. The real
        // invariants that must hold:
        //   - the wizard-step payload builders (payloadForStep / profilePayload) stay
        //     price-free — a step never carries a price;
        //   - the personal-page POST has no top-level/page-level price key.
        const stepPayloadBuilder = source.slice(
            source.indexOf('payloadForStep(step)'),
            source.indexOf('async saveDraft()'),
        );
        expect(stepPayloadBuilder.length).toBeGreaterThan(0);
        expect(stepPayloadBuilder).not.toContain('price');

        const personalPagePayloadBuilder = source.slice(
            source.indexOf('personalPagePayload()'),
            source.indexOf('async submitPersonalPage()'),
        );
        expect(personalPagePayloadBuilder.length).toBeGreaterThan(0);
        // No page-level/total price.
        expect(personalPagePayloadBuilder).not.toContain('total');
        // `price` appears ONLY inside the per-option .map() — never at the payload top level.
        const optionsMap = personalPagePayloadBuilder.slice(
            personalPagePayloadBuilder.indexOf('.options.map('),
            personalPagePayloadBuilder.indexOf('return {'),
        );
        const payloadReturn = personalPagePayloadBuilder.slice(
            personalPagePayloadBuilder.indexOf('return {'),
        );
        expect(optionsMap).toContain('price:'); // per-option price IS present (NF525 SSOT on the construct)
        expect(payloadReturn).not.toContain('price'); // ...but the returned page payload has NO price key
    });

    it('defines a dedicated composer route guarded by catalog.compose', () => {
        const source = read('resources/js/router/modules/itemRoutes.js');
        const routerIndex = read('resources/js/router/index.js');

        expect(source).toContain("name: 'admin.items.composer'");
        expect(source).toContain("permissionUrl: 'catalog.compose'");
        expect(source).toContain('ProductComposerEditorComponent');
        expect(source).toContain('requireWizardPerItemDemo');
        expect(routerIndex).toContain('import itemRoutes from "./modules/itemRoutes"');
        expect(routerIndex).toContain('itemRoutes,');
    });

    it('exposes the composer editor from the product composition tab', () => {
        const source = read('resources/js/components/admin/items/ProductComposerSummaryComponent.vue');

        expect(source).toContain("name: 'admin.items.composer'");
        expect(source).toContain('Configurer');
    });

    it('store module uses composer endpoints without pricing fields', () => {
        const source = read('resources/js/store/modules/composer.js');

        expect(source).toContain('admin/composer/items/${itemId}/profile');
        expect(source).toContain('admin/composer/profiles/${profileId}/publish');
        expect(source).not.toContain('delivery_charge');
        expect(source).not.toContain('total');
    });

    it('exposes stable composer management selectors for VA-SYS-05 E2E', () => {
        const editor = read('resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue');
        const stepEditor = read('resources/js/components/admin/items/composer/StepEditorComponent.vue');
        const preview = read('resources/js/components/admin/items/composer/StepPreviewComponent.vue');

        [
            'admin-composer-root',
            'admin-composer-template',
            'admin-composer-branch-scope',
            'admin-composer-save-draft',
            'admin-composer-publish',
            'admin-composer-unpublish',
            'admin-composer-add-step',
        ].forEach((testId) => expect(editor).toContain(testId));

        [
            'admin-composer-step-${index}',
            'admin-composer-step-${index}-key',
            'admin-composer-step-${index}-label',
            'admin-composer-step-${index}-source-type',
            'admin-composer-step-${index}-source-ref',
            'admin-composer-step-${index}-min',
            'admin-composer-step-${index}-max',
            'admin-composer-step-${index}-addon-role',
        ].forEach((testId) => expect(stepEditor).toContain(testId));

        expect(preview).toContain('admin-composer-step-${index}-preview');
    });

    it('exposes stable product and category management selectors for central sync E2E', () => {
        const itemList = read('resources/js/components/admin/items/ItemListComponent.vue');
        const itemCreate = read('resources/js/components/admin/items/ItemCreateComponent.vue');
        const itemShow = read('resources/js/components/admin/items/ItemShowComponent.vue');
        const categoryList = read('resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue');
        const categoryCreate = read('resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue');

        [
            'admin-items-list',
            'admin-item-row-${item.id}',
            'admin-item-create-open',
            'admin-item-edit-${item.id}',
            'admin-item-delete-${item.id}',
            'admin-item-view-${item.id}',
            // M1 P3 2026-05-21: inline availability toggle removed from item list
            // (consolidated into /admin/stock/rupture V2 page per owner spec).
        ].forEach((testId) => expect(itemList).toContain(testId));

        [
            'admin-item-form-name',
            'admin-item-form-price',
            'admin-item-form-category',
            'admin-item-form-image',
            'admin-item-form-save',
        ].forEach((testId) => expect(itemCreate).toContain(testId));

        [
            'admin-item-tab-image',
            'admin-item-photo-input',
            'admin-item-photo-save',
        ].forEach((testId) => expect(itemShow).toContain(testId));

        [
            'admin-category-list',
            'admin-category-row-${itemCategory.id}',
            'admin-category-create-open',
            'admin-category-view-${itemCategory.id}',
            'admin-category-edit-${itemCategory.id}',
            'admin-category-delete-${itemCategory.id}',
        ].forEach((testId) => expect(categoryList).toContain(testId));

        [
            'admin-category-form-name',
            'admin-category-form-image',
            'admin-category-form-save',
        ].forEach((testId) => expect(categoryCreate).toContain(testId));
    });

    it('exposes stable modifier management selectors for VA-SYS-05 E2E', () => {
        const variationList = read('resources/js/components/admin/items/variation/ItemVariationListComponent.vue');
        const variationCreate = read('resources/js/components/admin/items/variation/ItemVariationCreateComponent.vue');
        const extraList = read('resources/js/components/admin/items/extra/ItemExtraListComponent.vue');
        const extraCreate = read('resources/js/components/admin/items/extra/ItemExtraCreateComponent.vue');
        const addonList = read('resources/js/components/admin/items/addon/ItemAddonListComponent.vue');
        const addonCreate = read('resources/js/components/admin/items/addon/ItemAddonCreateComponent.vue');

        [
            'admin-variation-add',
            'admin-variation-row-${child.id}',
            'admin-variation-edit-${child.id}',
            'admin-variation-delete-${child.id}',
        ].forEach((testId) => expect(variationList).toContain(testId));
        // [Wizard builder W3] construct forms author per-option description + image (catalog metadata; price stays SSOT).
        ['admin-variation-form-name', 'admin-variation-form-price', 'admin-variation-form-description', 'admin-variation-form-image'].forEach((testId) => expect(variationCreate).toContain(testId));

        [
            'admin-extra-add',
            'admin-extra-row-${extra.id}',
            'admin-extra-edit-${extra.id}',
            'admin-extra-delete-${extra.id}',
        ].forEach((testId) => expect(extraList).toContain(testId));
        ['admin-extra-form-name', 'admin-extra-form-price', 'admin-extra-form-description', 'admin-extra-form-image'].forEach((testId) => expect(extraCreate).toContain(testId));

        [
            'admin-addon-add',
            'admin-addon-row-${addon.id}',
            'admin-addon-delete-${addon.id}',
        ].forEach((testId) => expect(addonList).toContain(testId));
        ['admin-addon-form-name', 'admin-addon-form-price'].forEach((testId) => expect(addonCreate).toContain(testId));
    });
});
