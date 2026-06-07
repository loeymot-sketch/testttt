// [CAT-DEL-01 FIX] Frontend guard behaviour for deleting a populated category.
// Mirrors the mount harness used by catalogStudioCategoryWizardEntry.spec.js.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => true),
        statusClass: vi.fn(() => 'status-active'),
        requestHandler: vi.fn(() => ''),
        destroyConfirmation: vi.fn(() => Promise.resolve()),
    },
}));

vi.mock('../../resources/js/services/alertService', () => ({
    default: {
        successFlip: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
    },
}));

import CatalogStudioComponent from '../../resources/js/components/admin/items/CatalogStudioComponent.vue';
import appService from '../../resources/js/services/appService';
import alertService from '../../resources/js/services/alertService';

const categories = [
    { id: 42, name: 'Tacos', product_count: 3 },
    { id: 99, name: 'Empty', product_count: 0 },
];

// Two active products in category 42 (client-side fallback tally = 2).
const products = [
    { id: 7, name: 'Tacos M', item_category_id: 42, status: 5, is_available: true },
    { id: 8, name: 'Tacos L', item_category_id: 42, status: 5, is_available: true },
];

function mountStudio(dispatch) {
    const wrapper = mount(CatalogStudioComponent, {
        global: {
            stubs: {
                LoadingComponent: true,
                AvailabilityToggleComponent: true,
                RouterLink: { props: ['to'], template: '<a><slot /></a>' },
            },
            mocks: {
                $t: (key, params) => {
                    if (params && typeof params.n !== 'undefined') {
                        return `${key}:${params.n}`;
                    }
                    return key;
                },
                $route: { query: {} },
                $router: { resolve: vi.fn(() => ({ href: '' })), push: vi.fn() },
                $store: {
                    dispatch,
                    getters: {
                        'itemCategory/lists': categories,
                        'item/lists': products,
                        'tax/lists': [],
                    },
                },
            },
        },
    });
    return wrapper;
}

describe('CatalogStudio category delete guard (CAT-DEL-01)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('warns the operator with the affected item count before a populated delete', async () => {
        const dispatch = vi.fn(() => Promise.resolve());
        const wrapper = mountStudio(dispatch);
        await flushPromises();

        wrapper.vm.destroyCategory({ id: 42, name: 'Tacos', product_count: 3 });
        await flushPromises();

        // The N-item warning must fire with the affected count (3 from server).
        expect(alertService.warning).toHaveBeenCalledTimes(1);
        expect(alertService.warning.mock.calls[0][0]).toBe('studio.delete_category_warning:3');
        // Destructive confirm is still required after the warning.
        expect(appService.destroyConfirmation).toHaveBeenCalledTimes(1);
    });

    it('falls back to the client-side item tally when no server count is present', () => {
        const wrapper = mountStudio(vi.fn(() => Promise.resolve()));
        // Category 42 has 2 active products loaded -> tally = 2.
        expect(wrapper.vm.categoryActiveItemCount({ id: 42, name: 'Tacos' })).toBe(2);
    });

    it('does not warn for an empty category', async () => {
        const dispatch = vi.fn(() => Promise.resolve());
        const wrapper = mountStudio(dispatch);
        await flushPromises();

        wrapper.vm.destroyCategory({ id: 99, name: 'Empty', product_count: 0 });
        await flushPromises();

        expect(alertService.warning).not.toHaveBeenCalled();
        expect(appService.destroyConfirmation).toHaveBeenCalledTimes(1);
    });

    it('surfaces the backend 409 guard message when the delete is rejected', async () => {
        const guardMessage = 'La catégorie contient 3 article(s) actif(s).';
        const rejection = Object.assign(new Error(guardMessage), {
            response: { data: { message: guardMessage } },
        });
        // Only the category destroy rejects (mount-time refreshData dispatches resolve).
        const dispatch = vi.fn((action) =>
            action === 'itemCategory/destroy' ? Promise.reject(rejection) : Promise.resolve(),
        );
        const wrapper = mountStudio(dispatch);
        await flushPromises();

        await wrapper.vm.destroyCategory({ id: 42, name: 'Tacos', product_count: 3 });
        await flushPromises();

        // The exact backend guard message reaches the operator (not a generic error).
        expect(alertService.error).toHaveBeenCalledTimes(1);
        expect(alertService.error.mock.calls[0][0]).toBe(guardMessage);
        expect(alertService.successFlip).not.toHaveBeenCalled();
    });
});
