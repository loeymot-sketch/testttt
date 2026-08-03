import { describe, expect, it } from 'vitest';

import {
    extraViandeNames,
    extraSauceNames,
    extraDisplayName,
    renderItemSymbolic,
    buildSymbolic,
} from '../../resources/js/helpers/kdsSymbolic.js';

// [MULTIVIANDE 2026-07-24] Le supplément de viande est véhiculé par un ItemExtra GÉNÉRIQUE
// « Viande supplémentaire » (@2,50, aucun nom) → le cuisinier lit « ⭐ Viande supplémentaire ×N »
// sans savoir QUELLE viande. Son nom réel ne survit que dans le texte libre `instruction`, sur
// une ligne DÉDIÉE écrite par les wizards en MIROIR de « Sauces en plus : … » :
// « Viandes en plus : <noms> ». Ces tests (jumeau JS du PHP KitchenTicketViandeSupplNameTest)
// prouvent la PARITÉ stricte PHP↔JS : mêmes entrées → mêmes noms.

describe('extraViandeNames — récupération depuis l\'instruction (miroir extraSauceNames)', () => {
    it('ligne dédiée "Viandes en plus : <noms>" (caisse + borne/web) → extras seuls', () => {
        expect(extraViandeNames('TACOS M\nViandes en plus : Poulet, Merguez'))
            .toEqual(['Poulet', 'Merguez']);
        // Borne mono-ligne jointe par ". " : capture jusqu'au point suivant.
        expect(extraViandeNames('Pain : Pain. Viandes : Poulet. Viandes en plus : Merguez. Menu : complet'))
            .toEqual(['Merguez']);
        expect(extraViandeNames('Viandes en plus : Merguez')).toEqual(['Merguez']);
    });

    it('tolère la formulation « Viande(s) supplémentaire(s) », les accents (é/e) et la casse', () => {
        expect(extraViandeNames('Viande supplémentaire : Poulet')).toEqual(['Poulet']);
        expect(extraViandeNames('viandes supplementaires : Poulet')).toEqual(['Poulet']); // sans accent
        expect(extraViandeNames('VIANDES EN PLUS : Merguez')).toEqual(['Merguez']);        // majuscules
        expect(extraViandeNames('Viandes en plus : +Merguez')).toEqual(['Merguez']);       // « + » retiré
    });

    it('déduplique (ordre préservé), ignore la ligne de BASE et le non-parsable', () => {
        expect(extraViandeNames('Viandes en plus : Poulet, Merguez, Poulet')).toEqual(['Poulet', 'Merguez']);
        // La ligne composition de base « Viandes : … » n'est JAMAIS captée (base = déjà ligne 1).
        expect(extraViandeNames('TACOS M\nViandes : Poulet, Merguez')).toEqual([]);
        expect(extraViandeNames('Bien cuit svp')).toEqual([]);
        expect(extraViandeNames('')).toEqual([]);
        expect(extraViandeNames(null)).toEqual([]);
    });
});

describe('extraDisplayName — nomme la viande générique, laisse les autres intacts', () => {
    it('nomme « Viande supplémentaire »', () => {
        expect(extraDisplayName('Viande supplémentaire', 'Viandes en plus : Poulet, Merguez'))
            .toBe('Viande supplémentaire : Poulet, Merguez');
    });
    it('rétro-compat : sans nom parsable → générique inchangé (pas de crash)', () => {
        expect(extraDisplayName('Viande supplémentaire', 'TACOS M')).toBe('Viande supplémentaire');
        expect(extraDisplayName('Viande supplémentaire', null)).toBe('Viande supplémentaire');
    });
    it('laisse les extras déjà nommés intacts ; une instruction SAUCE ne renomme pas une viande', () => {
        expect(extraDisplayName('Cheddar', 'Viandes en plus : Poulet')).toBe('Cheddar');
        expect(extraDisplayName('Viande supplémentaire', 'Sauce : Algérienne, Andalouse')).toBe('Viande supplémentaire');
    });
});

// [MULTIVIANDE 2026-07-24] Ligne supplément du KDS : le nom du/des viande(s) est ajouté, le
// ×N (quantité) déjà présent est conservé. PHP twin: KitchenTicketViandeSupplNameTest.
describe('renderItemSymbolic — la viande en plus est nommée sur la ligne supplément', () => {
    const makeItem = (instruction, extraQty = 1) => ({
        item_name: 'Tacos M',
        quantity: 1,
        instruction,
        composition_snapshot: {
            lines: [
                { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
                { attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' },
            ],
            extras: [
                { extra_name: 'Salade', unit_price: 0, line_total: 0, quantity: 1 },
                { extra_name: 'Viande supplémentaire', unit_price: 2.5, line_total: 2.5 * extraQty, quantity: extraQty },
            ],
            addons: [],
        },
    });

    it('nomme les 2 viandes en plus SANS ×N redondant (chaque unité déjà énumérée)', () => {
        // [OWNER 2026-08-03] « Poulet, Merguez ×2 » se lisait « 2× chaque » → suffixe supprimé
        // quand les noms sont résolus (le générique non résolu garde son ×N, testé PHP+JS).
        const res = renderItemSymbolic(makeItem('TACOS M\nViandes en plus : Poulet, Merguez', 2));
        const supp = res.lines.find((l) => l.type === 'supplement');
        expect(supp).toBeTruthy();
        expect(supp.label).toContain('Poulet');
        expect(supp.label).toContain('Merguez');
        expect(supp.label).not.toContain('×2');
    });

    it('cas 1 viande', () => {
        const res = renderItemSymbolic(makeItem('TACOS M. Viandes en plus : Merguez.', 1));
        const supp = res.lines.find((l) => l.type === 'supplement');
        expect(supp.label).toContain('Merguez');
    });

    it('rétro-compat : sans nom parsable → « Viande supplémentaire » générique reste en supplément', () => {
        const res = renderItemSymbolic(makeItem('TACOS M', 1));
        const supp = res.lines.find((l) => l.type === 'supplement');
        expect(supp).toBeTruthy();
        expect(supp.label).toContain('Viande suppl');
        expect(supp.label).not.toContain(' : ');
    });
});

// Non-régression sauce : le mécanisme viande est ADDITIF (canaux disjoints).
describe('non-régression sauce', () => {
    it('extraSauceNames continue de fonctionner ; extraViandeNames ne capte pas une sauce', () => {
        expect(extraSauceNames('Sauce : Algérienne, Andalouse')).toEqual(['Andalouse']);
        expect(extraViandeNames('Sauce : Algérienne, Andalouse')).toEqual([]);
        expect(extraDisplayName('Sauce supplémentaire', 'Sauce : Algérienne, Andalouse'))
            .toBe('Sauce supplémentaire : Andalouse');
    });
});

describe('[OWNER 2026-08-03] suffixe ×N redondant quand les noms sont résolus', () => {
  const snapExtras = { extras: [{ extra_name: 'Viande supplémentaire', quantity: 2, unit_price: 2.5 }] };
  it('noms résolus → pas de ×2 (chaque unité déjà énumérée)', () => {
    const s = buildSymbolic({ item_name: 'Tacos L', composition_snapshot: snapExtras,
      instruction: 'TACOS L\nViandes en plus : Viande Hachée, Poulet mariné' });
    expect(s.supplements).toEqual(['+ Viande supplémentaire : Viande Hachée, Poulet mariné']);
  });
  it('legacy non résolu → le générique GARDE ×2', () => {
    const s = buildSymbolic({ item_name: 'Tacos L', composition_snapshot: snapExtras, instruction: '' });
    expect(s.supplements).toEqual(['+ Viande supplémentaire ×2']);
  });
});
