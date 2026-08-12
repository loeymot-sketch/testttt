import { describe, it, expect } from 'vitest';
import { renderItemSymbolic, cuissonForOrder, estProduitFrites } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [FRITES-MENU / FRITES-SAUCE 2026-08-10 · owner] Ce que l'ÉCRAN DE CUISINE montre pour les
 * frites — le jumeau des assertions faites sur le ticket imprimé
 * (tests/Feature/Hardware/KitchenTicketUberEtFritesTest.php).
 *
 * Les deux surfaces doivent dire la même chose au même cuisinier : si l'une des deux dérive,
 * l'un des deux fichiers rougit.
 */
describe('frites — écran de cuisine', () => {
    it('les frites vendues comme PRODUIT affichent leur sauce (elle disparaissait)', () => {
        const { lines } = renderItemSymbolic({
            item_name: 'Grande Frites',
            quantity: 1,
            instruction: 'Sauce frites : Mayonnaise, Ketchup, Samouraï',
            composition_snapshot: {},
        });

        const badge = lines.find((l) => l.type === 'symbolic-menu');
        expect(badge, "aucun badge : les sauces des frites sont invisibles en cuisine").toBeTruthy();
        expect(badge.label).toBe('FRITES : MAY KTP SAM');
    });

    /**
     * [2ᵉ passe owner 2026-08-10] Ce test exigeait AUCUN badge sur un produit ordinaire portant
     * « Sauce frites : … » — c'est-à-dire qu'il scellait la PERTE de cette sauce. Vérifié en base :
     * la caisse écrit bel et bien « ↳ Sauce frites: Harissa » sur le PRODUIT (#4926, Tacos M, sans
     * aucun addon), et l'ancienne règle la faisait disparaître. La règle est désormais :
     * une sauce CHOISIE ne disparaît jamais.
     */
    it('un produit portant une sauce frites la MONTRE (elle était perdue)', () => {
        const { lines } = renderItemSymbolic({
            item_name: 'Cayenne',
            quantity: 1,
            instruction: 'Sauce frites : Andalouse',
            composition_snapshot: { lines: [{ attribute_name: 'Viande 1', variation_name: 'Poulet mariné' }] },
        });

        expect(lines.find((l) => l.type === 'symbolic-menu')?.label).toBe('FRITES : AND');
    });

    it("un produit SANS sauce frites ne reçoit aucun badge", () => {
        const { lines } = renderItemSymbolic({
            item_name: 'Cayenne',
            quantity: 1,
            instruction: '[bien cuit svp]',
            composition_snapshot: { lines: [{ attribute_name: 'Viande 1', variation_name: 'Poulet mariné' }] },
        });

        expect(lines.find((l) => l.type === 'symbolic-menu')).toBeUndefined();
    });

    it('un menu garde son badge annoté de la sauce des frites', () => {
        const { lines } = renderItemSymbolic({
            item_name: 'Cayenne',
            quantity: 1,
            instruction: 'Sauce frites : Andalouse',
            composition_snapshot: {
                lines: [{ attribute_name: 'Viande 1', variation_name: 'Poulet mariné' }],
                addons: [{ role: 'menu_full', addon_name: 'Menu (Frites + Boisson)', quantity: 1 }],
            },
        });

        expect(lines.find((l) => l.type === 'symbolic-menu').label).toBe('MENU : AND');
    });

    it('estProduitFrites distingue une portion de frites d’un conteneur de menu', () => {
        expect(estProduitFrites('Grande Frites')).toBe(true);
        expect(estProduitFrites('Frites Seules')).toBe(true);
        expect(estProduitFrites('Menu (Frites + Boisson)')).toBe(false);
        expect(estProduitFrites('Cayenne')).toBe(false);
    });
});

describe('bandeau de cuisson — le menu de la CAISSE compte sa frite', () => {
    it('la ligne dédiée « Menu (Frites + Boisson) » apporte la frite, et le parent ne la double pas', () => {
        // Forme RÉELLE d'une commande de caisse, relevée en base : le produit garde un écho
        // « + Menu (…) » dans son texte libre, et le menu est une LIGNE DE COMMANDE à part.
        const { texte } = cuissonForOrder([
            {
                item_name: 'Cayenne',
                quantity: 1,
                instruction: 'CAYENNE\n+ Menu (Frites + Boisson) (+2,50 €)',
                composition_snapshot: { lines: [{ attribute_name: 'Viande 1', variation_name: 'Viande Hachée' }] },
            },
            { item_name: 'Menu (Frites + Boisson)', quantity: 1, instruction: 'MENU', composition_snapshot: {} },
        ]);

        expect(texte).toBe('2K 1F');
    });

    it('le menu de la BORNE (canal addon) donne le même résultat', () => {
        const { texte } = cuissonForOrder([{
            item_name: 'Cayenne',
            quantity: 1,
            instruction: '',
            composition_snapshot: {
                lines: [{ attribute_name: 'Viande 1', variation_name: 'Viande Hachée' }],
                addons: [{ role: 'menu_full', addon_name: 'Menu (Frites + Boisson)', quantity: 1 }],
            },
        }]);

        expect(texte).toBe('2K 1F');
    });

    it('le tableau de bord « articles » du KDS n’expose pas le snapshot : les addons y vivent dans item_addons', () => {
        // Sans le repli sur `item_addons`, cette charge utile rendait TOUJOURS zéro frite.
        const { texte } = cuissonForOrder([{
            item_name: 'Cayenne',
            quantity: 1,
            instruction: '',
            item_variations: [{ attribute_name: 'Viande 1', variation_name: 'Viande Hachée' }],
            item_addons: [{ role: 'menu_full', addon_name: 'Menu (Frites + Boisson)', quantity: 1 }],
        }]);

        expect(texte).toBe('2K 1F');
    });
});
