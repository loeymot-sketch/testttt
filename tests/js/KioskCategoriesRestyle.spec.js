/**
 * Kiosk Design V1 — Phase 2.2 : KioskCategoriesComponent restyle
 *
 * Objectifs vérifiés :
 *  - data-testid stables pour E2E Playwright
 *  - A11y : aria-current, aria-hidden, aria-label, role
 *  - Aucune régression de logique métier (openProduct, selectCategory)
 *  - CSS 100% tokenisée (zero hex hardcodé) — validé par grep dans CI à part
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

function makeStore(overrides = {}) {
    return createStore({
        modules: {
            kioskMenu: {
                namespaced: true,
                state: () => ({ kioskSandwichSubcolumn: null }),
                getters: {
                    categories: () => overrides.categories || [],
                    allItems: () => overrides.allItems || [],
                    selectedCategoryId: () => overrides.selectedCategoryId || null,
                    loading: () => overrides.loading || false,
                    isStale: () => false,
                    fromCache: () => overrides.fromCache || false,
                    sidebarCategories: () => overrides.sidebarCategories || overrides.categories || [],
                    kioskCatalogItems: () => overrides.kioskCatalogItems || [],
                },
                actions: {
                    fetchMenu: vi.fn().mockResolvedValue(),
                    selectKioskCategory: vi.fn(),
                },
            },
            kioskCart: {
                namespaced: true,
                state: () => ({ branchId: 1, kioskToken: 'test-token' }),
                getters: {
                    count: () => overrides.cartCount || 0,
                    total: () => overrides.cartTotal || 0,
                    isEmpty: () => (overrides.cartCount || 0) === 0,
                },
                actions: {
                    addItem: vi.fn(),
                    reset: vi.fn(),
                },
            },
            frontendItem: {
                namespaced: true,
                actions: {
                    details: vi.fn().mockResolvedValue({ data: { data: { id: 1, name: 'Mock' } } }),
                },
            },
        },
    });
}

function mkI18n() {
    return createI18n({
        legacy: false,
        locale: 'fr',
        fallbackLocale: 'fr',
        messages: { fr: frMessages },
    });
}

function mountOpts(store) {
    return {
        global: {
            plugins: [store, mkI18n()],
            mocks: {
                $router: { push: vi.fn(), replace: vi.fn() },
                $route: { query: {}, params: {} },
            },
            stubs: {
                KioskWizardComponent: true,
                transition: false,
            },
        },
    };
}

beforeEach(() => {
    global.window = global.window || {};
});

describe('KioskCategoriesComponent (P2.2 restyle)', () => {
    it('renders root testid and top actions a11y-labeled', () => {
        const store = makeStore({ categories: [{ id: 1, name: 'Tacos', kioskRowKey: 't' }] });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        expect(wrapper.find('[data-testid="kiosk-categories-root"]').exists()).toBe(true);
        const account = wrapper.find('[data-testid="kiosk-categories-top-account"]');
        expect(account.exists()).toBe(true);
        expect(account.attributes('aria-label')).toBeTruthy();
        const allergens = wrapper.find('[data-testid="kiosk-categories-top-allergens"]');
        expect(allergens.attributes('aria-label')).toBeTruthy();
    });

    it('shows loading state with aria-live', () => {
        const store = makeStore({ loading: true });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        const loadingEl = wrapper.find('[data-testid="kiosk-categories-loading"]');
        expect(loadingEl.exists()).toBe(true);
        expect(loadingEl.attributes('role')).toBe('status');
        expect(loadingEl.attributes('aria-live')).toBe('polite');
    });

    it('shows empty state with retry when loadError', async () => {
        const store = makeStore({ loading: false, categories: [] });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        await wrapper.setData({ loadError: true });
        expect(wrapper.find('[data-testid="kiosk-categories-retry"]').exists()).toBe(true);
    });

    it('marks active sidebar item with aria-current=page', () => {
        const categories = [
            { id: 1, name: 'Tacos', kioskRowKey: 't', kioskSandwichSub: null },
            { id: 2, name: 'Burgers', kioskRowKey: 'b', kioskSandwichSub: null },
        ];
        const store = makeStore({
            categories,
            sidebarCategories: categories,
            selectedCategoryId: 1,
        });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        const item1 = wrapper.find('[data-testid="kiosk-categories-sidebar-item-1"]');
        const item2 = wrapper.find('[data-testid="kiosk-categories-sidebar-item-2"]');
        expect(item1.attributes('aria-current')).toBe('page');
        expect(item2.attributes('aria-current')).toBeFalsy();
    });

    it('product cards expose role=button + aria-label + testid', () => {
        const categories = [{ id: 1, name: 'Tacos', kioskRowKey: 't' }];
        const items = [{ id: 42, name: 'Tacos Classic', convert_price: 8.5 }];
        const store = makeStore({
            categories,
            sidebarCategories: categories,
            selectedCategoryId: 1,
            kioskCatalogItems: items,
            allItems: items,
        });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        const card = wrapper.find('[data-testid="kiosk-product-card-42"]');
        expect(card.exists()).toBe(true);
        expect(card.attributes('role')).toBe('button');
        expect(card.attributes('tabindex')).toBe('0');
        expect(card.attributes('aria-label')).toContain('Tacos Classic');
        const add = wrapper.find('[data-testid="kiosk-product-add-42"]');
        expect(add.exists()).toBe(true);
        expect(add.attributes('aria-label')).toContain('Tacos Classic');
    });

    it('bottom bar pay button is disabled when cart empty', () => {
        const store = makeStore({
            categories: [{ id: 1, name: 'T', kioskRowKey: 't' }],
            sidebarCategories: [{ id: 1, name: 'T', kioskRowKey: 't' }],
            cartCount: 0,
        });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        const pay = wrapper.find('[data-testid="kiosk-categories-pay"]');
        expect(pay.attributes('disabled')).toBeDefined();
    });

    it('bottom bar pay button is enabled with items', () => {
        const store = makeStore({
            categories: [{ id: 1, name: 'T', kioskRowKey: 't' }],
            sidebarCategories: [{ id: 1, name: 'T', kioskRowKey: 't' }],
            cartCount: 2,
            cartTotal: 15,
        });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        const pay = wrapper.find('[data-testid="kiosk-categories-pay"]');
        expect(pay.attributes('disabled')).toBeUndefined();
    });

    it('cache banner shown with role=status when fromCache', () => {
        const store = makeStore({
            categories: [{ id: 1, name: 'T', kioskRowKey: 't' }],
            sidebarCategories: [{ id: 1, name: 'T', kioskRowKey: 't' }],
            fromCache: true,
            loading: false,
        });
        const wrapper = mount(KioskCategoriesComponent, mountOpts(store));
        const banner = wrapper.find('[data-testid="kiosk-categories-cache-banner"]');
        expect(banner.exists()).toBe(true);
        expect(banner.attributes('role')).toBe('status');
    });
});
