import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepMenuComponent from '../../../resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue';
import frMessages from '../../../resources/js/languages/fr.json';
import {
  calculateKioskRunningTotal,
  getKioskStandaloneDrinkAddonPrice,
} from '../../../resources/js/helpers/kioskPricing';

/**
 * [REGISTRE goal-intelligence-2026-07-18 · finding P2-g / F3 borne]
 *
 * Bol Frites (item 41) — step composer « boisson » (addon_role='drink',
 * source_type='addon') portant l'addon « Boisson Seule » (id 99, 2,00 €).
 *
 * Le mapping FROZEN `ADDON_ROLE_TO_TYPE['drink']='menu'` route ce step vers
 * KioskStepMenu. Avant le fix : la boisson (choisie au catalogue global)
 * émettait `addonId:null` → jamais poussée → bol facturé seul (7,90) mais nom
 * imprimé au ticket cuisine. Ce test verrouille le fix NON-frozen :
 *   (a) le step drink pur d'un bol se rend en sélecteur de boisson (pas en
 *       formule « Menu Complet gratuit ») ;
 *   (b) sélectionner une boisson émet `addonId` NON-null (99) + menuChoice
 *       'boisson' → le wizard frozen pousse l'addon → facturé 2,00 € ;
 *   (c) le total local == scellé (7,90 + 2,00 = 9,90).
 */

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const DRINK_ADDON_ID = 99;

const buildBolItem = () => ({
  id: 41,
  name: 'Bol Frites',
  has_menu: false,
  default_menu_kiosk: false,
  convert_price: 7.9,
  price: '7.900000',
  extras: [],
  addons: [
    {
      id: DRINK_ADDON_ID,
      addon_item_id: null,
      item_addon_id: 3,
      addon_item_name: 'Boisson Seule',
      name: null,
      group_label: null,
      addon_item_convert_price: 2,
      price: null,
      addon_item_price: '2.000000',
    },
  ],
});

const buildDrinkStep = (overrides = {}) => ({
  type: 'menu',
  label: 'Boisson (optionnel)',
  component: 'KioskStepMenu',
  composer_step: {
    id: 277,
    step_key: 'boisson',
    addon_role: 'drink',
    source_type: 'addon',
    min_select: 0,
    max_select: 1,
    choices: [{ id: DRINK_ADDON_ID, addon_item_id: 3, name: 'Boisson Seule' }],
    ...overrides,
  },
});

// Store stub exposing a global drink catalog (Coca) so boissonList is populated.
const drinkCatalogStore = () => ({
  getters: {
    'kioskMenu/allItems': [
      { id: 200, name: 'Coca-Cola', item_category_id: 9, status: 5, is_available: true },
    ],
    'kioskMenu/categories': [{ id: 9, name: 'Boissons', slug: 'boissons' }],
  },
  state: { kioskMenu: { items: [], categories: [] } },
});

const mountStep = (selections = {}) =>
  mount(KioskStepMenuComponent, {
    global: {
      plugins: [i18n],
      mocks: { $store: drinkCatalogStore() },
    },
    props: {
      step: buildDrinkStep(),
      item: buildBolItem(),
      selections: {
        menuChoice: null,
        boissonChoice: null,
        supplements: {},
        sauceOrder: [],
        fritesSauceOrder: [],
        quantity: 1,
        ...selections,
      },
      // Wizard sets this false for bols (no menu-formula addon).
      showBoissonOnlyMenuCard: false,
    },
  });

describe('[P2-g] Bol drink addon — pricing helper (display == sealed)', () => {
  it('getKioskStandaloneDrinkAddonPrice returns the addon price (2,00) when a drink is picked', () => {
    const item = buildBolItem();
    const selections = {
      menuChoice: 'boisson',
      _boissonMeta: { boissonName: 'Coca-Cola', boissonId: 200, addonId: DRINK_ADDON_ID },
    };
    expect(getKioskStandaloneDrinkAddonPrice(item, selections)).toBe(2);
  });

  it('returns 0 when no drink addon is selected', () => {
    const item = buildBolItem();
    expect(getKioskStandaloneDrinkAddonPrice(item, { menuChoice: 'none' })).toBe(0);
    expect(getKioskStandaloneDrinkAddonPrice(item, {})).toBe(0);
  });

  it('calculateKioskRunningTotal charges bol + drink (7,90 + 2,00 = 9,90)', () => {
    const item = buildBolItem();
    const withDrink = {
      menuChoice: 'boisson',
      _boissonMeta: { boissonName: 'Coca-Cola', boissonId: 200, addonId: DRINK_ADDON_ID },
      supplements: {},
      quantity: 1,
    };
    expect(calculateKioskRunningTotal(item, withDrink)).toBe(9.9);
  });

  it('calculateKioskRunningTotal charges bol alone (7,90) without a drink', () => {
    const item = buildBolItem();
    expect(
      calculateKioskRunningTotal(item, { menuChoice: 'none', supplements: {}, quantity: 1 })
    ).toBe(7.9);
  });
});

describe('[P2-g] Bol drink addon — KioskStepMenu standalone drink step', () => {
  it('renders a drink picker, NOT the "Menu Complet gratuit" formula cards', () => {
    const wrapper = mountStep();
    // The menu-formula cards (full / frites / none) must be hidden for a pure drink step.
    expect(wrapper.find('.kiosk-menu-options').exists()).toBe(false);
    // The drink grid must be shown with the catalog drink(s).
    const cards = wrapper.findAll('.kiosk-boisson-card');
    expect(cards.length).toBeGreaterThan(0);
    expect(wrapper.text()).toContain('Coca-Cola');
  });

  it('emits a NON-null addonId (99) + menuChoice="boisson" when a drink is selected', async () => {
    const wrapper = mountStep();
    const card = wrapper.find('.kiosk-boisson-card');
    await card.trigger('click');

    const updates = wrapper.emitted('update') || [];

    // menuChoice must end on 'boisson' so the frozen wizard pushes the drink addon.
    const menuChoiceEvents = updates.filter((a) => a[0] === 'menuChoice');
    expect(menuChoiceEvents.length).toBeGreaterThan(0);
    expect(menuChoiceEvents[menuChoiceEvents.length - 1][1]).toBe('boisson');

    // boissonChoice must carry meta.addonId === 99 (the billing anchor), NOT null.
    const boissonEvent = updates.find((a) => a[0] === 'boissonChoice' && a[2] != null);
    expect(boissonEvent, 'boissonChoice update with meta must be emitted').toBeTruthy();
    expect(boissonEvent[2].addonId).toBe(DRINK_ADDON_ID);
    expect(boissonEvent[2].boissonName).toBe('Coca-Cola');
  });

  it('honors min_select=0: defaults to a skippable "none" choice on mount', () => {
    const wrapper = mountStep();
    const updates = wrapper.emitted('update') || [];
    const menuChoiceEvents = updates.filter((a) => a[0] === 'menuChoice');
    // On mount, an optional drink step pre-selects 'none' so the customer can advance.
    expect(menuChoiceEvents.length).toBeGreaterThan(0);
    expect(menuChoiceEvents[0][1]).toBe('none');
  });
});
