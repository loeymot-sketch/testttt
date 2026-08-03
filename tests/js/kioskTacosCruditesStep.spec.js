import { describe, it, expect } from 'vitest';
import { createI18n } from 'vue-i18n';
import { mount } from '@vue/test-utils';
import KioskStepGarnituresComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue';
import { partitionKioskExtras } from '../../resources/js/helpers/kioskExtrasPartition.js';
import frMessages from '../../resources/js/languages/fr.json';

/**
 * [A-MEGA-BORNE 2026-07-22] SENTINELLE — un Tacos sur la borne DOIT proposer
 * l'étape « Crudités » (Salade / Tomate / Oignon / Oignons cuits, sélectionnables).
 *
 * Rappel du mécanisme (KioskWizardComponent FROZEN) : le template 'tacos' liste
 * un step { type:'garnitures' } filtré par shouldShowStep('garnitures') =
 * partitionKioskExtras(item).garnitures.length > 0. La crudité = ItemExtra
 * gratuit (prix 0) non-sauce, group_label='crudite'. Si la DB perd ces extras
 * (régression type OwnerMenuUpdate20260623Seeder::clearGarnitures), l'étape
 * disparaît silencieusement. Ce test verrouille l'exigence owner au niveau de
 * la donnée+rendu, indépendamment du fichier frozen.
 *
 * Fixture = miroir exact du payload borne réel (KioskMenuService) pour Tacos M
 * (#26) / Tacos L (#97) : 4 crudités @0 + suppléments @0,90 + sauce supp @0,50.
 */

const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr: frMessages },
});

// Miroir du payload borne réel — extras d'un tacos (group_label + prix réels).
const buildTacosItem = () => ({
  id: 26,
  name: 'Tacos M',
  has_menu: true,
  convert_price: 6.9,
  extras: [
    { id: 244, name: 'Salade', price: 0, convert_price: 0, group_label: 'crudite', status: 5, is_available: true },
    { id: 245, name: 'Tomate', price: 0, convert_price: 0, group_label: 'crudite', status: 5, is_available: true },
    { id: 246, name: 'Oignon', price: 0, convert_price: 0, group_label: 'crudite', status: 5, is_available: true },
    { id: 247, name: 'Oignons frits', price: 0.9, convert_price: 0.9, group_label: 'supplement', status: 5, is_available: true },
    { id: 250, name: 'Cheddar', price: 0.9, convert_price: 0.9, group_label: 'supplement', status: 5, is_available: true },
    { id: 392, name: 'Viande supplémentaire', price: 2.5, convert_price: 2.5, group_label: 'supplement', status: 5, is_available: true },
    { id: 424, name: 'Oignons cuits', price: 0, convert_price: 0, group_label: 'crudite', status: 5, is_available: true },
    { id: 431, name: 'Sauce supplémentaire', price: 0.5, convert_price: 0.5, group_label: 'sauce', status: 5, is_available: true },
  ],
});

describe('Tacos borne — étape Crudités (garnitures) présente', () => {
  it('partitionKioskExtras place les 4 crudités gratuites en garnitures (→ step visible)', () => {
    const part = partitionKioskExtras(buildTacosItem());
    const names = part.garnitures.map((g) => g.name);
    expect(names).toEqual(expect.arrayContaining(['Salade', 'Tomate', 'Oignon', 'Oignons cuits']));
    // Le suppl. @0,90, la viande @2,50 et la sauce @0,50 ne sont PAS des crudités.
    expect(names).not.toContain('Cheddar');
    expect(names).not.toContain('Viande supplémentaire');
    expect(names).not.toContain('Sauce supplémentaire');
    // Garantit shouldShowStep('garnitures') === (length > 0) === true.
    expect(part.garnitures.length).toBeGreaterThan(0);
  });

  it("rend l'étape Crudités avec Salade/Tomate/Oignon sélectionnables (pas d'empty state)", () => {
    const wrapper = mount(KioskStepGarnituresComponent, {
      props: {
        step: {},
        item: buildTacosItem(),
        selections: { garnitures: {} },
        activeFilters: [],
      },
      global: { plugins: [i18n] },
    });

    expect(wrapper.find('.kiosk-step-empty').exists()).toBe(false);
    const rowNames = wrapper.findAll('.kiosk-garniture-name').map((n) => n.text());
    expect(rowNames).toEqual(expect.arrayContaining(['Salade', 'Tomate', 'Oignon', 'Oignons cuits']));

    // Sélectionnable : toggler Salade émet une mise à jour garnitures.
    const rows = wrapper.findAll('.kiosk-garniture-row');
    expect(rows.length).toBe(4);
  });
});
