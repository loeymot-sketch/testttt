import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepMenuComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [B-MEGA-BORNE 2026-07-22] La page « Faire un menu ? » (formule) de la borne
 * doit AFFICHER le prix de chaque option AVANT sélection — parité avec le
 * wizard web (wizard-v2.jsx l.576) qui montre déjà +2,50 / +1,50 / +1,00.
 *
 * SSOT : le prix vient de l'addon `Menu (Frites + Boisson)` @2,50 déjà reçu par
 * le composant (item.addons) via getKioskMenuAddonPrice — full ×1, frites ×0.6,
 * boisson ×0.4. Aucun prix inventé, aucun changement de valeur (affichage seul).
 */

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

// Item type « tacos/sandwich » : has_menu (catégorie) + addon menu @2,50.
const buildMenuItem = () => ({
  id: 26,
  name: 'Tacos M',
  has_menu: true,
  default_menu_kiosk: false, // pas d'auto-sélection 'full' au mount
  convert_price: 6.9,
  price: '6.900000',
  extras: [],
  addons: [
    {
      id: 37,
      role: 'menu_component',
      addon_item_name: 'Menu (Frites + Boisson)',
      addon_item_convert_price: 2.5,
      price: '2.500000',
    },
  ],
});

const mountStep = () =>
  mount(KioskStepMenuComponent, {
    props: {
      step: {},
      item: buildMenuItem(),
      selections: { menuChoice: null, boissonChoice: null },
      showBoissonOnlyMenuCard: true,
    },
    global: { plugins: [i18n] },
  });

describe('KioskStepMenu — prix par option formule (borne)', () => {
  it('affiche +2,50 / +1,90 / +1,90 sur les cartes full / frites / boisson', () => {
    const wrapper = mountStep();
    const cards = wrapper.findAll('.kiosk-menu-card');
    // full, frites, boisson, none
    expect(cards.length).toBe(4);

    const fullPrice = cards[0].find('.kiosk-menu-card-price');
    const fritesPrice = cards[1].find('.kiosk-menu-card-price');
    const boissonPrice = cards[2].find('.kiosk-menu-card-price');

    expect(fullPrice.exists()).toBe(true);
    expect(fritesPrice.exists()).toBe(true);
    expect(boissonPrice.exists()).toBe(true);

    expect(fullPrice.text()).toContain('+2,50');
    expect(fritesPrice.text()).toContain('+1,90');
    expect(boissonPrice.text()).toContain('+1,90');
  });

  it("n'affiche AUCUN prix sur la carte « Sans menu » (0 €)", () => {
    const wrapper = mountStep();
    const cards = wrapper.findAll('.kiosk-menu-card');
    const nonePrice = cards[3].find('.kiosk-menu-card-price');
    expect(nonePrice.exists()).toBe(false);
  });

  it('menuChoicePrice() reflète le SSOT addon (2,50 × ratios) sans muter les prix', () => {
    const wrapper = mountStep();
    const vm = wrapper.vm;
    expect(vm.menuChoicePrice('full')).toBeCloseTo(2.5, 2);
    expect(vm.menuChoicePrice('frites')).toBeCloseTo(1.9, 2);
    expect(vm.menuChoicePrice('boisson')).toBeCloseTo(1.9, 2);
    expect(vm.menuChoicePrice('none')).toBe(0);
  });
});
