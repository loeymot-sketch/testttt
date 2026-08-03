/**
 * W6.A — Sentinelles axe-core sur flux kiosk (Vitest + happy-dom).
 * Les règles dépendantes d’un moteur de rendu complet (ex. color-contrast)
 * sont désactivées ici : faux négatifs fréquents sous jsdom/happy-dom.
 */
import { describe, it, expect, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import axe from 'axe-core';

import KioskAppComponent from '../../resources/js/components/frontend/kiosk/KioskAppComponent.vue';
import KioskToastComponent from '../../resources/js/components/frontend/kiosk/KioskToastComponent.vue';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import KsModal from '../../resources/js/components/frontend/kiosk/ds/KsModal.vue';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

/** @param {HTMLElement} el */
async function runAxe(el, options = {}) {
  return axe.run(el, {
    rules: {
      'color-contrast': { enabled: false },
    },
    ...options,
  });
}

/** Écarts catalogue grille produits (role=list + carte role=button + CTA) — hors périmètre W6.A EXECUTE. */
const AXE_ALLOW_IDS_CATEGORIES = new Set([
  'aria-allowed-role',
  'aria-required-children',
  'nested-interactive',
]);

function expectAxeClean(result, allowedRuleIds = null) {
  const allow = allowedRuleIds || new Set();
  const bad = result.violations.filter((v) => !allow.has(v.id));
  expect(bad).toEqual([]);
}

const wizardStubs = Object.fromEntries(
  [
    'KioskStepPain',
    'KioskStepTaille',
    'KioskStepViande',
    'KioskStepSauce',
    'KioskStepGarnitures',
    'KioskStepSupplements',
    'KioskStepMenu',
    'KioskOrderSummary',
    'KsAllergenBadge',
  ].map((n) => [n, true])
);

function makeKioskAppStore() {
  return createStore({
    modules: {
      kioskSettings,
      kioskCart: {
        namespaced: true,
        state: () => ({
          items: [
            {
              quantity: 2,
              convert_price: 5,
              item_variation_total: 0,
              item_extra_total: 0,
            },
          ],
        }),
        getters: {
          count: (s) => s.items.reduce((a, i) => a + i.quantity, 0),
          total: () => 12.5,
        },
        actions: {
          reset: vi.fn(),
          setBranch: vi.fn(),
        },
      },
      frontendBranch: {
        namespaced: true,
        actions: {
          lists: vi.fn().mockResolvedValue({ data: { data: [{ id: 1, name: 'Test' }] } }),
        },
      },
      globalState: {
        namespaced: true,
        actions: { set: vi.fn() },
      },
      kioskMenu: {
        namespaced: true,
        actions: { fetchMenu: vi.fn().mockResolvedValue() },
      },
    },
  });
}

describe('kiosk A11y — axe-core sentinels', () => {
  it('KioskAppComponent — barre panier (bouton natif)', async () => {
    const store = makeKioskAppStore();
    const wrapper = shallowMount(KioskAppComponent, {
      attachTo: document.body,
      global: {
        plugins: [store, i18n],
        stubs: {
          ConnectionStatusBanner: true,
          KioskInactivityOverlayComponent: true,
          KioskAdminComponent: true,
          KioskToastComponent: true,
          RouterView: { template: '<div />' },
          transition: false,
        },
        mocks: {
          $route: { name: 'kiosk.wizard', path: '/kiosk/wizard/1', meta: {} },
          $router: { push: vi.fn(), replace: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    const bar = wrapper.find('.kiosk-cart-bar');
    expect(bar.exists()).toBe(true);
    const r = await runAxe(wrapper.element);
    expectAxeClean(r);
    wrapper.unmount();
  });

  it('KioskToastComponent — région live + bouton fermer', async () => {
    const wrapper = mount(KioskToastComponent, {
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    wrapper.vm.show('Test message', 'info', 60000);
    await wrapper.vm.$nextTick();
    const r = await runAxe(wrapper.element);
    expectAxeClean(r);
    wrapper.unmount();
  });

  it('KioskWizardComponent — en-tête stepper', async () => {
    const item = {
      id: 9001,
      name: 'Tacos XL',
      category_name: 'Tacos',
      wizard_template: 'tacos',
      convert_price: 9,
      itemAttributes: [{ id: 1, name: 'Viande' }],
      variations: {
        1: [{ id: 11, name: 'Boeuf', convert_price: '0', price: 0, status: 5 }],
      },
      extras: [],
    };
    const wrapper = shallowMount(KioskWizardComponent, {
      attachTo: document.body,
      props: { item, onAddToCart: vi.fn(), onClose: vi.fn() },
      global: {
        plugins: [i18n],
        stubs: { ...wizardStubs, transition: false },
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
    });
    await wrapper.vm.$nextTick();
    const r = await runAxe(wrapper.element);
    expectAxeClean(r);
    wrapper.unmount();
  });

  it('KsModal — ouverte', async () => {
    const wrapper = mount(KsModal, {
      props: { modelValue: true, title: 'Titre test', closable: true },
      slots: { default: '<p>Corps</p>' },
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();
    const r = await runAxe(wrapper.element);
    expectAxeClean(r);
    wrapper.unmount();
  });

  it('KioskCategoriesComponent — grille catalogue', async () => {
    const store = createStore({
      modules: {
        kioskSettings,
        kioskFilter: {
          namespaced: true,
          getters: {
            activeFilters: () => [],
            hydrated: () => true,
          },
          actions: {
            init: vi.fn(),
            toggle: vi.fn(),
            reset: vi.fn(),
          },
        },
        kioskMenu: {
          namespaced: true,
          state: () => ({ kioskSandwichSubcolumn: null }),
          getters: {
            categories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
            allItems: () => [],
            selectedCategoryId: () => 1,
            loading: () => false,
            isStale: () => false,
            fromCache: () => false,
            sidebarCategories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
            kioskCatalogItems: () => [
              {
                id: 10,
                name: 'Item A',
                convert_price: 8,
                category_id: 1,
                status: 5,
              },
            ],
          },
          actions: {
            fetchMenu: vi.fn().mockResolvedValue(),
            selectKioskCategory: vi.fn(),
          },
        },
        kioskCart: {
          namespaced: true,
          state: () => ({ branchId: 1, kioskToken: 't' }),
          getters: {
            count: () => 0,
            total: () => 0,
            isEmpty: () => true,
          },
          actions: { addItem: vi.fn(), reset: vi.fn() },
        },
        frontendItem: {
          namespaced: true,
          actions: { details: vi.fn().mockResolvedValue({ data: { data: { id: 1, name: 'Mock' } } }) },
        },
      },
    });
    const wrapper = mount(KioskCategoriesComponent, {
      global: {
        plugins: [store, i18n],
        mocks: {
          $router: { push: vi.fn(), replace: vi.fn() },
          $route: { query: {}, params: {} },
        },
        stubs: {
          KioskWizardComponent: true,
          transition: false,
        },
      },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();
    const r = await runAxe(wrapper.element);
    expectAxeClean(r, AXE_ALLOW_IDS_CATEGORIES);
    wrapper.unmount();
  });
});
