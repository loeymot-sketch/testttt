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
    },
}));

import CatalogStudioComponent from '../../resources/js/components/admin/items/CatalogStudioComponent.vue';

const categories = [
    { id: 42, name: 'Tacos', product_count: 2 },
    { id: 99, name: 'Burgers', product_count: 1 },
];

const products = [
    {
        id: 7,
        name: 'Tacos M',
        item_category_id: 42,
        category_name: 'Tacos',
        flat_price: '9.90',
        status: 5,
        thumb: '/uploads/tacos.jpg',
        is_available: true,
    },
];

function mountStudio(selectedCategoryId = 42) {
    const dispatch = vi.fn(() => Promise.resolve());
    const resolve = vi.fn((route) => ({
        href: route.name === 'admin.categories.composer'
            ? `/admin/categories/${route.params.id}/composer`
            : `/admin/items/show/${route.params.id}/composer`,
    }));

    const wrapper = mount(CatalogStudioComponent, {
        global: {
            stubs: {
                LoadingComponent: true,
                AvailabilityToggleComponent: true,
                RouterLink: {
                    props: ['to'],
                    template: '<a><slot /></a>',
                },
            },
            mocks: {
                $t: (key, params) => {
                    if (key === 'studio.products_count') {
                        return `${params.n} produits`;
                    }
                    return key;
                },
                $route: { query: {} },
                $router: { resolve, push: vi.fn() },
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

    wrapper.vm.selectedCategoryId = selectedCategoryId;
    return { wrapper, resolve };
}

describe('CatalogStudio category wizard entry', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the category wizard button when a category is selected', async () => {
        const { wrapper } = mountStudio(42);
        await flushPromises();

        expect(wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').text()).toContain('Wizard de la catégorie');
    });

    it('hides the category wizard button on all categories view', async () => {
        const { wrapper } = mountStudio(null);
        await flushPromises();

        expect(wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').exists()).toBe(false);
    });

    it('opens the composer drawer with the selected category route', async () => {
        const { wrapper } = mountStudio(42);
        await flushPromises();

        await wrapper.find('[data-testid="catalog-studio-category-wizard-button"]').trigger('click');

        expect(wrapper.find('[data-testid="catalog-studio-composer-overlay"]').exists()).toBe(true);
        expect(wrapper.vm.composerDrawerUrl).toBe('/admin/categories/42/composer');
        expect(wrapper.text()).toContain("Ce wizard s'applique à TOUS les produits de cette catégorie.");
    });

    it('exposes per-product composer buttons on product cards when catalog compose is allowed', async () => {
        const { wrapper } = mountStudio(42);
        await flushPromises();

        const productWizardButtons = wrapper.findAll('[data-testid^="catalog-studio-product-wizard-"]');
        expect(productWizardButtons.length).toBe(products.length);
    });
});
