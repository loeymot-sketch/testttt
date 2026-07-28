import { describe, it, expect, vi } from 'vitest';
import { createStore } from 'vuex';
import { createI18n } from 'vue-i18n';
import { shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import { kioskCart } from '../../resources/js/store/modules/kioskCart.js';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [OWNER 2026-07-28] BORNE — les frites et le menu enfant chicken burger doivent exposer l'étape
 * SAUCE (et, pour le burger, crudités + suppléments), SANS profil composer publié : la borne les
 * affiche via le TEMPLATE catégorie ('snacking' pour les frites, 'sandwich' pour les menus enfants)
 * data-gaté par shouldShowStep. C'est le chemin sans-422 (cf. FritesKidsSauceNoProfileSealTest).
 */

vi.mock('../../resources/js/helpers/kioskOfflineQueue', () => ({
  saveOrder: vi.fn(), getPendingCount: vi.fn(() => 0), startAutoSync: vi.fn(),
}));
vi.mock('../../resources/js/helpers/kioskMenuCache', () => ({
  isSnapshotStale: vi.fn(() => false), loadSnapshot: vi.fn(() => null),
}));

const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages } });
const SAUCES = ['Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï', 'Algérienne'];
const sauceVariations = () => SAUCES.map((name, i) => ({ id: 5000 + i, name, item_attribute_id: 5 }));

// Frites servies après la commande : template 'snacking', attribut sauce 5 + variations, extra générique.
function fritesItem() {
  return {
    id: 33, name: 'Petite Frites', wizard_template: 'snacking', has_menu: false,
    convert_price: 2.5, currency_price: '2,50 €', item_category_id: 7,
    itemAttributes: [{ id: 5, name: 'Sauce (1ère Gratuite)' }],
    variations: { 5: sauceVariations() },
    extras: [{ id: 700, name: 'Sauce supplémentaire', group_label: 'sauce', convert_price: 0.5, currency_price: '€0.50' }],
  };
}

// Kids burger : template 'sandwich', sauce + crudités + suppléments (les « trois choses »).
function kidsBurgerItem() {
  return {
    id: 106, name: 'Menu Enfant Chicken Burger', wizard_template: 'sandwich', has_menu: false,
    convert_price: 6.0, currency_price: '6,00 €', item_category_id: 11,
    itemAttributes: [{ id: 5, name: 'Sauce (1ère Gratuite)' }],
    variations: { 5: sauceVariations() },
    extras: [
      { id: 800, name: 'Salade', group_label: 'crudite', convert_price: 0, currency_price: '€0.00' },
      { id: 801, name: 'Tomate', group_label: 'crudite', convert_price: 0, currency_price: '€0.00' },
      { id: 810, name: 'Cheddar', group_label: 'supplement', convert_price: 0.9, currency_price: '€0.90' },
      { id: 811, name: 'Œuf', group_label: 'supplement', convert_price: 0.9, currency_price: '€0.90' },
      { id: 700, name: 'Sauce supplémentaire', group_label: 'sauce', convert_price: 0.5, currency_price: '€0.50' },
    ],
  };
}

const stubs = Object.fromEntries(
  ['KioskStepPain', 'KioskStepTaille', 'KioskStepViande', 'KioskStepSauce', 'KioskStepGarnitures',
   'KioskStepSupplements', 'KioskStepMenu', 'KioskOrderSummary', 'KioskStepFritesStyle', 'KioskStepGenericChoices']
    .map((n) => [n, true])
);
const cloneModule = () => ({ ...kioskCart, state: JSON.parse(JSON.stringify(kioskCart.state)) });
const mountWizard = (item) => {
  const store = createStore({ modules: { kioskCart: cloneModule() } });
  return shallowMount(KioskWizardComponent, {
    props: { item, onAddToCart: null, onClose: null },
    global: { plugins: [i18n, store], stubs, mocks: { $router: { go: vi.fn(), push: vi.fn() } } },
  });
};
const stepTypes = (wrapper) => wrapper.vm.activeSteps.map((s) => s.type);

describe('BORNE frites/kids — étapes via template (owner 2026-07-28, sans profil)', () => {
  it('FRITES (snacking) → étape SAUCE présente, aucune étape parasite (has_menu=false)', () => {
    const types = stepTypes(mountWizard(fritesItem()));
    expect(types, 'la borne propose la sauce sur les frites').toContain('sauce');
    expect(types, 'pas d\'étape menu (has_menu=false)').not.toContain('menu');
    expect(types, 'pas d\'étape viande (frites)').not.toContain('viande');
    // Épuré : sauce + recap.
    expect(types).toContain('recap');
  });

  it('KIDS BURGER (sandwich) → SAUCE + garnitures + suppléments (« les trois choses »)', () => {
    const types = stepTypes(mountWizard(kidsBurgerItem()));
    expect(types, 'sauce').toContain('sauce');
    expect(types, 'crudités (garnitures)').toContain('garnitures');
    expect(types, 'suppléments').toContain('supplements');
    expect(types, 'pas d\'étape menu (has_menu=false)').not.toContain('menu');
  });

  it('ne s\'appuie sur AUCUN profil composer (le chemin sans-422)', () => {
    const w = mountWizard(fritesItem());
    // publishedComposerProfile() doit être nul : l'item n'a pas de composer_profile.
    expect(w.vm.publishedComposerProfile()).toBeNull();
  });
});
