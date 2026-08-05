import { describe, expect, it } from 'vitest';

import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';
import { sanitizeKdsInstruction } from '../../resources/js/helpers/kdsCustomization.js';

/**
 * [GOAL-8AXES V4 2026-08-05] D-1 côté ÉCRAN — jumeau STRICT de
 * tests/Feature/Hardware/KitchenTicketNoDuplicateLabelTest.php.
 *
 * La carte KDS émettait la boisson de formule DEUX FOIS quand l'addon
 * menu_boisson porte le VRAI nom (« Coca 33cl ») ET que l'instruction contient
 * la ligne « Formule : … (Coca 33cl) » : une ligne type:'menu_child' (canal
 * addon, drinkAddonLabels) + une ligne type:'instruction' « BOISSON: Coca 33cl »
 * (canal extractFormuleDrinkLines). Écran et papier faux de la même manière.
 */
describe('D-1 KDS — boisson de formule non dupliquée entre addon et instruction', () => {
    const orderItem = {
        item_name: 'Tacos M',
        quantity: 1,
        instruction: 'Formule : Menu (Frites + Boisson) (Coca 33cl)\nSans oignons',
        composition_snapshot: {
            addons: [
                { role: 'menu_frites', quantity: 1, addon_name: 'Frites' },
                { role: 'menu_boisson', quantity: 1, addon_name: 'Coca 33cl' },
            ],
        },
    };

    it('émet la boisson UNE seule fois sur la carte', () => {
        const { lines } = renderItemSymbolic(orderItem);
        const text = lines.map((l) => String(l.label ?? '')).join('\n');
        const occurrences = (text.toLowerCase().match(/coca/g) || []).length;
        expect(occurrences, `Rendu obtenu :\n${text}`).toBe(1);
        // La note libre survit.
        expect(text).toContain('Sans oignons');
    });

    it('sanitizeKdsInstruction saute les boissons déjà connues (canal addon)', () => {
        const cleaned = sanitizeKdsInstruction(
            orderItem.instruction,
            orderItem.item_name,
            ['1× Coca 33cl'],
        );
        expect(cleaned.toLowerCase()).not.toContain('coca');
        expect(cleaned).toContain('Sans oignons');
    });

    it('garde inverse — addon conteneur : le canal instruction reste le seul porteur', () => {
        const cleaned = sanitizeKdsInstruction(
            'Formule : Menu (Frites + Boisson) (Oasis Tropical)',
            'Galette Cayenne',
            [],
        );
        const occurrences = (cleaned.toLowerCase().match(/oasis/g) || []).length;
        expect(occurrences, `Notes :\n${cleaned}`).toBe(1);
    });

    it('la ligne source « Formule : … » ne survit pas en note', () => {
        const cleaned = sanitizeKdsInstruction(
            'Formule : Menu XL (Fanta 33cl)',
            'Big Tacos',
            ['2× Fanta 33cl'],
        );
        expect(cleaned.toLowerCase()).not.toContain('fanta');
        expect(cleaned.toLowerCase()).not.toContain('formule');
    });
});
