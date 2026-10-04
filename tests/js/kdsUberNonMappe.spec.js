import { describe, expect, it } from 'vitest';

import { renderItemSymbolic } from '../../resources/js/helpers/kdsSymbolic.js';
import { renderItem } from '../../resources/js/helpers/kdsCustomization.js';

// [GOAL REMARQUES 2026-10-03 · T-1.7 R-075] Jumeau écran de
// tests/Unit/Hardware/KitchenTicketUberNonMappeTitreTest.php. Une ligne Uber non reconnue affichait
// « ART » (article technique « Article Uber (non mappé) ») ; le vrai titre n'était que dans la note
// « [UBER NON MAPPÉ: …] ». Le titre devient la ligne produit, en entier ; la note client reste.

const item = (name, instruction) => ({
    item_name: name,
    quantity: 1,
    instruction,
    composition_snapshot: { lines: [{ attribute_name: 'Sauce', variation_name: 'Algérienne' }], extras: [], addons: [] },
});

describe('Uber non reconnu : le titre complet, jamais « ART » (R-075)', () => {
    const res = renderItemSymbolic(item('Article Uber (non mappé)', '[UBER NON MAPPÉ: Wrap Poulet Spicy] NO ONIONS'));
    const texte = res.lines.map((l) => l.label).join('\n');

    it('la ligne produit est le titre Uber en entier, options en symboles', () => {
        expect(res.lines.find((l) => l.type === 'symbolic-main').label).toBe('WRAP POULET SPICY | ALG');
    });

    it('la note du client reste, le marqueur technique disparaît', () => {
        expect(texte).toContain('NO ONIONS');
        expect(texte).not.toContain('UBER NON MAPP');
        expect(texte).not.toMatch(/\bART\b/);
    });

    it('[revue F4] un titre sans lettre latine n\'est jamais vidé : le titre reste lisible', () => {
        const r = renderItemSymbolic(item('Article Uber (non mappé)', '[UBER NON MAPPÉ: شاورما دجاج] [sans oignons]'));
        // Sans repli, la normalisation ASCII vidait le titre : la ligne ne gardait que « ALG ».
        expect(r.lines.find((l) => l.type === 'symbolic-main').label).toContain('شاورما دجاج');
    });

    it('[tiroir Historique] l\'en-tête montre le titre Uber, pas « Article Uber (non mappé) »', () => {
        const out = renderItem(item('Article Uber (non mappé)', '[UBER NON MAPPÉ: Wrap Poulet Spicy] NO ONIONS'));
        const header = out.lines.find((l) => l.type === 'header');
        expect(header.label).toBe('Wrap Poulet Spicy');
        expect(out.lines.map((l) => l.label).join('\n')).not.toContain('UBER NON MAPP');
    });

    it('contre-épreuve : un article reconnu garde son code', () => {
        expect(renderItemSymbolic(item('Cayenne', 'NO ONIONS')).lines.find((l) => l.type === 'symbolic-main').label).toBe('CAY | ALG');
    });
});
