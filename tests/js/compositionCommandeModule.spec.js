// [GOAL CAISSE CONTRÔLE 2026-09-02] Le module partagé de lecture d'une commande.
//
// POURQUOI CE MODULE EXISTE (et pourquoi ce banc le verrouille) :
// `resumeComposition`, `compoAffichee`, `nomProduit`, `itemsPreview`, `aDuContenuAVoir`,
// `elapsedShort` vivaient comme `methods:` de `PosOrdersTrackerComponent.vue`. Le tiroir de
// contrôle de la caisse a besoin des MÊMES règles. Les recopier garantissait la divergence :
// la troncature « +N » a déjà été corrigée DEUX fois (FIX-6/A-006 puis A-016) et un doublon
// aurait raté la seconde. Le module est donc la source unique, et les deux surfaces l'importent.
//
// Contrat verrouillé ici = celui qui existait AVANT l'extraction, à la virgule près.

import { describe, it, expect } from 'vitest';
import {
    BUDGET_COMPO,
    nomProduit,
    resumeComposition,
    compoAffichee,
    lignesCompletes,
    itemsPreview,
    extraItemsCount,
    aDuContenuAVoir,
    listeNommee,
    ageCourt,
    heureCourte,
} from '../../resources/js/support/compositionCommande.js';

const LIB_SUPPRIME = 'Article supprimé';

describe('nomProduit — jamais de ligne muette', () => {
    it('rend le nom du produit', () => {
        expect(nomProduit({ item_name: 'Tacos M' }, LIB_SUPPRIME)).toBe('Tacos M');
    });
    it('accepte la forme `name` (autres flux)', () => {
        expect(nomProduit({ name: 'Cayenne' }, LIB_SUPPRIME)).toBe('Cayenne');
    });
    it('replie sur le libellé fourni quand le produit a été retiré du catalogue', () => {
        expect(nomProduit({ item_name: null }, LIB_SUPPRIME)).toBe(LIB_SUPPRIME);
        expect(nomProduit({ item_name: '   ' }, LIB_SUPPRIME)).toBe(LIB_SUPPRIME);
        expect(nomProduit(null, LIB_SUPPRIME)).toBe(LIB_SUPPRIME);
    });
});

describe('resumeComposition — une ligne lisible en français', () => {
    it('enchaîne options, extras et suppléments avec « · »', () => {
        const ligne = {
            options: [{ label: 'Sauce', value: 'Algérienne' }, { label: 'Pain', value: 'Galette' }],
            extras: [{ name: 'Cheddar', quantity: 2 }],
            addons: [{ name: 'Frites' }],
        };
        expect(resumeComposition(ligne)).toBe('Algérienne · Galette · +2 Cheddar · +Frites');
    });
    it('note la quantité d’une option répétée', () => {
        expect(resumeComposition({ options: [{ value: 'Harissa', quantity: 2 }] })).toBe('Harissa ×2');
    });
    it('écarte les entrées sans valeur lisible plutôt que de rendre un « · » orphelin', () => {
        const ligne = { options: [{ value: '' }, { value: 'Curry' }], extras: [{ name: '  ' }] };
        expect(resumeComposition(ligne)).toBe('Curry');
    });
    it('rend une chaîne vide sur une ligne sans personnalisation', () => {
        expect(resumeComposition({ item_name: 'Coca' })).toBe('');
        expect(resumeComposition(null)).toBe('');
    });
});

describe('compoAffichee — la coupe est EXPLICITE, jamais une ellipse muette', () => {
    it('laisse passer entière une composition sous le budget', () => {
        const r = compoAffichee({ options: [{ value: 'Algérienne' }] });
        expect(r).toEqual({ texte: 'Algérienne', tronque: false, restants: 0 });
    });
    it('coupe sur une frontière « · » et annonce le reste', () => {
        const ligne = {
            options: [
                { value: 'Galette' }, { value: 'Algerienne' }, { value: 'Bien cuit' },
                { value: 'Sans oignons' }, { value: 'Supplement salade' }, { value: 'A emporter' },
            ],
        };
        const complet = resumeComposition(ligne);
        expect(complet.length).toBeGreaterThan(BUDGET_COMPO);
        const r = compoAffichee(ligne);
        expect(r.tronque).toBe(true);
        expect(r.restants).toBeGreaterThan(0);
        expect(r.texte.length).toBeLessThanOrEqual(BUDGET_COMPO);
        // Jamais coupé au milieu d'un mot : le texte gardé est un préfixe exact
        // de la composition complète, sur une frontière de séparateur.
        expect(complet.startsWith(r.texte)).toBe(true);
        expect(r.texte.split(' · ').length + r.restants).toBe(complet.split(' · ').length);
    });
    it('garde ENTIER un premier morceau plus long que le budget', () => {
        const long = 'x'.repeat(BUDGET_COMPO + 20);
        const r = compoAffichee({ options: [{ value: long }, { value: 'Curry' }] });
        expect(r.texte).toBe(long);
        expect(r.tronque).toBe(true);
        expect(r.restants).toBe(1);
    });
});

describe('itemsPreview / extraItemsCount — 3 lignes, et le reste annoncé', () => {
    const cmd = { order_items: [{ item_name: 'A' }, { item_name: 'B' }, { item_name: 'C' }, { item_name: 'D' }, { item_name: 'E' }] };
    it('montre les 3 premières lignes', () => {
        expect(itemsPreview(cmd)).toHaveLength(3);
        expect(itemsPreview(cmd)[2].item_name).toBe('C');
    });
    it('compte celles qui restent', () => {
        expect(extraItemsCount(cmd)).toBe(2);
        expect(extraItemsCount({ order_items: [{}] })).toBe(0);
        expect(extraItemsCount({})).toBe(0);
    });
    it('lignesCompletes rend toutes les lignes, ou un tableau vide', () => {
        expect(lignesCompletes(cmd)).toHaveLength(5);
        expect(lignesCompletes(null)).toEqual([]);
        expect(lignesCompletes({ order_items: 'pas un tableau' })).toEqual([]);
    });
});

describe('aDuContenuAVoir — un bouton qui n’ajoute rien est un bouton qui ment', () => {
    it('vrai au-delà de 3 lignes', () => {
        expect(aDuContenuAVoir({ order_items: [{}, {}, {}, {}] })).toBe(true);
    });
    it('vrai dès qu’une ligne porte une personnalisation', () => {
        expect(aDuContenuAVoir({ order_items: [{ options: [{ value: 'Curry' }] }] })).toBe(true);
        expect(aDuContenuAVoir({ order_items: [{ extras: [{ name: 'Cheddar' }] }] })).toBe(true);
        expect(aDuContenuAVoir({ order_items: [{ addons: [{ name: 'Frites' }] }] })).toBe(true);
    });
    it('vrai dès qu’une ligne porte une instruction non vide', () => {
        expect(aDuContenuAVoir({ order_items: [{ instruction: 'Sans oignons' }] })).toBe(true);
        expect(aDuContenuAVoir({ order_items: [{ instruction: '   ' }] })).toBe(false);
    });
    it('faux sur trois lignes nues', () => {
        expect(aDuContenuAVoir({ order_items: [{ item_name: 'A' }, { item_name: 'B' }] })).toBe(false);
        expect(aDuContenuAVoir(null)).toBe(false);
    });
});

describe('listeNommee — « 2× Cheddar, Salade »', () => {
    it('note la quantité au-delà de 1 et l’omet à 1', () => {
        expect(listeNommee([{ name: 'Cheddar', quantity: 2 }, { name: 'Salade' }])).toBe('2× Cheddar, Salade');
    });
    it('écarte les entrées sans nom', () => {
        expect(listeNommee([{ name: '' }, { name: 'Œuf' }])).toBe('Œuf');
        expect(listeNommee(null)).toBe('');
    });
});

describe('ageCourt — l’âge mesuré, jamais une prédiction', () => {
    const T0 = 1_800_000_000_000;
    it('rend le libellé « à l’instant » sous une minute', () => {
        expect(ageCourt(new Date(T0 - 30_000).toISOString(), 'à l’instant', T0)).toBe('à l’instant');
    });
    it('rend les minutes sous une heure', () => {
        expect(ageCourt(new Date(T0 - 14 * 60_000).toISOString(), 'à l’instant', T0)).toBe('14 min');
    });
    it('rend heures et minutes au-delà, minutes sur deux chiffres', () => {
        expect(ageCourt(new Date(T0 - 125 * 60_000).toISOString(), 'à l’instant', T0)).toBe('2h05');
        expect(ageCourt(new Date(T0 - 190 * 60_000).toISOString(), 'à l’instant', T0)).toBe('3h10');
    });
    it('rend une chaîne vide sur une date absente ou illisible', () => {
        expect(ageCourt(null, 'x', T0)).toBe('');
        expect(ageCourt('pas une date', 'x', T0)).toBe('');
    });
    it('ne rend jamais un âge négatif (horloge du poste en avance)', () => {
        expect(ageCourt(new Date(T0 + 600_000).toISOString(), 'à l’instant', T0)).toBe('à l’instant');
    });
});

describe('heureCourte — l’heure de commande, en 24 h', () => {
    it('rend HH:MM', () => {
        expect(heureCourte('2026-09-02T08:28:00Z')).toMatch(/^\d{2}:\d{2}$/);
    });
    it('rend une chaîne vide sur une date absente', () => {
        expect(heureCourte(null)).toBe('');
        expect(heureCourte('pas une date')).toBe('');
    });
});

describe('minutes écoulées — la brique du rang et des seuils de retard', () => {
    it('BUDGET_COMPO reste la constante partagée, exportée', () => {
        expect(BUDGET_COMPO).toBe(58);
    });
});
