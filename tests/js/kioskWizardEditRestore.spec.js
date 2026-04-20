import { describe, it, expect, vi } from 'vitest';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import { shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import frMessages from '../../resources/js/languages/fr.json';

vi.mock('../../resources/js/helpers/kioskOfflineQueue', () => ({
  saveOrder: vi.fn(),
  getPendingCount: vi.fn(() => 0),
  startAutoSync: vi.fn(),
}));
vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
  isSnapshotStale: vi.fn(() => false),
  loadSnapshot: vi.fn(() => null),
}));

const wizardI18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const stubs = Object.fromEntries(
  ['KioskStepPain', 'KioskStepTaille', 'KioskStepViande', 'KioskStepSauce',
   'KioskStepGarnitures', 'KioskStepSupplements', 'KioskStepMenu', 'KioskOrderSummary']
    .map((n) => [n, true])
);

const cloneModule = () => ({ ...kioskCart, state: JSON.parse(JSON.stringify(kioskCart.state)) });

const mountWizardWithStore = (item, store) =>
  shallowMount(KioskWizardComponent, {
    props: { item, onAddToCart: null, onClose: null },
    global: {
      plugins: [wizardI18n, store],
      stubs,
      mocks: { $router: { go: vi.fn(), push: vi.fn() } },
    },
  });

const tacosItem = {
  id: 42,
  name: 'Tacos XL 3 viandes',
  category_name: 'Tacos',
  wizard_template: 'tacos',
  convert_price: 9,
  currency_price: '9,00 €',
  itemAttributes: [{ id: 1, name: 'Viande' }, { id: 2, name: 'Sauce' }],
  variations: {
    1: [{ id: 11, name: 'Boeuf' }, { id: 12, name: 'Poulet' }],
    2: [{ id: 21, name: 'Algérienne' }],
  },
  extras: [{ id: 301, name: 'Tomate' }],
};

describe('[P-MEGA-05] Wizard restore selections from edit snapshot', () => {
  it('au mount, restore intégral des sélections quand isEditingCart=true et item_id matches', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    // Pré-set un snapshot édition
    store.commit('kioskCart/SET_EDITING', {
      index: 0,
      snapshot: {
        item_id: 42,
        quantity: 3,
        instruction: 'Sans oignons',
        _wizardSelections: {
          pain: null,
          taille: 'XL',
          _tailleMeta: { viandeCount: 3, label: 'XL' },
          _viandeMeta: [
            { id: 11, key: 'var_11', name: 'Boeuf', source: 'variation', count: 2 },
            { id: 12, key: 'var_12', name: 'Poulet', source: 'variation', count: 1 },
          ],
          viandes: { 11: 2, 12: 1 },
          totalViandes: 3,
          sauces: { 21: true },
          sauceOrder: [21],
          garnitures: {},
          supplements: {},
          menuChoice: null,
          boissonChoice: null,
          fritesSauce: null,
          fritesSauceOrder: [],
          quantity: 3,
          instruction: 'Sans oignons',
        },
      },
    });
    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.selections.viandes).toEqual({ 11: 2, 12: 1 });
    expect(wrapper.vm.selections.totalViandes).toBe(3);
    expect(wrapper.vm.selections._viandeMeta).toHaveLength(2);
    expect(wrapper.vm.selections.sauces).toEqual({ 21: true });
    expect(wrapper.vm.selections.quantity).toBe(3);
    expect(wrapper.vm.selections.instruction).toBe('Sans oignons');
  });

  it('au mount, ne restore PAS si item_id du snapshot ≠ item courant (security)', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    store.commit('kioskCart/SET_EDITING', {
      index: 0,
      snapshot: {
        item_id: 999,
        _wizardSelections: { viandes: { 11: 5 }, totalViandes: 5, sauces: {}, sauceOrder: [], garnitures: {}, supplements: {}, quantity: 5, instruction: 'spam' },
      },
    });
    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.selections.viandes).toEqual({});
    expect(wrapper.vm.selections.totalViandes).toBe(0);
    expect(wrapper.vm.selections.quantity).toBe(1);
  });

  it('addToCart en mode edit appelle replaceEditingCartItem (et clear edit)', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    // Pré-add un item au panier
    await store.dispatch('kioskCart/addItem', {
      item_id: 42,
      name: 'Tacos pré-existant',
      quantity: 1,
      convert_price: 9,
      item_variations: [],
      item_extras: [],
    });
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    expect(store.getters['kioskCart/isEditingCart']).toBe(true);

    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();
    wrapper.vm.selections.quantity = 5;

    wrapper.vm.addToCart();
    await wrapper.vm.$nextTick();

    expect(store.getters['kioskCart/items']).toHaveLength(1); // Pas d'add, c'est un replace
    expect(store.getters['kioskCart/items'][0].quantity).toBe(5);
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
  });

  it('addToCart hors mode edit appelle addItem normal', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();

    wrapper.vm.addToCart();
    await wrapper.vm.$nextTick();

    expect(store.getters['kioskCart/items']).toHaveLength(1);
    expect(store.getters['kioskCart/items'][0].item_id).toBe(42);
  });

  it('performCloseWizard cancel l\'édition et préserve la cart line originale', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    await store.dispatch('kioskCart/addItem', {
      item_id: 42, name: 'Tacos original', quantity: 4, convert_price: 9, item_variations: [], item_extras: [],
    });
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();

    wrapper.vm.performCloseWizard();
    await wrapper.vm.$nextTick();

    expect(store.getters['kioskCart/items']).toHaveLength(1);
    expect(store.getters['kioskCart/items'][0].quantity).toBe(4);
    expect(store.getters['kioskCart/items'][0].name).toBe('Tacos original');
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
  });

  it('beforeDestroy guard cancel l\'édition orpheline (timeout idle, navigation externe)', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    await store.dispatch('kioskCart/addItem', {
      item_id: 42, name: 'Tacos', quantity: 2, convert_price: 9, item_variations: [], item_extras: [],
    });
    await store.dispatch('kioskCart/startEditingCartItem', 0);
    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();

    wrapper.unmount(); // simulate route change / idle reset
    expect(store.getters['kioskCart/isEditingCart']).toBe(false);
    expect(store.getters['kioskCart/items']).toHaveLength(1);
  });

  it('buildCartItem inclut _wizardSelections (snapshot pour ré-édition future)', async () => {
    const store = createStore({ modules: { kioskCart: cloneModule() } });
    const wrapper = mountWizardWithStore(tacosItem, store);
    await wrapper.vm.$nextTick();
    wrapper.vm.selections.viandes = { 11: 1 };
    wrapper.vm.selections.totalViandes = 1;
    wrapper.vm.selections.quantity = 2;
    wrapper.vm.selections.instruction = 'extra spicy';

    const ci = wrapper.vm.buildCartItem();
    expect(ci._wizardSelections).toBeDefined();
    expect(ci._wizardSelections.viandes).toEqual({ 11: 1 });
    expect(ci._wizardSelections.quantity).toBe(2);
    expect(ci._wizardSelections.instruction).toBe('extra spicy');
  });
});
