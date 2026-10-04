import { describe, expect, it } from 'vitest';

import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';
import { collapseBundledAddonItems } from '../../resources/js/helpers/kdsBundledAddons';

// [GOAL REMARQUES 2026-10-03 · T-1.2 R-072] Propriétaire, 02/10 puis 03/10 : « mettre un dièse si il y a
// un produit avec des supplément encore, ils savent bien que ce produit là il y a des supplément ».
// Le « # » existait depuis le 02/10 mais n'était posé QUE si une ligne « supplément » s'affichait.
// Or une sauce EN PLUS (payée 0,50 €) est volontairement repliée dans la ligne produit (« … | ALG SAM »)
// ou sur le badge frites (« MENU : MAY KTP ») — aucune ligne supplément, donc AUCUN « # », alors que
// le produit porte bien un supplément payé. Même trou pour un extra OFFERT nommé comme une crudité
// (« Oignons frits » offert = 0 €) : l'écran le rangeait parmi les crudités gratuites, contrairement
// au ticket (jumeau PHP, garde `offered`). Jumeau : tests/Unit/Hardware/KitchenTicketDieseTousSupplementsTest.php.

const sandwich = (extras, instruction = 'CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne') => ({
    item_name: 'Cayenne',
    quantity: 1,
    instruction,
    composition_snapshot: {
        lines: [
            { attribute_name: 'Viande 1', variation_name: 'Poulet mariné' },
            { attribute_name: 'Sauce (1ère Gratuite)', variation_name: 'Algérienne' },
        ],
        extras,
        addons: [],
    },
});

const main = (item) => renderItemSymbolic(item).lines.find((l) => l.type === 'symbolic-main');
const supplements = (item) => renderItemSymbolic(item).lines.filter((l) => l.type === 'supplement').map((l) => l.label);

describe('« # » sur tout produit qui porte un supplément (R-072)', () => {
    it('extra payant classique (Cheddar)', () => {
        expect(main(sandwich([{ extra_name: 'Cheddar', unit_price: 0.9, line_total: 0.9, quantity: 1 }])).hasSupplement).toBe(true);
    });

    it('viande supplémentaire', () => {
        expect(main(sandwich([{ extra_name: 'Viande supplémentaire', unit_price: 2.5, line_total: 2.5, quantity: 1 }])).hasSupplement).toBe(true);
    });

    it('sauce EN PLUS repliée dans la ligne produit — aucune ligne supplément mais bien un « # »', () => {
        const item = sandwich(
            [{ extra_name: 'Sauce supplémentaire', unit_price: 0.5, line_total: 0.5, quantity: 1 }],
            'CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne, Samouraï',
        );
        expect(supplements(item)).toEqual([]);
        expect(main(item).label).toContain('SAM');
        expect(main(item).hasSupplement).toBe(true);
    });

    it('2ᵉ sauce frites payée, affichée sur le badge — « # » quand même', () => {
        const item = sandwich(
            [{ extra_name: 'Sauce supplémentaire', unit_price: 0.5, line_total: 0.5, quantity: 1 }],
            'CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise, Ketchup',
        );
        expect(main(item).hasSupplement).toBe(true);
    });

    it('extra OFFERT nommé comme une crudité : reste un supplément à préparer, avec son « # »', () => {
        const item = sandwich([{ extra_name: 'Oignons frits', unit_price: 0, line_total: 0, quantity: 1, offered: true, catalog_unit_price: 0.9 }]);
        expect(supplements(item).join(' ')).toContain('Oignons frits');
        expect(main(item).hasSupplement).toBe(true);
    });

    it('option de formule (Grande Portion) héritée de la ligne formule repliée sous le sandwich', () => {
        const parent = { id: 1, ...sandwich([], 'CAYENNE\nPain Viandes : Poulet mariné - Salade, Tomate Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise') };
        const formule = {
            id: 2,
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Sauce frites: Mayonnaise',
            composition_snapshot: { lines: [], extras: [{ extra_name: 'Grande Portion', unit_price: 1, line_total: 1, quantity: 1 }], addons: [] },
        };
        const affichees = collapseBundledAddonItems([parent, formule]);
        expect(affichees).toHaveLength(1);
        expect(main(affichees[0]).hasSupplement).toBe(true);
    });

    it('option de formule, instruction RÉELLE de la caisse : « Grande Portion » écrite UNE fois', () => {
        // Le wizard écrit « ↳ Grande Portion (+1.00€) » et, depuis le 02/10, l'option est AUSSI un extra
        // facturé : l'écran affichait le cadre noir PUIS la note « ↳ Grande Portion » (R-049, doublon).
        const parent = { id: 1, ...sandwich([], 'CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise\n↳ Grande Portion (+1.00€)') };
        const formule = {
            id: 2,
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Sauce frites: Mayonnaise\n↳ Grande Portion (+1.00€)',
            composition_snapshot: { lines: [], extras: [{ extra_name: 'Grande Portion', unit_price: 1, line_total: 1, quantity: 1 }], addons: [] },
        };
        const [seul] = collapseBundledAddonItems([parent, formule]);
        const texte = renderItemSymbolic(seul).lines.map((l) => l.label).join('\n');
        expect(texte.match(/Grande Portion/g) || []).toHaveLength(1);
        expect(main(seul).hasSupplement).toBe(true);
    });

    it('[revue F2] l\'option de formule dit qu\'elle va sur les FRITES — jamais confondue avec le cheddar du sandwich', () => {
        const parent = { id: 1, ...sandwich([{ extra_name: 'Cheddar', unit_price: 0.9, line_total: 0.9, quantity: 1 }], 'CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise\n↳ Cheddar Fondu (+1.00€)') };
        const formule = {
            id: 2,
            item_name: 'Menu (Frites + Boisson)',
            quantity: 1,
            instruction: 'Sauce frites: Mayonnaise\n↳ Cheddar Fondu (+1.00€)',
            composition_snapshot: { lines: [], extras: [{ extra_name: 'Cheddar Fondu', unit_price: 1, line_total: 1, quantity: 1 }], addons: [] },
        };
        const [seul] = collapseBundledAddonItems([parent, formule]);
        const libelles = supplements(seul);
        expect(libelles).toContain('⭐ Cheddar');
        expect(libelles).toContain('⭐ Frites : Cheddar Fondu');
        const texte = renderItemSymbolic(seul).lines.map((l) => l.label).join('\n');
        expect(texte.match(/Cheddar Fondu/g) || []).toHaveLength(1);
    });

    it('une note « ↳ » qui n\'est PAS un supplément affiché reste visible', () => {
        const item = sandwich([{ extra_name: 'Cheddar', unit_price: 0.9, line_total: 0.9, quantity: 1 }], 'CAYENNE\nSauce : Algérienne\n↳ Bien cuit');
        expect(renderItemSymbolic(item).lines.map((l) => l.label).join('\n')).toContain('Bien cuit');
    });

    it('contre-épreuve : garnitures gratuites seules → pas de « # »', () => {
        expect(main(sandwich([{ extra_name: 'Salade', unit_price: 0, line_total: 0, quantity: 1 }])).hasSupplement).toBeUndefined();
    });

    it('contre-épreuve : produit sans aucun extra → pas de « # »', () => {
        expect(main(sandwich([])).hasSupplement).toBeUndefined();
    });
});
