import { describe, expect, it } from 'vitest';
import {
    rowUnitBundled,
    rowUnitMain,
    computePosCartLineDisplayTotal,
    bundledOrderQuantityAndTotal,
} from '../../resources/js/helpers/posCartLineMath.js';

/**
 * [FIX 2026-07-04 owner] Régression du bug « le menu s'affiche à 2,50 dans le wizard mais
 * le panier calcule 3,00 » alors que la base de données + le ticket = 2,50 (le RÉEL).
 *
 * Reproduit exactement la sérialisation du wizard (pos-wizard.js addonToPayload) : le menu
 * porte `total_price` = total_convert_price (AUTORITATIF, = backend + ticket) MAIS une
 * `item_variation_total` (ex. taille de frites) NON facturée. rowUnitMain sommait les
 * composants (2,50 + 0,50 = 3,00) ; rowUnitBundled doit désormais rendre le prix RÉEL 2,50.
 */
describe('POS cart menu display = prix réel (total_price), pas la somme des composants', () => {
    // Exactement la forme produite par le wizard pour un menu à 2,50 € avec variation frites 0,50 €.
    const menuAddon = {
        name: 'Menu (Frites + Boisson)',
        quantity: 1,
        convert_price: 2.5,
        item_variation_total: 0.5, // variation menu (taille frites) — NON facturée par le backend
        item_extra_total: 0,
        total_price: 2.5, // AUTORITATIF : ce qui est soumis, facturé, imprimé (= 2,50 €)
    };

    it('rowUnitMain (ancien calcul) donnait bien 3,00 — la source du bug', () => {
        expect(rowUnitMain(menuAddon)).toBeCloseTo(3.0, 2);
    });

    it('rowUnitBundled rend le prix RÉEL 2,50 (total_price), pas 3,00', () => {
        expect(rowUnitBundled(menuAddon)).toBeCloseTo(2.5, 2);
    });

    it('le total de ligne panier affiché reste cohérent (principal + menu réel)', () => {
        const line = {
            quantity: 1,
            convert_price: 7.4, // sandwich Cayenne
            item_variation_total: 0,
            item_extra_total: 0,
            pos_line_addons: [menuAddon],
        };
        // 7,40 (principal) + 2,50 (menu réel) = 9,90 — PAS 10,40.
        expect(computePosCartLineDisplayTotal(line)).toBeCloseTo(9.9, 2);
    });

    it('le total commande de l addon suit aussi le prix réel', () => {
        const { lineTotal } = bundledOrderQuantityAndTotal(menuAddon, 1);
        expect(lineTotal).toBeCloseTo(2.5, 2);
    });

    it('fallback : un addon sans total_price retombe sur la somme des composants', () => {
        const legacy = { convert_price: 1.5, item_variation_total: 0, item_extra_total: 0 };
        expect(rowUnitBundled(legacy)).toBeCloseTo(1.5, 2);
    });
});
