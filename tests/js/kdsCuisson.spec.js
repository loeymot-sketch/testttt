import { describe, it, expect } from 'vitest';
import { cuissonForOrder, meatPortionsForItem, renderCuisson } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [CUISSON 2026-08-06 owner] Jumeau JS du bandeau de cuisson.
 *
 * Les attendus de ce fichier sont EXACTEMENT ceux de la sentinelle PHP
 * tests/Feature/Kitchen/MeatPortionCalculatorTest.php. C'est le verrou de parité :
 * l'écran cuisine et le ticket imprimé doivent annoncer la même chose au même cuisinier,
 * et les mêmes portions alimentent la consommation de stock.
 */

/** Snapshot à la forme canonique de production (clé `lines`). */
const snap = (viandes, extras = []) => ({
    lines: [
        ...viandes.map((v, i) => ({ attribute_name: `Viande ${i + 1}`, variation_name: v })),
        // Une ligne non-viande DOIT être ignorée : sinon la sauce finirait à la plancha.
        { attribute_name: 'Sauce 1', variation_name: 'Algérienne' },
    ],
    extras,
});

const item = (item_name, viandes, quantity = 1, extras = [], instruction = '') => ({
    item_name, quantity, instruction, composition_snapshot: snap(viandes, extras),
});

const ligne = (...args) => {
    const r = meatPortionsForItem(item(...args));
    return renderCuisson(r.pieces, r.inconnu ? (args[2] || 1) : 0);
};

describe('bandeau de cuisson — règle de portion owner', () => {
    it('un produit à UNE viande reçoit la portion complète (2 pièces)', () => {
        expect(ligne('Tacos M', ['Viande Hachée'])).toBe('2K');
        expect(ligne('Cayenne', ['Viande Hachée'])).toBe('2K');
        expect(ligne('Galette Normale', ['Poulet mariné'])).toBe('2P');
        expect(ligne('Bol Riz', ['Cordon Bleu'])).toBe('2Cordon');
    });

    it('un produit à DEUX viandes reçoit une demi-portion de chacune', () => {
        expect(ligne('Méga', ['Viande Hachée', 'Poulet mariné'])).toBe('1K 1P');
        expect(ligne('Tacos L', ['Mexicanos', 'Cordon Bleu'])).toBe('1Cordon 1Mex');
    });

    it('deux fois la même viande se recompose en portion pleine', () => {
        expect(ligne('Terminator', ['Viande Hachée', 'Viande Hachée'])).toBe('2K');
    });

    it('le choix « Mixte » partage son emplacement entre ses deux viandes', () => {
        expect(ligne('Cayenne', ['Mixte (hachée + poulet)'])).toBe('1K 1P');
    });

    it('la quantité de ligne multiplie les pièces', () => {
        expect(ligne('Tacos M', ['Viande Hachée'], 3)).toBe('6K');
    });

    it('le supplément viande vaut une portion complète et est nommé depuis l’instruction', () => {
        const extra = [{ extra_name: 'Viande supplémentaire', quantity: 1 }];
        expect(ligne('Cayenne', ['Poulet mariné'], 1, extra, 'Viandes en plus : Viande Hachée')).toBe('2K 2P');
    });

    it('un supplément non nommable reste VISIBLE plutôt que de disparaître', () => {
        const rendu = ligne('Cayenne', ['Poulet mariné'], 1, [{ extra_name: 'Viande supplémentaire', quantity: 1 }]);
        expect(rendu).toContain('?');
        expect(rendu).toContain('2P');
    });

    it('une recette non déclarée en base est signalée, jamais devinée', () => {
        for (const nom of ['Big Burger', 'Cheese Burger', 'Suprême', 'Menu Enfant Nuggets']) {
            const r = meatPortionsForItem(item(nom, []));
            expect(r.inconnu, `${nom} doit être signalé inconnu`).toBe(true);
            expect(Object.keys(r.pieces), `${nom} ne doit produire aucune pièce inventée`).toEqual([]);
        }
    });

    it('un produit sans viande ne produit rien', () => {
        const r = meatPortionsForItem(item('Frites', []));
        expect(r.inconnu).toBe(false);
        expect(renderCuisson(r.pieces, 0)).toBe('');
    });

    it('une ligne qui n’est pas une viande n’atteint jamais la plancha', () => {
        const r = meatPortionsForItem({
            item_name: 'Tacos M', quantity: 1,
            composition_snapshot: { lines: [
                { attribute_name: 'Sauce 1', variation_name: 'Algérienne' },
                { attribute_name: 'Crudités', variation_name: 'Salade' },
                { attribute_name: 'Taille', variation_name: 'M' },
            ] },
        });
        expect(Object.keys(r.pieces)).toEqual([]);
    });
});

describe('bandeau de cuisson — agrégation de toute la commande', () => {
    it('assemble toutes les viandes en une seule ligne', () => {
        const o = cuissonForOrder([
            item('Tacos M', ['Viande Hachée'], 3),                    // 6K
            item('Méga', ['Viande Hachée', 'Poulet mariné'], 2),      // 2K 2P
            item('Galette Cayenne', ['Poulet mariné'], 1),            // 2P
            item('Frites', [], 2),                                    // rien
        ]);
        expect(o.texte).toBe('8K 4P');
        expect(o.inconnus).toBe(0);
    });

    it('compte les recettes inconnues à part et les annonce', () => {
        const o = cuissonForOrder([item('Tacos M', ['Viande Hachée'], 1), item('Big Burger', [], 2)]);
        expect(o.inconnus).toBe(2);
        expect(o.texte).toBe('2K 2×?');
    });
});
