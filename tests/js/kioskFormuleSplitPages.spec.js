import { describe, it, expect, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount, shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskStepMenuComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [W-SPLIT 2026-07-22 · LOCK_KIOSK_FORMULE_SPLIT] Split de la cascade formule de la
 * borne en 3 PAGES DÉDIÉES (référence concurrents, mandat owner) :
 *   Page 1 — Formule    (cartes menu complet / boisson seule / frites seules / sans)
 *   Page 2 — Boisson    (grille boissons pleine page, si la formule inclut une boisson)
 *   Page 3 — Sauce frites (grille sauces frites pleine page, si la formule inclut des frites)
 *
 * Exigence dure : SEULE la navigation change. Le payload backend (roles
 * menu_full/menu_frites/menu_boisson + _boissonMeta + fritesSauceOrder) et les prix
 * formule (2,50 / 1,90 / 1,90) restent IDENTIQUES. La zone gelée KioskWizardComponent
 * n'est touchée que pour ce split (gate owner explicite).
 */

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

// Tacos formule complète : has_menu + addon « Menu (Frites + Boisson) » @3,00 +
// une vraie boisson (Coca-Cola) + attribut Sauce (→ catalogue sauces frites).
const buildFormuleItem = (overrides = {}) => ({
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
      { id: 22, name: 'Blanche', convert_price: '0.50', price: 0.5, status: 5 },
    ],
  },
  extras: [],
  addons: [
    { id: 37, addon_item_name: 'Menu (Frites + Boisson)', addon_item_convert_price: '3.00', price: 3 },
    { addon_item_name: 'Coca-Cola', group_label: 'boisson', addon_item_id: 778 },
  ],
  ...overrides,
});

const wizardStubs = Object.fromEntries(
  [
    'KioskStepPain',
    'KioskStepTaille',
    'KioskStepViande',
    'KioskStepSauce',
    'KioskStepGarnitures',
    'KioskStepSupplements',
    'KioskStepMenu',
    'KioskStepFritesStyle',
    'KioskStepGenericChoices',
    'KioskOrderSummary',
    'KsAllergenBadge',
  ].map((n) => [n, true]),
);

const mountWizard = (item = buildFormuleItem()) =>
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

const typesOf = (vm) => vm.activeSteps.map((s) => s.type);

describe('[W-SPLIT] Formule split — 3 étapes dédiées (wizard)', () => {
  it('formule complète → séquence menu → boisson → frites_sauce, contiguë et ordonnée', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    await wrapper.vm.$nextTick();

    const types = typesOf(wrapper.vm);
    const mi = types.indexOf('menu');
    const bi = types.indexOf('boisson');
    const fi = types.indexOf('frites_sauce');
    expect(mi).toBeGreaterThanOrEqual(0);
    expect(bi).toBe(mi + 1);
    expect(fi).toBe(mi + 2);
  });

  it('gating : boisson n’apparaît que si la formule inclut une boisson ; frites_sauce que si elle inclut des frites', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();

    wrapper.vm.updateSelection('menuChoice', 'frites');
    await wrapper.vm.$nextTick();
    expect(typesOf(wrapper.vm)).toContain('frites_sauce');
    expect(typesOf(wrapper.vm)).not.toContain('boisson');

    wrapper.vm.updateSelection('menuChoice', 'boisson');
    await wrapper.vm.$nextTick();
    expect(typesOf(wrapper.vm)).toContain('boisson');
    expect(typesOf(wrapper.vm)).not.toContain('frites_sauce');

    wrapper.vm.updateSelection('menuChoice', 'none');
    await wrapper.vm.$nextTick();
    expect(typesOf(wrapper.vm)).not.toContain('boisson');
    expect(typesOf(wrapper.vm)).not.toContain('frites_sauce');
  });

  it('étape menu : CTA gaté sur le SEUL choix de formule (la boisson ne le bloque plus)', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    const menuIdx = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    wrapper.vm.currentStepIndex = menuIdx;

    wrapper.vm.updateSelection('menuChoice', null);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);

    wrapper.vm.updateSelection('menuChoice', 'full');
    await wrapper.vm.$nextTick();
    // canAdvance vrai même SANS boissonChoice : la boisson est validée sur son étape dédiée.
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('étape boisson dédiée : CTA gaté sur la sélection boisson (min 1)', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    await wrapper.vm.$nextTick();
    const bi = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    expect(bi).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = bi;

    wrapper.vm.updateSelection('boissonChoice', null);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);

    wrapper.vm.updateSelection('boissonChoice', 778, { boissonName: 'Coca-Cola', boissonId: 778 });
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('étape sauce-frites dédiée : CTA gaté sur au moins une sauce (dont « Sans sauce »)', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'frites');
    await wrapper.vm.$nextTick();
    const fi = wrapper.vm.activeSteps.findIndex((s) => s.type === 'frites_sauce');
    expect(fi).toBeGreaterThanOrEqual(0);
    wrapper.vm.currentStepIndex = fi;

    wrapper.vm.updateSelection('fritesSauceOrder', []);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(false);

    wrapper.vm.updateSelection('fritesSauceOrder', ['sans']);
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.canAdvance).toBe(true);
  });

  it('retour arrière propre : depuis l’étape boisson on revient à l’étape menu', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    await wrapper.vm.$nextTick();
    const mi = wrapper.vm.activeSteps.findIndex((s) => s.type === 'menu');
    const bi = wrapper.vm.activeSteps.findIndex((s) => s.type === 'boisson');
    wrapper.vm.currentStepIndex = bi;

    wrapper.vm.prevStep();
    await wrapper.vm.$nextTick();
    expect(wrapper.vm.currentStepIndex).toBe(mi);
    expect(wrapper.vm.currentStep.type).toBe('menu');
  });

  it('MONEY-PATH inchangé : buildCartItem scelle le rôle menu_full + la boisson (navigation-indépendant)', async () => {
    const wrapper = mountWizard();
    await wrapper.vm.$nextTick();
    wrapper.vm.updateSelection('menuChoice', 'full');
    wrapper.vm.updateSelection('boissonChoice', 778, { boissonName: 'Coca-Cola', boissonId: 778, addonId: 778 });
    wrapper.vm.updateSelection('fritesSauceOrder', ['21']);
    await wrapper.vm.$nextTick();

    const cart = wrapper.vm.buildCartItem();
    const roles = (cart.item_addons || []).map((a) => a.role);
    // Rôle formule NF525-critique intact (PricingService applique le ratio full).
    expect(roles).toContain('menu_full');
    // Boisson poussée via son addonId (facturation formule inchangée).
    expect(roles).toContain('drink');
    // Le prix de la formule (addon @3,00 × ratio full=1) est scellé dans le total variation.
    expect(cart.item_variation_total).toBeGreaterThan(0);
    // La boisson choisie est mémorisée pour le récap / ticket.
    expect(wrapper.vm.selections._boissonMeta?.boissonName).toBe('Coca-Cola');
  });
});

describe('[W-SPLIT] KioskStepMenu — sectionMode (rendu par page)', () => {
  const mountStep = (sectionMode, selections = {}) =>
    mount(KioskStepMenuComponent, {
      global: {
        plugins: [i18n],
        mocks: { $store: { getters: {}, state: {} } },
      },
      props: {
        step: {},
        item: buildFormuleItem(),
        selections: { menuChoice: 'full', boissonChoice: null, fritesSauceOrder: [], ...selections },
        ...(sectionMode ? { sectionMode } : {}),
      },
    });

  it("sectionMode='formule' : n’affiche QUE les 4 cartes formule (aucune cascade interne)", () => {
    const wrapper = mountStep('formule');
    expect(wrapper.findAll('.kiosk-menu-card').length).toBe(4);
    expect(wrapper.find('.kiosk-boisson-section').exists()).toBe(false);
    expect(wrapper.find('.kiosk-frites-sauce-section').exists()).toBe(false);
  });

  it("sectionMode='boisson' : n’affiche QUE la grille boissons (pas de cartes formule ni sauce-frites)", () => {
    const wrapper = mountStep('boisson');
    expect(wrapper.find('.kiosk-menu-options').exists()).toBe(false);
    expect(wrapper.find('.kiosk-frites-sauce-section').exists()).toBe(false);
    expect(wrapper.find('.kiosk-boisson-section').exists()).toBe(true);
    expect(wrapper.text()).toContain('Coca-Cola');
  });

  it("sectionMode='frites_sauce' : n’affiche QUE la grille sauces frites", () => {
    const wrapper = mountStep('frites_sauce');
    expect(wrapper.find('.kiosk-menu-options').exists()).toBe(false);
    expect(wrapper.find('.kiosk-frites-sauce-section').exists()).toBe(true);
    // La seule .kiosk-boisson-section rendue est celle des sauces frites (pas de grille boisson).
    wrapper.findAll('.kiosk-boisson-section').forEach((s) =>
      expect(s.classes()).toContain('kiosk-frites-sauce-section'),
    );
  });

  it("sectionMode défaut 'all' : conserve le monolithe historique (cartes + cascade)", () => {
    const wrapper = mountStep(null);
    expect(wrapper.findAll('.kiosk-menu-card').length).toBe(4);
    expect(wrapper.find('.kiosk-boisson-section').exists()).toBe(true);
    expect(wrapper.find('.kiosk-frites-sauce-section').exists()).toBe(true);
  });

  it('bol intact : step boisson autonome composer (isStandaloneDrinkStep) inchangé par le split', () => {
    const wrapper = mount(KioskStepMenuComponent, {
      global: {
        plugins: [i18n],
        mocks: { $store: { getters: {}, state: {} } },
      },
      props: {
        // Path composer (bol) : has_menu=false + addon-boisson autonome. Le wizard NE pilote
        // PAS sectionMode sur ce chemin → défaut 'all', isStandaloneDrinkStep court-circuite.
        step: { composer_step: { source_type: 'addon', addon_role: 'drink', min_select: 0, choices: [{ id: 90 }] } },
        item: {
          id: 55,
          name: 'Bol Poulet',
          has_menu: false,
          addons: [{ id: 90, addon_item_name: 'Boisson Seule', addon_item_convert_price: '2.00', price: 2 }],
          variations: {},
          extras: [],
          itemAttributes: [],
        },
        selections: { menuChoice: null, boissonChoice: null, fritesSauceOrder: [] },
      },
    });
    expect(wrapper.vm.isStandaloneDrinkStep).toBe(true);
    // Pas de cartes formule (le bol n'a pas de « Menu Complet »), section boisson présente.
    expect(wrapper.find('.kiosk-menu-options').exists()).toBe(false);
    expect(wrapper.find('.kiosk-boisson-section').exists()).toBe(true);
  });
});
