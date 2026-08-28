import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';

import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

function makeStore() {
    const categories = [{ id: 1, name: 'Tacos', kioskRowKey: 't' }];
    const items = [{ id: 42, name: 'Tacos Classic', convert_price: 8.5 }];
    return createStore({
        modules: {
            kioskMenu: {
                namespaced: true,
                state: () => ({ kioskSandwichSubcolumn: null }),
                getters: {
                    categories: () => categories,
                    allItems: () => items,
                    selectedCategoryId: () => 1,
                    loading: () => false,
                    isStale: () => false,
                    fromCache: () => false,
                    sidebarCategories: () => categories,
                    kioskCatalogItems: () => items,
                },
                actions: { fetchMenu: vi.fn().mockResolvedValue(), selectKioskCategory: vi.fn() },
            },
            kioskCart: {
                namespaced: true,
                state: () => ({ branchId: 1, kioskToken: 'test-token' }),
                getters: { count: () => 0, total: () => 0, isEmpty: () => true },
                actions: { addItem: vi.fn(), reset: vi.fn() },
            },
            frontendItem: {
                namespaced: true,
                actions: { details: vi.fn().mockResolvedValue({ data: { data: items[0] } }) },
            },
        },
    });
}

describe('Carte produit borne — parité clavier', () => {
    it('active le même parcours au clic, à Entrée et à Espace', async () => {
        const wrapper = mount(KioskCategoriesComponent, {
            global: {
                plugins: [
                    makeStore(),
                    createI18n({ legacy: false, locale: 'fr', messages: { fr: frMessages } }),
                ],
                mocks: {
                    $router: { push: vi.fn(), replace: vi.fn() },
                    $route: { query: {}, params: {} },
                },
                stubs: { KioskWizardComponent: true, transition: false },
            },
        });
        const openProduct = vi.spyOn(wrapper.vm, 'openProduct').mockImplementation(() => {});
        const action = wrapper.get('[data-testid="kiosk-product-add-42"]');

        expect(action.element.tagName).toBe('BUTTON');
        expect(action.attributes('type')).toBe('button');
        expect(action.attributes('aria-label')).toContain('Tacos Classic');
        expect(action.attributes('aria-describedby')).toBe('kiosk-product-meta-42');
        expect(wrapper.get('#kiosk-product-meta-42').text()).toContain('Tacos Classic');

        await action.trigger('click');
        await action.trigger('keydown', { key: 'Enter' });
        await action.trigger('keydown', { key: ' ' });

        expect(openProduct).toHaveBeenCalledTimes(3);
        wrapper.unmount();
    });
});
