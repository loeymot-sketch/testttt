/**
 * Kiosk rupture UX (P2-B) :
 * - stock-unavailable card keeps product visible
 * - rupture badge is visible
 * - add button / card click are blocked (openProduct not called)
 */

import { describe, it, expect, vi } from 'vitest';
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
      kioskFilter: {
        namespaced: true,
        getters: {
          hydrated: () => false,
          activeFilters: () => [],
        },
        actions: {
          init: vi.fn(),
          toggle: vi.fn(),
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

function makeI18n() {
  return createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'fr',
    messages: { fr: frMessages },
  });
}

describe('kioskRuptureUx', () => {
  it('unavailable tile stays visible, displays rupture badge, and does not open wizard', async () => {
    const categories = [{ id: 1, name: 'Tacos', kioskRowKey: 'tacos' }];
    const items = [
      {
        id: 42,
        name: 'Tacos Classique',
        convert_price: 8.5,
        is_available: false,
        unavailable_reason: 'stock_rupture',
      },
    ];

    const store = makeStore({
      categories,
      sidebarCategories: categories,
      selectedCategoryId: 1,
      kioskCatalogItems: items,
      allItems: items,
    });

    const wrapper = mount(KioskCategoriesComponent, {
      global: {
        plugins: [store, makeI18n()],
        mocks: {
          $router: { push: vi.fn(), replace: vi.fn() },
          $route: { query: {}, params: {} },
          showToast: vi.fn(),
        },
        stubs: {
          KioskWizardComponent: true,
          transition: false,
        },
      },
    });

    const card = wrapper.find('[data-testid="kiosk-product-card-42"]');
    const addBtn = wrapper.find('[data-testid="kiosk-product-add-42"]');
    const badge = wrapper.find('[data-testid="kiosk-product-badge-42"]');
    const openProduct = vi.spyOn(wrapper.vm, 'openProduct');

    expect(card.exists()).toBe(true);
    expect(card.attributes('role')).toBe('listitem');
    expect(card.attributes('aria-disabled')).toBe('true');
    expect(card.attributes('tabindex')).toBeUndefined();
    expect(addBtn.attributes('disabled')).toBeDefined();
    expect(badge.exists()).toBe(true);
    expect(badge.text()).toContain('Épuisé');
    expect(card.attributes('title')).toBe('stock_rupture');

    await card.trigger('click');
    await addBtn.trigger('click');
    await wrapper.vm.$nextTick();

    expect(openProduct).not.toHaveBeenCalled();
  });
});
