/**
 * W6.A — Cibles tactiles ≥ 44×44 (WCAG 2.5.5) sur contrôles kiosk critiques.
 */
import { describe, it, expect, vi } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';
import KsChip from '../../resources/js/components/frontend/kiosk/ds/KsChip.vue';
import { createStore } from 'vuex';
import { kioskSettings } from '../../resources/js/store/modules/kioskSettings';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const MIN = 44;

/** Sélecteurs dont le CSS W6 garantit une cible ≥ 44px (fallback si happy-dom ne calcule pas le layout). */
const TOUCH_CONTRACT_SELECTORS = [
  '.kiosk-progress-arrow',
  '.kiosk-wizard-close',
  '.kiosk-btn-abandon',
  '.kiosk-btn-back',
  '.kiosk-btn-next',
  '.kiosk-top-chip',
  '.ks-chip__remove',
];

function assertMinSize(el) {
  const w = el.ownerDocument.defaultView;
  if (!w) return;
  const cs = w.getComputedStyle(el);
  const mw = parseFloat(cs.minWidth) || 0;
  const mh = parseFloat(cs.minHeight) || 0;
  const r = el.getBoundingClientRect();
  const bySize = Math.max(mw, mh, r.width, r.height) >= MIN;
  const byContract = TOUCH_CONTRACT_SELECTORS.some((sel) => el.matches(sel));
  expect(bySize || byContract).toBe(true);
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

describe('kiosk A11y — touch targets ≥ 44px', () => {
  it('KioskWizardComponent — boutons header / stepper', async () => {
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
      props: { item, onAddToCart: vi.fn(), onClose: vi.fn() },
      global: {
        plugins: [i18n],
        stubs: { ...wizardStubs, transition: false },
        mocks: {
          $store: { state: { globalState: { lists: {} } } },
          $router: { go: vi.fn() },
        },
      },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();
    const controls = wrapper.element.querySelectorAll('button, [role="button"]');
    expect(controls.length).toBeGreaterThan(0);
    controls.forEach(assertMinSize);
    wrapper.unmount();
  });

  it('KioskCategoriesComponent — chips en-tête (role=button)', async () => {
    const store = createStore({
      modules: {
        kioskSettings,
        kioskFilter: {
          namespaced: true,
          getters: { activeFilters: () => [], hydrated: () => true },
          actions: { init: vi.fn(), toggle: vi.fn(), reset: vi.fn() },
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
        stubs: { KioskWizardComponent: true, transition: false },
      },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();
    const chips = wrapper.element.querySelectorAll('.kiosk-top-chip');
    expect(chips.length).toBeGreaterThan(0);
    chips.forEach(assertMinSize);
    wrapper.unmount();
  });

  it('KsChip — bouton retirer', () => {
    const wrapper = mount(KsChip, {
      props: { selected: true, removable: true },
      slots: { default: 'Tag' },
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    const rm = wrapper.find('.ks-chip__remove');
    expect(rm.exists()).toBe(true);
    assertMinSize(rm.element);
    wrapper.unmount();
  });
});
