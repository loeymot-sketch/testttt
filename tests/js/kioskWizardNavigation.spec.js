import { describe, it, expect, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount, shallowMount } from '@vue/test-utils';
import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskStepViandeComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue';
import KioskStepGarnituresComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue';
import KioskStepSauceComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [P-MEGA-04] Garde-fou navigation wizard : back/forward préserve les
 * sélections utilisateur.
 *
 * Risque structurel : `<component :is="currentStepComponent" :key="currentStep.type">`
 * dans une <transition mode="out-in"> → chaque navigation détruit puis
 * remonte le composant Step. Si un Step réinitialise mal son état local
 * lors du remount, l'utilisateur perd ses sélections.
 *
 * Ce test couvre 3 scénarios critiques :
 *   1. Viande/Sauce : aller/retour préserve les viandes ET les sauces
 *   2. Garnitures : modifier une garniture, naviguer ailleurs, revenir,
 *      observer que la modification utilisateur survit (garde contre la
 *      faille `userInteracted` reset au remount).
 *   3. Taille → Viande : changer la taille reset les viandes
 *      INTENTIONNELLEMENT (cf. updateSelection ligne 567-571).
 */

const wizardI18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

const wizardStubNames = [
  'KioskStepPain',
  'KioskStepTaille',
  'KioskStepViande',
  'KioskStepSauce',
  'KioskStepGarnitures',
  'KioskStepSupplements',
  'KioskStepMenu',
  'KioskOrderSummary',
];

const stubs = Object.fromEntries(wizardStubNames.map((n) => [n, true]));

const mountWizard = (item) =>
  shallowMount(KioskWizardComponent, {
    props: { item, onAddToCart: vi.fn(), onClose: vi.fn() },
    global: {
      plugins: [wizardI18n],
      stubs,
      mocks: {
        $store: { state: { globalState: { lists: {} } } },
        $router: { go: vi.fn() },
      },
    },
  });

describe('[P-MEGA-04] Wizard navigation — preserves user selections across back/forward', () => {
  const tacosItem = {
    id: 5001,
    name: 'Tacos XL 3 viandes',
    category_name: 'Tacos',
    wizard_template: 'tacos',
    convert_price: 9,
    currency_price: '9,00 €',
    itemAttributes: [
      { id: 1, name: 'Viande' },
      { id: 2, name: 'Sauce' },
    ],
    variations: {
      1: [
        { id: 11, name: 'Boeuf', convert_price: '0', price: 0, status: 5 },
        { id: 12, name: 'Poulet', convert_price: '0', price: 0, status: 5 },
        { id: 13, name: 'Merguez', convert_price: '0', price: 0, status: 5 },
      ],
      2: [
        { id: 21, name: 'Algérienne', convert_price: '0', price: 0, status: 5 },
        { id: 22, name: 'Blanche', convert_price: '0', price: 0, status: 5 },
      ],
    },
    extras: [],
  };

  it('viandes + sauces survivent à un cycle next → prev', async () => {
    const wrapper = mountWizard(tacosItem);
    await wrapper.vm.$nextTick();

    // Set viande + sauce via la même API que les Step* enfants
    wrapper.vm.updateSelection('viandes', { 11: 2, 12: 1 });
    wrapper.vm.updateSelection('totalViandes', 3);
    wrapper.vm.updateSelection('_viandeMeta', [
      { id: 11, key: 'var_11', name: 'Boeuf', price: 0, source: 'variation', count: 2 },
      { id: 12, key: 'var_12', name: 'Poulet', price: 0, source: 'variation', count: 1 },
    ]);
    wrapper.vm.updateSelection('sauceOrder', [21]);
    wrapper.vm.updateSelection('sauces', { 21: true });
    await wrapper.vm.$nextTick();

    // Naviguer next puis prev plusieurs fois
    wrapper.vm.nextStep();
    await wrapper.vm.$nextTick();
    wrapper.vm.nextStep();
    await wrapper.vm.$nextTick();
    wrapper.vm.prevStep();
    await wrapper.vm.$nextTick();
    wrapper.vm.prevStep();
    await wrapper.vm.$nextTick();
    wrapper.vm.nextStep();
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.selections.viandes).toEqual({ 11: 2, 12: 1 });
    expect(wrapper.vm.selections.totalViandes).toBe(3);
    expect(wrapper.vm.selections._viandeMeta.length).toBe(2);
    expect(wrapper.vm.selections.sauceOrder).toEqual([21]);
  });

  it('changer de taille reset les viandes (comportement INTENTIONNEL et documenté)', async () => {
    const wrapper = mountWizard({
      ...tacosItem,
      name: 'Tacos Spécial',
    });
    await wrapper.vm.$nextTick();

    // Pré-sélectionner 2 viandes
    wrapper.vm.updateSelection('viandes', { 11: 1, 12: 1 });
    wrapper.vm.updateSelection('totalViandes', 2);
    wrapper.vm.updateSelection('_viandeMeta', [
      { id: 11, key: 'var_11', name: 'Boeuf', price: 0, source: 'variation', count: 1 },
      { id: 12, key: 'var_12', name: 'Poulet', price: 0, source: 'variation', count: 1 },
    ]);
    await wrapper.vm.$nextTick();

    // Maintenant choisir une nouvelle taille → reset attendu
    wrapper.vm.updateSelection('taille', 'XXL', { viandeCount: 4, label: 'XXL' });
    await wrapper.vm.$nextTick();

    expect(wrapper.vm.selections.viandes).toEqual({});
    expect(wrapper.vm.selections.totalViandes).toBe(0);
    expect(wrapper.vm.selections._viandeMeta).toEqual([]);
    // Mais la nouvelle taille est bien posée :
    expect(wrapper.vm.selections._tailleMeta).toEqual({
      viandeCount: 4,
      label: 'XXL',
    });
  });

  it('le bouton back est désactivé sur le 1er step', async () => {
    const wrapper = mountWizard(tacosItem);
    await wrapper.vm.$nextTick();
    wrapper.vm.currentStepIndex = 0;
    await wrapper.vm.$nextTick();
    const backBtn = wrapper.find('.kiosk-btn-back');
    expect(backBtn.exists()).toBe(true);
    expect(backBtn.attributes('disabled')).toBeDefined();
  });

  it('prevStep ne descend jamais sous 0', () => {
    const wrapper = mountWizard(tacosItem);
    wrapper.vm.currentStepIndex = 0;
    wrapper.vm.prevStep();
    expect(wrapper.vm.currentStepIndex).toBe(0);
    wrapper.vm.prevStep();
    expect(wrapper.vm.currentStepIndex).toBe(0);
  });

  it('nextStep ne dépasse jamais le dernier index', () => {
    const wrapper = mountWizard(tacosItem);
    const last = wrapper.vm.activeSteps.length - 1;
    wrapper.vm.currentStepIndex = last;
    wrapper.vm.nextStep();
    expect(wrapper.vm.currentStepIndex).toBe(last);
    wrapper.vm.nextStep();
    expect(wrapper.vm.currentStepIndex).toBe(last);
  });
});

describe('[P-MEGA-04] Step* remount preserve local state from props.selections', () => {
  it('KioskStepViande seed localSelections depuis props.selections.viandes au mount', () => {
    const w = mount(KioskStepViandeComponent, {
      global: { plugins: [wizardI18n] },
      props: {
        step: {},
        item: {
          name: 'Tacos XL',
          itemAttributes: [{ id: 1, name: 'Viande' }],
          variations: { 1: [{ id: 11, name: 'Boeuf' }, { id: 12, name: 'Poulet' }] },
          extras: [],
        },
        selections: {
          viandes: { 11: 2, 12: 1 },
          _tailleMeta: { viandeCount: 3 },
        },
      },
    });
    expect(w.vm.localSelections).toEqual({ 11: 2, 12: 1 });
    expect(w.vm.totalSelected).toBe(3);
    expect(w.vm.maxViandes).toBe(3);
  });

  it('KioskStepGarnitures seed localSelections au mount (faille userInteracted lifecycle)', () => {
    const w = mount(KioskStepGarnituresComponent, {
      global: { plugins: [wizardI18n] },
      props: {
        step: {},
        item: {
          extras: [
            { id: 301, name: 'Tomate', convert_price: 0, price: 0 },
            { id: 302, name: 'Salade', convert_price: 0, price: 0 },
            { id: 303, name: 'Oignon', convert_price: 0, price: 0 },
          ],
        },
        // Au remount, l'utilisateur avait déjà décoché Tomate
        selections: { garnitures: { 301: false, 302: true, 303: true } },
      },
    });
    // Selections doivent être adoptées telles quelles
    expect(w.vm.localSelections).toEqual({ 301: false, 302: true, 303: true });
    expect(w.vm.userInteracted).toBe(false); // remount neuf, mais selections sont déjà ce que l'utilisateur veut
  });

  it('KioskStepSauce seed localSelections depuis props.selections.sauces', () => {
    const w = mount(KioskStepSauceComponent, {
      global: { plugins: [wizardI18n] },
      props: {
        step: {},
        item: {
          itemAttributes: [{ id: 1, name: 'Sauce' }],
          variations: {
            1: [{ id: 21, name: 'Algérienne', status: 5, convert_price: '0' }],
          },
        },
        selections: {
          sauces: { 21: true },
          sauceOrder: [21],
        },
      },
    });
    expect(w.vm.localSelections).toEqual({ 21: true });
  });
});
