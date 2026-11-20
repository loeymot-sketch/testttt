import { describe, expect, it } from 'vitest';
import { renderItemSymbolic, drinkAddonLabels } from '../../resources/js/helpers/kdsSymbolic.js';

/**
 * [SAUCES FRITES UBER 2026-11-20 · relecture adverse P0] Un ticket Uber photographié range un dessert
 * pris avec le produit en addon `role: 'dessert'` (il sort en bas du papier). L'écran cuisine ne
 * montrait que les addons boisson/menu : le Tiramisu DISPARAISSAIT de l'écran. Jumeau PHP :
 * KitchenTicketSymbolicFormatter::drinkLines().
 */
describe('KDS — dessert d’une commande Uber photographiée', () => {
    const orderItem = {
        item_name: 'Cayenne',
        quantity: 1,
        instruction: '',
        composition_snapshot: {
            source: 'uber_eats_photo',
            lines: [{ attribute_name: 'Sauce 1', variation_name: 'Algérienne' }],
            addons: [
                { role: 'drink', quantity: 1, addon_name: 'Coca-Cola 33cl' },
                { role: 'dessert', quantity: 2, addon_name: 'Tiramisu' },
            ],
        },
    };

    it('le dessert est listé avec sa quantité', () => {
        expect(drinkAddonLabels(orderItem)).toEqual(['1× Coca-Cola 33cl', '2× Tiramisu']);
    });

    it('le dessert apparaît sur la carte de l’écran cuisine', () => {
        const text = renderItemSymbolic(orderItem).lines.map((l) => String(l.label ?? '')).join('\n');
        expect(text, `Rendu obtenu :\n${text}`).toContain('Tiramisu');
    });
});
