/**
 * [OWNER 2026-08-25 · LOCK_KIOSK_WIZARD_MODIFIER_DEPUIS_RECAP_2026-08-25]
 * Modifier un produit DÉJÀ au panier, sans tout recomposer.
 *
 * LE BESOIN, dans les mots du propriétaire : « s'il veut modifier un produit du panier,
 * ça va ouvrir le récap, et à côté de chaque chose il pourra le modifier, et ça ouvre
 * directement la page dédiée — s'il veut changer la viande, s'il veut changer la formule ».
 *
 * CE QUI EXISTAIT DÉJÀ (P-MEGA-05) : le panier savait rouvrir le wizard sur la ligne, avec
 * ses sélections restaurées, et remplacer la ligne à la validation.
 * CE QUI MANQUAIT, et que ces tests verrouillent :
 *   · l'édition rouvrait à l'ÉTAPE 1 — corriger une sauce imposait de reparcourir viande,
 *     sauce, suppléments, formule ;
 *   · le récap n'offrait AUCUN moyen d'atteindre une étape précise ;
 *   · le stepper n'est pas cliquable, donc il n'existait aucun raccourci.
 *
 * On teste le CONTRAT (quelle étape est active, quel événement est émis) plutôt qu'un
 * parcours DOM : un scénario piloté à l'écran se casse sur une animation ou un 401 d'auth
 * et ne dit alors plus rien de la logique.
 */
import { describe, it, expect, vi } from 'vitest';
import { shallowMount, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import KioskWizardComponent from '../../resources/js/components/frontend/kiosk/KioskWizardComponent.vue';
import KioskOrderSummaryComponent from '../../resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue';
import frMessages from '../../resources/js/languages/fr.json';

const i18n = () => createI18n({
  legacy: false, locale: 'fr', fallbackLocale: 'fr', messages: { fr: frMessages },
  missingWarn: false, fallbackWarn: false,
});

/** Tacos à 2 viandes + sauce + formule : assez d'étapes pour qu'un saut soit mesurable. */
const produit = () => ({
  id: 234,
  name: 'Tacos XL',
  category_name: 'Tacos',
  wizard_template: 'tacos',
  has_menu: true,
  convert_price: '10.90',
  currency_price: '10,90 €',
  itemAttributes: [{ id: 1, name: 'Viande 1' }, { id: 5, name: 'Sauce (1ère Gratuite)' }],
  variations: {
    1: [{ id: 11, name: 'Mexicanos', convert_price: '0', price: 0, status: 5 }],
    5: [{ id: 51, name: 'Mayonnaise', convert_price: '0', price: 0, status: 5 }],
  },
  extras: [{ id: 301, name: 'Cheddar', convert_price: '0.90', price: 0.9 }],
  addons: [],
});

function monterWizard({ enEdition = false } = {}) {
  return shallowMount(KioskWizardComponent, {
    props: { item: produit(), onAddToCart: vi.fn(), onClose: vi.fn() },
    global: {
      plugins: [i18n()],
      stubs: {
        KioskStepPain: true, KioskStepTaille: true, KioskStepViande: true,
        KioskStepSauce: true, KioskStepGarnitures: true, KioskStepSupplements: true,
        KioskStepFritesStyle: true, KioskStepMenu: true, KioskStepGenericChoices: true,
        KioskOrderSummary: true, KsAllergenBadge: true, transition: false,
      },
      mocks: {
        $store: {
          getters: {
            'kioskFilter/activeFilters': [],
            'kioskSettings/customerProfile': null,
            'kioskCart/isEditingCart': enEdition,
          },
          state: { globalState: { lists: {} }, kioskCart: { editingCartSnapshot: null } },
          dispatch: vi.fn(),
        },
        $router: { go: vi.fn(), push: vi.fn(), replace: vi.fn() },
      },
    },
  });
}

describe('borne — modifier un produit du panier rouvre son RÉCAP', () => {
  it('une PREMIÈRE composition commence bien à la première étape', () => {
    // Garde-fou : le saut au récap ne doit jamais toucher un client qui compose
    // son produit pour la première fois.
    expect(monterWizard({ enEdition: false }).vm.currentStepIndex).toBe(0);
  });

  it('une MODIFICATION depuis le panier ouvre directement sur le récap', () => {
    const vm = monterWizard({ enEdition: true }).vm;
    expect(vm.currentStepIndex).toBe(vm.recapStepIndex());
    expect(vm.activeSteps[vm.currentStepIndex].type).toBe('recap');
  });

  it('le récap est toujours la DERNIÈRE étape — on rouvre donc sur une vue complète', () => {
    const vm = monterWizard({ enEdition: true }).vm;
    expect(vm.recapStepIndex()).toBe(vm.activeSteps.length - 1);
  });
});

describe('borne — « Modifier » saute à la bonne étape', () => {
  it('saute à l\'étape demandée par son TYPE', () => {
    const vm = monterWizard({ enEdition: true }).vm;
    const cible = vm.activeSteps.find((s) => s.type === 'viande');
    expect(cible, 'le produit de test doit avoir une étape viande').toBeTruthy();

    vm.goToStepType('viande');
    expect(vm.activeSteps[vm.currentStepIndex].type).toBe('viande');
  });

  it('un type INCONNU ne déplace pas le client', () => {
    // Envoyer le client sur une étape au hasard est pire que de ne rien faire :
    // il perdrait le fil de ce qu'il était en train de corriger.
    const vm = monterWizard({ enEdition: true }).vm;
    const avant = vm.currentStepIndex;
    vm.goToStepType('etape-qui-nexiste-pas');
    expect(vm.currentStepIndex).toBe(avant);
  });

  it('un type vide ou absent ne casse rien', () => {
    const vm = monterWizard({ enEdition: true }).vm;
    const avant = vm.currentStepIndex;
    [null, undefined, '', 0, false].forEach((mauvais) => {
      expect(() => vm.goToStepType(mauvais)).not.toThrow();
    });
    expect(vm.currentStepIndex).toBe(avant);
  });

  it('on peut enchaîner plusieurs sauts sans se perdre', () => {
    const vm = monterWizard({ enEdition: true }).vm;
    const types = vm.activeSteps.map((s) => s.type);
    types.forEach((t) => {
      vm.goToStepType(t);
      expect(vm.activeSteps[vm.currentStepIndex].type).toBe(t);
    });
  });
});

/** Récap monté seul : c'est lui qui porte les boutons et émet le type d'étape. */
function monterRecap(selections) {
  return mount(KioskOrderSummaryComponent, {
    props: {
      step: { type: 'recap' },
      item: { id: 234, name: 'Tacos XL', convert_price: '10.90', thumb: null, extras: [] },
      selections: {
        pain: null, taille: null, viandes: {}, totalViandes: 0, _viandeMeta: [],
        sauceOrder: [], garnitures: {}, supplements: {}, menuChoice: null,
        fritesSauceOrder: [], composerChoices: {}, quantity: 1, instruction: '',
        ...selections,
      },
    },
    global: { plugins: [i18n()], stubs: { KsBadge: true, KsAllergenBadge: true } },
  });
}

describe('borne — chaque ligne du récap porte son « Modifier »', () => {
  it('les viandes choisies exposent un bouton qui émet le type « viande »', async () => {
    const w = monterRecap({ totalViandes: 2, _viandeMeta: [{ id: 11, key: 'v_11', name: 'Mexicanos', count: 2, source: 'variation', price: 0 }] });
    const bouton = w.find('[data-testid="kiosk-summary-edit-viande"]');
    expect(bouton.exists(), 'bouton Modifier des viandes').toBe(true);

    await bouton.trigger('click');
    expect(w.emitted('modifier')).toBeTruthy();
    expect(w.emitted('modifier')[0]).toEqual(['viande']);
  });

  it('une section SANS choix n\'affiche aucun bouton — il n\'y a rien à modifier', () => {
    const w = monterRecap({});
    ['viande', 'sauce', 'garnitures', 'supplements', 'menu', 'pain', 'frites_sauce'].forEach((t) => {
      expect(w.find(`[data-testid="kiosk-summary-edit-${t}"]`).exists()).toBe(false);
    });
  });

  it('la formule expose son propre bouton, distinct de celui des viandes', async () => {
    const w = monterRecap({ menuChoice: 'menu_full', totalViandes: 1, _viandeMeta: [{ id: 11, key: 'v_11', name: 'Mexicanos', count: 1, source: 'variation', price: 0 }] });
    const menu = w.find('[data-testid="kiosk-summary-edit-menu"]');
    expect(menu.exists(), 'bouton Modifier de la formule').toBe(true);

    await menu.trigger('click');
    expect(w.emitted('modifier')[0]).toEqual(['menu']);
  });

  it('le libellé est en FRANÇAIS, jamais une clé brute', () => {
    // La borne est verrouillée en français (ADR-007) et le mandat visuel interdit
    // qu'une clé i18n s'affiche telle quelle devant le client.
    const w = monterRecap({ totalViandes: 1, _viandeMeta: [{ id: 11, key: 'v_11', name: 'Mexicanos', count: 1, source: 'variation', price: 0 }] });
    const texte = w.find('[data-testid="kiosk-summary-edit-viande"]').text();
    expect(texte).toBe('Modifier');
    expect(texte).not.toContain('kiosk.');
  });
});
