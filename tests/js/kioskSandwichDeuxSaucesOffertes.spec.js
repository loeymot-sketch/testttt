import { describe, it, expect, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount, shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskStepSauceComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue';
import {
  calculateKioskRunningTotal,
  getKioskBilledSauceCount,
  getKioskIncludedSauceCount,
} from '../../resources/js/helpers/kioskPricing';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [OWNER 2026-11-11 LOCK_POS_KIOSK_SAUCES_CRUDITES_2026-11-11] « Deux sauces offertes » (sandwichs).
 *
 * Le serveur publie `sauce_included` par produit (App\Support\Menu\IncludedSauceCount :
 * Sandwichs = 2, défaut 1) dans le menu borne et la fiche produit. La borne facture
 * max(0, N − sauce_included) unités de l'extra « Sauce supplémentaire » (0,50 € en base) :
 * total affiché, étiquettes de l'étape et payload scellé suivent la MÊME règle. Les sauces des
 * frites gardent 1 incluse (kioskFritesSauceBilling.spec.js, inchangé) ; les deux s'additionnent
 * sur le même extra du produit parent.
 */

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const SAUCE_SUPPL_EXTRA = {
  id: 428,
  name: 'Sauce supplémentaire',
  group_label: 'sauce',
  convert_price: '0.50',
  price: 0.5,
  status: 5,
};

const buildItem = (overrides = {}) => ({
  id: 70,
  name: 'Sandwich Américain',
  category_name: 'Sandwichs',
  has_menu: true,
  convert_price: '7.00',
  currency_price: '7,00 €',
  sauce_included: 2,
  itemAttributes: [{ id: 2, name: 'Sauce' }],
  variations: {
    2: [
      { id: 21, name: 'Algérienne', convert_price: '0', price: 0, status: 5 },
      { id: 22, name: 'Blanche', convert_price: '0', price: 0, status: 5 },
      { id: 23, name: 'Samouraï', convert_price: '0', price: 0, status: 5 },
    ],
  },
  extras: [SAUCE_SUPPL_EXTRA],
  addons: [
    { id: 37, addon_item_name: 'Menu (Frites + Boisson)', addon_item_convert_price: '3.00', price: 3 },
  ],
  ...overrides,
});

const buildTacos = () => buildItem({ id: 26, name: 'Tacos M', category_name: 'Tacos', sauce_included: 1 });

const wizardStubs = Object.fromEntries(
  [
    'KioskStepPain', 'KioskStepTaille', 'KioskStepViande', 'KioskStepSauce',
    'KioskStepGarnitures', 'KioskStepSupplements', 'KioskStepMenu',
    'KioskStepFritesStyle', 'KioskStepGenericChoices', 'KioskOrderSummary',
    'KsAllergenBadge',
  ].map((n) => [n, true]),
);

const mountWizard = (item) =>
  shallowMount(KioskWizardComponent, {
    props: { item, onAddToCart: vi.fn(), onClose: vi.fn() },
    global: {
      plugins: [i18n],
      stubs: wizardStubs,
      mocks: {
        $store: {
          getters: {
            'kioskFilter/activeFilters': [],
            'kioskSettings/customerProfile': null,
          },
          state: { globalState: { lists: {} } },
          dispatch: vi.fn(),
        },
        $router: { go: vi.fn() },
      },
    },
  });

const sealedSauceExtras = async (item, selections) => {
  const wrapper = mountWizard(item);
  await wrapper.vm.$nextTick();
  Object.entries(selections).forEach(([k, v]) => wrapper.vm.updateSelection(k, v));
  await wrapper.vm.$nextTick();
  const cart = wrapper.vm.buildCartItem();
  const pushed = (cart.item_extras || []).filter((e) => Number(e.id) === SAUCE_SUPPL_EXTRA.id);
  return { wrapper, cart, pushed };
};

const mountStep = (item, order) =>
  mount(KioskStepSauceComponent, {
    global: { plugins: [i18n] },
    props: {
      step: { type: 'sauce' },
      item,
      selections: {
        sauces: Object.fromEntries(order.map((k) => [String(k), true])),
        sauceOrder: order,
      },
    },
  });

const labelsByName = (wrapper) =>
  Object.fromEntries(
    wrapper.findAll('.kiosk-option-card').map((card) => [
      card.find('.kiosk-sauce-name').text(),
      card.find('.kiosk-sauce-price').text(),
    ]),
  );

describe('[OWNER 2026-11-11] sauce_included — helpers', () => {
  it('lit sauce_included (2 sandwich), défaut 1 si absent ou invalide', () => {
    expect(getKioskIncludedSauceCount(buildItem())).toBe(2);
    expect(getKioskIncludedSauceCount(buildTacos())).toBe(1);
    expect(getKioskIncludedSauceCount({})).toBe(1);
    expect(getKioskIncludedSauceCount({ sauce_included: 0 })).toBe(1);
    expect(getKioskIncludedSauceCount({ sauce_included: 'x' })).toBe(1);
    expect(getKioskIncludedSauceCount(null)).toBe(1);
  });

  it('sauces facturées = max(0, N − incluses)', () => {
    expect(getKioskBilledSauceCount(buildItem(), ['21', '22'])).toBe(0);
    expect(getKioskBilledSauceCount(buildItem(), ['21', '22', '23'])).toBe(1);
    expect(getKioskBilledSauceCount(buildTacos(), ['21', '22'])).toBe(1);
    expect(getKioskBilledSauceCount(buildItem(), [])).toBe(0);
    expect(getKioskBilledSauceCount(buildItem(), undefined)).toBe(0);
  });
});

describe('[OWNER 2026-11-11] sandwich — deux sauces offertes (affiché == scellé)', () => {
  it('2 sauces : 0 € ajouté, aucun extra scellé', async () => {
    const item = buildItem();
    const base = calculateKioskRunningTotal(item, { quantity: 1 });
    expect(calculateKioskRunningTotal(item, { quantity: 1, sauceOrder: ['21', '22'] })).toBeCloseTo(base, 2);

    const { wrapper, cart, pushed } = await sealedSauceExtras(item, { sauceOrder: ['21', '22'] });
    expect(pushed.length).toBe(0);
    expect(cart.item_extra_total).toBeCloseTo(0, 2);
    expect(wrapper.vm.runningTotalLocal).toBeCloseTo(base, 2);
  });

  it('3 sauces : 1 extra scellé, +0,50 €', async () => {
    const item = buildItem();
    const base = calculateKioskRunningTotal(item, { quantity: 1 });
    expect(calculateKioskRunningTotal(item, { quantity: 1, sauceOrder: ['21', '22', '23'] })).toBeCloseTo(base + 0.5, 2);

    const { wrapper, cart, pushed } = await sealedSauceExtras(item, { sauceOrder: ['21', '22', '23'] });
    expect(pushed.length).toBe(1);
    expect(cart.item_extra_total).toBeCloseTo(0.5, 2);
    expect(wrapper.vm.runningTotalLocal).toBeCloseTo(base + 0.5, 2);
  });

  it('les sauces au-delà de la 1ʳᵉ restent toutes listées pour la cuisine', async () => {
    const { cart } = await sealedSauceExtras(buildItem(), { sauceOrder: ['21', '22', '23'] });
    expect(String(cart.instruction || '')).toContain('Blanche');
    expect(String(cart.instruction || '')).toContain('Samouraï');
  });

  it('menu sandwich : 2 sauces + 2 sauces frites → 1 seul extra (celui des frites), +0,50 €', async () => {
    const item = buildItem();
    const base = calculateKioskRunningTotal(item, { quantity: 1, menuChoice: 'full' });
    expect(calculateKioskRunningTotal(item, {
      quantity: 1, menuChoice: 'full', sauceOrder: ['21', '22'], fritesSauceOrder: ['21', '22'],
    })).toBeCloseTo(base + 0.5, 2);

    const { pushed, cart } = await sealedSauceExtras(item, {
      menuChoice: 'full', sauceOrder: ['21', '22'], fritesSauceOrder: ['21', '22'],
    });
    expect(pushed.length).toBe(1);
    expect(cart.item_extra_total).toBeCloseTo(0.5, 2);
  });

  it('menu sandwich : 3 sauces + 2 sauces frites → 2 extras sur le même produit parent', async () => {
    const { pushed, cart } = await sealedSauceExtras(buildItem(), {
      menuChoice: 'full', sauceOrder: ['21', '22', '23'], fritesSauceOrder: ['21', '22'],
    });
    expect(pushed.length).toBe(2);
    expect(cart.item_extra_total).toBeCloseTo(1.0, 2);
  });
});

describe('[OWNER 2026-11-11] tacos (sauce_included 1) — règle inchangée', () => {
  it('2 sauces : 1 extra scellé, +0,50 €', async () => {
    const item = buildTacos();
    const base = calculateKioskRunningTotal(item, { quantity: 1 });
    expect(calculateKioskRunningTotal(item, { quantity: 1, sauceOrder: ['21', '22'] })).toBeCloseTo(base + 0.5, 2);

    const { pushed, cart } = await sealedSauceExtras(item, { sauceOrder: ['21', '22'] });
    expect(pushed.length).toBe(1);
    expect(cart.item_extra_total).toBeCloseTo(0.5, 2);
  });

  it('produit sans sauce_included publié : 1 incluse (comportement historique)', async () => {
    const legacy = buildTacos();
    delete legacy.sauce_included;
    const { pushed } = await sealedSauceExtras(legacy, { sauceOrder: ['21', '22'] });
    expect(pushed.length).toBe(1);
  });
});

describe('[OWNER 2026-11-11] étape sauce — étiquettes', () => {
  const free = frMessages.kiosk.wizard.summary.free;

  it('sandwich, 2 sauces : les deux « offertes », aucune ligne de supplément', () => {
    const wrapper = mountStep(buildItem(), [21, 22]);
    const labels = labelsByName(wrapper);
    expect(labels['Algérienne']).toBe(free);
    expect(labels['Blanche']).toBe(free);
    expect(labels['Samouraï']).toBe('');
    expect(wrapper.find('.kiosk-sauce-extra').exists()).toBe(false);
  });

  it('sandwich, 3 sauces : la 3ᵉ porte +0,50 €, ligne « +1 sauce supplémentaire »', () => {
    const wrapper = mountStep(buildItem(), [21, 22, 23]);
    const labels = labelsByName(wrapper);
    expect(labels['Algérienne']).toBe(free);
    expect(labels['Blanche']).toBe(free);
    expect(labels['Samouraï']).toMatch(/^\+0[,.]50/);
    expect(wrapper.find('.kiosk-sauce-extra').text()).toContain('+1 sauce supplémentaire');
  });

  it('tacos, 2 sauces : la 2ᵉ porte +0,50 € (inchangé)', () => {
    const wrapper = mountStep(buildTacos(), [21, 22]);
    const labels = labelsByName(wrapper);
    expect(labels['Algérienne']).toBe(free);
    expect(labels['Blanche']).toMatch(/^\+0[,.]50/);
    expect(wrapper.find('.kiosk-sauce-extra').exists()).toBe(true);
  });
});
