// [GOAL CMS C1.4 2026-06-10] Sidebar Studio = arbre 2 niveaux : chaque
// sous-catégorie est rendue immédiatement sous son parent, indentée
// (classe --child). Mirrors the mount harness of
// catalogStudioCategoryDeleteGuard.spec.js.
import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

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

const categories = [
    { id: 1, name: 'Tacos', parent_id: null, product_count: 3 },
    { id: 2, name: 'Sandwich Cayenne', parent_id: null, product_count: 5 },
    // Stored AFTER both tops, must render right under "Tacos".
    { id: 3, name: 'Tacos Signature', parent_id: 1, product_count: 1 },
];

function mountStudio() {
    return mount(CatalogStudioComponent, {
        global: {
            stubs: {
                LoadingComponent: true,
                AvailabilityToggleComponent: true,
                RouterLink: { props: ['to'], template: '<a><slot /></a>' },
            },
            mocks: {
                $t: (key, params) => (params && typeof params.n !== 'undefined' ? `${key}:${params.n}` : key),
                $route: { query: {} },
                $router: { resolve: vi.fn(() => ({ href: '' })), push: vi.fn() },
                $store: {
                    dispatch: vi.fn(() => Promise.resolve({})),
                    getters: {
                        'itemCategory/lists': categories,
                        'item/lists': [],
                        'tax/lists': [],
                        'itemCategory/temp': { temp_id: null, isEditing: false },
                    },
                },
            },
        },
    });
}

describe('CatalogStudioComponent — category tree sidebar', () => {
    it('renders sub-categories immediately under their parent', () => {
        const wrapper = mountStudio();
        const rows = wrapper.findAll('[data-testid^="catalog-studio-category-row-"]');
        const ids = rows.map((row) => row.attributes('data-testid'));

        expect(ids).toEqual([
            'catalog-studio-category-row-1',
            'catalog-studio-category-row-3',
            'catalog-studio-category-row-2',
        ]);
    });

    it('marks sub-category rows with the child modifier class', () => {
        const wrapper = mountStudio();

        expect(
            wrapper.find('[data-testid="catalog-studio-category-row-3"]').classes()
        ).toContain('catalog-studio__category-row--child');
        expect(
            wrapper.find('[data-testid="catalog-studio-category-row-1"]').classes()
        ).not.toContain('catalog-studio__category-row--child');
    });
});
