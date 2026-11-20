/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P1-11 / P1-65 / triage A19]
 *
 * DÉFAUT D'ARGENT RÉEL, pas un défaut d'affichage.
 *
 * « Sans sauce » n'est pas une option payante : sa variation vaut 0,00 €
 * (pinné par `EnsureCayenneMixteCommandTest`). Mais le wizard borne facture les
 * sauces au DÉCOMPTE : le parent gelé `KioskWizardComponent.vue` calcule
 *     extraSauceN = max(0, selections.sauceOrder.length - 1)
 * et pousse autant d'exemplaires de l'extra « Sauce supplémentaire » (0,50 €),
 * montant ensuite SCELLÉ par PricingService. Comme rien ne rendait « Sans
 * sauce » exclusif, elle pouvait être cochée EN PLUS de vraies sauces et
 * comptait alors comme une sauce payante : le client payait 0,50 € pour ne PAS
 * avoir de sauce. Le rapport le mesure : Menu Enfant Nuggets 4,90 € +
 * Ketchup + Mayonnaise + « Sans sauce » = 5,90 € (= 4,90 + 2 × 0,50).
 *
 * CHOIX DE CORRECTIF — la zone gelée §7 n'est PAS touchée. Le décompte vit dans
 * `KioskWizardComponent.vue` (gelé) et `public/js/pos-wizard.js` (gelé). Mais la
 * SOURCE de `sauceOrder` est `KioskStepSauceComponent.toggleSauce()`, qui n'est
 * PAS gelé. En y rendant « Sans sauce » exclusive — exactement la règle que le
 * rapport demande (« si sélectionné, désélectionner toutes les sauces et
 * interdire l'ajout de sauce ») — `sauceOrder` ne peut plus valoir
 * ['ketchup','mayo','sans_sauce'] : il vaut ['sans_sauce'], donc
 * extraSauceN = 0 et AUCUN extra n'est poussé. Le défaut d'argent tombe sans
 * gate propriétaire.
 *
 * ⚠️ La caisse (`public/js/pos-wizard.js`, gelé, 6 sites de décompte) reste
 * exposée au même défaut : elle exige un gate propriétaire + LOCK. C'est
 * escaladé dans le rapport QA, pas corrigé ici en silence.
 *
 * Le site vitrine, lui, est déjà protégé depuis 2026-07-31 (mécanisme `solo` de
 * `wizard-v2.jsx`) — donc P1-65 est RÉFUTÉ sur la surface qu'il désigne, et
 * CONFIRMÉ sur borne + caisse.
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { sauceIsNoSauceOption } from '../../resources/js/helpers/sauceExclusive';
import KioskStepSauceComponent from '../../resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue';

/** Reproduit EXACTEMENT le calcul du parent gelé KioskWizardComponent.vue. */
const extraSauceNFromOrder = (sauceOrder) => Math.max(0, (sauceOrder || []).length - 1);
const PRIX_SAUCE_EXTRA = 0.5;

describe('helper sauceIsNoSauceOption', () => {
  it('reconnaît « Sans sauce » et ses alias canoniques', () => {
    expect(sauceIsNoSauceOption('Sans sauce')).toBe(true);
    expect(sauceIsNoSauceOption('sans sauce')).toBe(true);
    expect(sauceIsNoSauceOption('SANS SAUCE')).toBe(true);
    expect(sauceIsNoSauceOption('  Sans  sauce  ')).toBe(true);
    expect(sauceIsNoSauceOption('Aucune sauce')).toBe(true);
    expect(sauceIsNoSauceOption('Pas de sauce')).toBe(true);
  });

  it('ne confond pas une vraie sauce avec l’option « sans sauce »', () => {
    ['Ketchup', 'Mayonnaise', 'Sauce Algérienne', 'Samouraï', 'Harissa', 'Blanche', 'Spicy maison']
      .forEach((n) => expect(sauceIsNoSauceOption(n)).toBe(false));
  });

  it('ne jette pas et reste faux sur une entrée vide', () => {
    expect(sauceIsNoSauceOption(null)).toBe(false);
    expect(sauceIsNoSauceOption(undefined)).toBe(false);
    expect(sauceIsNoSauceOption('')).toBe(false);
  });

  it('lit la SSOT servie par la page quand elle est présente (config/pos_sauces.php)', () => {
    const previous = globalThis.window;
    globalThis.window = {
      POS_WIZARD_CONFIG: {
        sauceStyles: [
          { key: 'ketchup', name: 'Ketchup', aliases: ['ketchup'] },
          { key: 'sans_sauce', name: 'Sans sauce', aliases: ['sans sauce', 'aucune sauce', 'pas de sauce', 'zero sauce'] },
        ],
      },
    };
    try {
      // Alias présent SEULEMENT dans la SSOT servie, absent du miroir par défaut.
      expect(sauceIsNoSauceOption('Zéro sauce')).toBe(true);
      expect(sauceIsNoSauceOption('Ketchup')).toBe(false);
    } finally {
      globalThis.window = previous;
    }
  });
});

describe('KioskStepSauceComponent.toggleSauce — « Sans sauce » est exclusive', () => {
  const KETCHUP = { id: 101, name: 'Ketchup' };
  const MAYO = { id: 102, name: 'Mayonnaise' };
  const SANS = { id: 697, name: 'Sans sauce' };

  let ctx;
  let emissions;

  beforeEach(() => {
    emissions = [];
    ctx = {
      localSelections: {},
      sauceOrder: [],
      activeFilters: [],
      // `sauceList` est la propriété calculée que rend le gabarit : c'est la
      // seule correspondance clé -> nom, donc toujours peuplée à l'écran.
      sauceList: [KETCHUP, MAYO, SANS],
      sauceFilterAllowed: () => true,
      isSauceOos: () => false,
      sauceKey: KioskStepSauceComponent.methods.sauceKey,
      $emit: (evt, field, value) => emissions.push([evt, field, value]),
    };
  });

  afterEach(() => {
    emissions = [];
  });

  const toggle = (sauce) => KioskStepSauceComponent.methods.toggleSauce.call(ctx, sauce);

  it('cocher deux vraies sauces reste possible (règle métier inchangée)', () => {
    toggle(KETCHUP);
    toggle(MAYO);

    expect(ctx.sauceOrder).toEqual([101, 102]);
    expect(extraSauceNFromOrder(ctx.sauceOrder)).toBe(1); // la 2e sauce reste payante
  });

  it('cocher « Sans sauce » désélectionne toutes les sauces déjà choisies', () => {
    toggle(KETCHUP);
    toggle(MAYO);
    toggle(SANS);

    expect(ctx.sauceOrder).toEqual([697]);
    expect(Object.keys(ctx.localSelections)).toEqual(['697']);
  });

  it('cocher une vraie sauce retire « Sans sauce »', () => {
    toggle(SANS);
    expect(ctx.sauceOrder).toEqual([697]);

    toggle(KETCHUP);

    expect(ctx.sauceOrder).toEqual([101]);
    expect(Object.keys(ctx.localSelections)).toEqual(['101']);
  });

  it('décocher « Sans sauce » fonctionne normalement', () => {
    toggle(SANS);
    toggle(SANS);

    expect(ctx.sauceOrder).toEqual([]);
    expect(ctx.localSelections).toEqual({});
  });

  /**
   * L'ASSERTION D'ARGENT. C'est le scénario exact du rapport. On reproduit le
   * calcul du parent gelé pour prouver que plus aucun extra n'est facturé.
   */
  it('le scénario du rapport (Ketchup + Mayonnaise + Sans sauce) ne facture plus rien', () => {
    toggle(KETCHUP);
    toggle(MAYO);
    toggle(SANS);

    const extraSauceN = extraSauceNFromOrder(ctx.sauceOrder);

    expect(ctx.sauceOrder).toEqual([697]);
    expect(extraSauceN).toBe(0);
    expect(extraSauceN * PRIX_SAUCE_EXTRA).toBe(0);

    // Menu Enfant Nuggets : 4,90 € et non 5,90 € comme observé par le rapport.
    expect(4.9 + extraSauceN * PRIX_SAUCE_EXTRA).toBeCloseTo(4.9, 2);
  });

  it('émet bien les deux champs que le parent consomme (sauces + sauceOrder)', () => {
    toggle(SANS);

    const champs = emissions.filter(([evt]) => evt === 'update').map(([, field]) => field);
    expect(champs).toContain('sauces');
    expect(champs).toContain('sauceOrder');
  });
});
