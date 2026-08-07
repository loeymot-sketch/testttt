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
        // [owner 2026-08-07] Le POULET se compte en PORTIONS (1 = 200 g), la hachée en STEAKS.
        expect(ligne('Galette Normale', ['Poulet mariné'])).toBe('1P');
        expect(ligne('Bol Riz', ['Cordon Bleu'])).toBe('2Cordon');
    });

    it('un produit à DEUX viandes reçoit une demi-portion de chacune', () => {
        expect(ligne('Méga', ['Viande Hachée', 'Poulet mariné'])).toBe('1K 0,5P');
        expect(ligne('Tacos L', ['Mexicanos', 'Cordon Bleu'])).toBe('1Cordon 1Mex');
    });

    it('deux fois la même viande se recompose en portion pleine', () => {
        expect(ligne('Terminator', ['Viande Hachée', 'Viande Hachée'])).toBe('2K');
    });

    it('le choix « Mixte » partage son emplacement entre ses deux viandes', () => {
        expect(ligne('Cayenne', ['Mixte (hachée + poulet)'])).toBe('1K 0,5P');
    });

    it('la quantité de ligne multiplie les pièces', () => {
        expect(ligne('Tacos M', ['Viande Hachée'], 3)).toBe('6K');
    });

    it('le supplément viande vaut une portion complète et est nommé depuis l’instruction', () => {
        const extra = [{ extra_name: 'Viande supplémentaire', quantity: 1 }];
        expect(ligne('Cayenne', ['Poulet mariné'], 1, extra, 'Viandes en plus : Viande Hachée')).toBe('2K 1P');
    });

    it('un supplément non nommable reste VISIBLE plutôt que de disparaître', () => {
        const rendu = ligne('Cayenne', ['Poulet mariné'], 1, [{ extra_name: 'Viande supplémentaire', quantity: 1 }]);
        expect(rendu).toContain('?');
        expect(rendu).toContain('1P');
    });

    // Recettes FIXES — données owner 2026-08-06, confirmées contre la description produit.
    // Attendus IDENTIQUES au fournisseur PHP MeatPortionCalculatorTest::recettesFixes.
    it.each([
        ['Cheese Burger', '1K'],
        ['Double Cheese', '2K'],
        ['Grill Burger', '2K'],
        ['Big Burger', '3K'],
        ['Fish Burger', '1Poi'],
        ['Chicken Burger', '1Chick'],
        ['Suprême', '1K 1Cordon'],
        ['Menu Enfant Nuggets', '6Nug 1F'],
        ['Menu Enfant Chicken Burger', '1Chick 1F'],
    ])('recette fixe : %s → %s', (nom, attendu) => {
        const r = meatPortionsForItem(item(nom, []));
        expect(r.inconnu).toBe(false);
        expect(renderCuisson(r.pieces, 0)).toBe(attendu);
    });

    it('les recettes qui se chevauchent ne se volent pas', () => {
        expect(ligne('Double Cheese', [])).toBe('2K');
        expect(ligne('Cheese Burger', [])).toBe('1K');
        expect(ligne('Menu Enfant Chicken Burger', [])).toBe('1Chick 1F');
        expect(ligne('Chicken Burger', [])).toBe('1Chick');
    });

    it('un burger sans recette documentée reste signalé « ? »', () => {
        const r = meatPortionsForItem(item('Mystery Burger', []));
        expect(r.inconnu).toBe(true);
        expect(Object.keys(r.pieces)).toEqual([]);
    });

    it('un produit sans cuisson ne produit rien', () => {
        const r = meatPortionsForItem(item('Coca 33cl', []));
        expect(r.inconnu).toBe(false);
        expect(renderCuisson(r.pieces, 0)).toBe('');
    });
});

describe('bandeau de cuisson — frites', () => {
    const menuSnap = (viandes, qty) => ({
        item_name: 'Tacos M', quantity: qty, instruction: '',
        composition_snapshot: {
            ...snap(viandes),
            addons: [
                { role: 'menu_frites', quantity: 1, addon_name: 'Frites' },
                { role: 'menu_boisson', quantity: 1, addon_name: 'Coca 33cl' },
            ],
        },
    });

    it('compte une portion par menu — « 5 menus tu mets 5F »', () => {
        expect(renderCuisson(meatPortionsForItem(menuSnap(['Viande Hachée'], 1)).pieces, 0)).toBe('2K 1F');
        expect(renderCuisson(meatPortionsForItem(menuSnap(['Viande Hachée'], 5)).pieces, 0)).toBe('10K 5F');
    });

    it('une grande frite compte double', () => {
        expect(ligne('Frites', [])).toBe('1F');
        expect(ligne('Grande Frite', [])).toBe('2F');
    });

    it('la frite d’un menu enfant n’est jamais comptée deux fois', () => {
        const r = meatPortionsForItem({
            item_name: 'Menu Enfant Nuggets', quantity: 1,
            composition_snapshot: { ...snap([]), addons: [{ role: 'menu_frites', quantity: 1, addon_name: 'Frites' }] },
        });
        expect(renderCuisson(r.pieces, 0)).toBe('6Nug 1F');
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
            item('Frites', [], 2),                                    // 2F
        ]);
        expect(o.texte).toBe('8K 2P 2F');
        expect(o.inconnus).toBe(0);
    });

    /** L'exemple owner : 3 mixtes au poulet (0,5 chacun) + 1 Cayenne entier (1) = 2,5P. */
    it('donne bien 2,5 portions de poulet sur l’exemple owner', () => {
        const o = cuissonForOrder([
            item('Tacos L', ['Poulet mariné', 'Viande Hachée'], 2),
            item('Tacos L', ['Poulet mariné', 'Cordon Bleu'], 1),
            item('Cayenne', ['Poulet mariné'], 1),
        ]);
        expect(o.texte).toBe('2K 2,5P 1Cordon');
    });

    it('compte les recettes inconnues à part et les annonce', () => {
        const o = cuissonForOrder([item('Tacos M', ['Viande Hachée'], 1), item('Mystery Burger', [], 2)]);
        expect(o.inconnus).toBe(2);
        expect(o.texte).toBe('2K 2×?');
    });

    /** La commande décrite par l'owner : 5 menus tacos (10K 5F) + Big Burger (3K) + grande frite (2F). */
    it('mêle viandes, menus et frites en une seule ligne', () => {
        const menu = { role: 'menu_frites', quantity: 1, addon_name: 'Frites' };
        const o = cuissonForOrder([
            { item_name: 'Tacos M', quantity: 5, composition_snapshot: { ...snap(['Viande Hachée']), addons: [menu] } },
            item('Big Burger', [], 1),
            item('Grande Frite', [], 1),
        ]);
        expect(o.texte).toBe('13K 7F');
        expect(o.inconnus).toBe(0);
    });
});
