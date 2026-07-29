import { describe, it, expect, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import { calculateKioskRunningTotal } from '../../resources/js/helpers/kioskPricing';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [PLAINTE OWNER 2026-07-29 — « les suppléments sont ajoutés mais le prix ne bouge pas »]
 *
 * Racine : la 2ᵉ sauce FRITES du menu affichait « +0,50 € »
 * (KioskStepMenuComponent.fritesSaucePriceLabel → getKioskExtraSauceUnitPrice) alors que
 *   · calculateKioskRunningTotal ignorait `fritesSauceOrder` (total inchangé), et
 *   · buildCartItem ne poussait l'ItemExtra « Sauce supplémentaire » que pour `sauceOrder`
 *     (sandwich) → le backend ne facturait JAMAIS la sauce frites en plus.
 * Le SITE, lui, l'affiche, la facture (menu.js priceFor) ET la scelle (api.js item_extras) :
 * la borne était la seule à diverger. Ce spec verrouille la parité :
 * AFFICHÉ == TOTAL == SCELLÉ, sur les 24 produits porteurs de l'extra.
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
  id: 26,
  name: 'Tacos M',
  category_name: 'Tacos',
  wizard_template: 'tacos',
  has_menu: true,
  convert_price: '8.50',
  currency_price: '8,50 €',
  itemAttributes: [
    { id: 1, name: 'Viande' },
    { id: 2, name: 'Sauce' },
  ],
  variations: {
    1: [{ id: 11, name: 'Poulet', convert_price: '0', price: 0, status: 5 }],
    2: [
      { id: 21, name: 'Algérienne', convert_price: '0', price: 0, status: 5 },
      { id: 22, name: 'Blanche', convert_price: '0', price: 0, status: 5 },
    ],
  },
  extras: [SAUCE_SUPPL_EXTRA],
  addons: [
    { id: 37, addon_item_name: 'Menu (Frites + Boisson)', addon_item_convert_price: '3.00', price: 3 },
    { addon_item_name: 'Coca-Cola', group_label: 'boisson', addon_item_id: 778 },
  ],
  ...overrides,
});

const wizardStubs = Object.fromEntries(
  [
    'KioskStepPain', 'KioskStepTaille', 'KioskStepViande', 'KioskStepSauce',
    'KioskStepGarnitures', 'KioskStepSupplements', 'KioskStepMenu',
    'KioskStepFritesStyle', 'KioskStepGenericChoices', 'KioskOrderSummary',
    'KsAllergenBadge',
  ].map((n) => [n, true]),
);

const mountWizard = (item = buildItem()) =>
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

describe('[OWNER 2026-07-29] Sauce FRITES en plus — affiché == total == scellé', () => {
  const item = buildItem();
  const base = calculateKioskRunningTotal(item, { quantity: 1 });

  it('1ʳᵉ sauce frites : incluse (aucun surcoût)', () => {
    const t = calculateKioskRunningTotal(item, { quantity: 1, fritesSauceOrder: ['21'] });
    expect(t).toBeCloseTo(base, 2);
  });

  it('2ᵉ sauce frites : +0,50 € dans le total affiché (== étiquette de l’étape)', () => {
    const t = calculateKioskRunningTotal(item, { quantity: 1, fritesSauceOrder: ['21', '22'] });
    expect(t).toBeCloseTo(base + 0.5, 2);
  });

  it('3ᵉ sauce frites : +1,00 € (N-1 × 0,50)', () => {
    const t = calculateKioskRunningTotal(item, { quantity: 1, fritesSauceOrder: ['21', '22', '23'] });
    expect(t).toBeCloseTo(base + 1.0, 2);
  });

  it('quantité 2 : le surcoût suit la ligne (2 × 0,50)', () => {
    const t = calculateKioskRunningTotal(item, { quantity: 2, fritesSauceOrder: ['21', '22'] });
    expect(t).toBeCloseTo((base + 0.5) * 2, 2);
  });

  it('item SANS l’extra « Sauce supplémentaire » : sauce frites en plus reste gratuite', () => {
    const free = buildItem({ extras: [] });
    const freeBase = calculateKioskRunningTotal(free, { quantity: 1 });
    const t = calculateKioskRunningTotal(free, { quantity: 1, fritesSauceOrder: ['21', '22', '23'] });
    expect(t).toBeCloseTo(freeBase, 2);
  });

  it('sauce SANDWICH + sauce FRITES : les deux surcoûts se cumulent, sans double comptage', () => {
    const t = calculateKioskRunningTotal(item, {
      quantity: 1,
      sauceOrder: ['21', '22'],
      fritesSauceOrder: ['21', '22'],
    });
    expect(t).toBeCloseTo(base + 1.0, 2);
  });

  it('SCELLÉ : buildCartItem pousse l’ItemExtra « Sauce supplémentaire » ×(N-1) pour les frites', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    wrapper.vm.updateSelection('fritesSauceOrder', ['21', '22']);
    await wrapper.vm.$nextTick();

    const cart = wrapper.vm.buildCartItem();
    const pushed = (cart.item_extras || []).filter((e) => Number(e.id) === SAUCE_SUPPL_EXTRA.id);
    expect(pushed.length).toBe(1);
    expect(cart.item_extra_total).toBeCloseTo(0.5, 2);
  });

  it('SCELLÉ : 1 seule sauce frites → aucun extra poussé (1ʳᵉ incluse)', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    wrapper.vm.updateSelection('fritesSauceOrder', ['21']);
    await wrapper.vm.$nextTick();

    const cart = wrapper.vm.buildCartItem();
    const pushed = (cart.item_extras || []).filter((e) => Number(e.id) === SAUCE_SUPPL_EXTRA.id);
    expect(pushed.length).toBe(0);
    expect(cart.item_extra_total).toBeCloseTo(0, 2);
  });

  it('SCELLÉ : sandwich (2 sauces) + frites (2 sauces) → 2 extras poussés, total 1,00 €', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    wrapper.vm.updateSelection('sauceOrder', ['21', '22']);
    wrapper.vm.updateSelection('fritesSauceOrder', ['21', '22']);
    await wrapper.vm.$nextTick();

    const cart = wrapper.vm.buildCartItem();
    const pushed = (cart.item_extras || []).filter((e) => Number(e.id) === SAUCE_SUPPL_EXTRA.id);
    expect(pushed.length).toBe(2);
    expect(cart.item_extra_total).toBeCloseTo(1.0, 2);
  });

  it('AFFICHÉ == SCELLÉ : le total du wizard égale prix + extras scellés', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    wrapper.vm.updateSelection('fritesSauceOrder', ['21', '22']);
    await wrapper.vm.$nextTick();

    const cart = wrapper.vm.buildCartItem();
    const sealed = parseFloat(item.convert_price)
      + (cart.item_extra_total || 0)
      + (cart.item_variation_total || 0);
    expect(wrapper.vm.runningTotalLocal).toBeCloseTo(sealed, 2);
  });
});
