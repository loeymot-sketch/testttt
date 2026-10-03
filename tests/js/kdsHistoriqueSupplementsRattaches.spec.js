import { describe, expect, it } from 'vitest';

import { renderItem } from '../../resources/js/helpers/kdsCustomization.js';

// [GOAL REMARQUES 2026-10-03 · T-1.8 R-069] Propriétaire, 27/09 : « chaque sauce si c'est pour le sandwich
// […] pour la ligne de sandwich. Si c'est pour les frites on doit s'afficher ça devant les frites ou bien
// menu ». Le plateau KDS le fait ; le tiroir HISTORIQUE (kdsCustomization.renderItem) ne résolvait que les
// sauces du PRODUIT : une 2ᵉ sauce FRITES payée restait « + Sauce supplémentaire », anonyme et sans
// destination. Chaque destination est désormais nommée ; seul l'inexpliqué garde le libellé générique.

const supps = (item) => renderItem(item).lines.filter((l) => l.type === 'supplement').map((l) => l.label);
const generique = (qty) => [{ name: 'Sauce supplémentaire', quantity: qty }];

describe('tiroir Historique : chaque sauce en plus rattachée à sa destination (R-069)', () => {
    it('une sauce produit et une sauce frites payées → deux lignes nommées, chacune à sa place', () => {
        const out = supps({
            item_name: 'Cayenne',
            quantity: 1,
            item_extras: generique(2),
            instruction: 'CAYENNE\nPain Sauce : Algérienne, Samouraï\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise, Ketchup',
        });
        expect(out).toContain('+ Sauce supplémentaire : Samouraï');
        expect(out).toContain('+ Sauce frites en plus : Ketchup');
        expect(out).not.toContain('+ Sauce supplémentaire');
        expect(out).not.toContain('+ Sauce supplémentaire ×2');
    });

    it('seulement une 2ᵉ sauce frites payée → nommée côté frites, jamais anonyme', () => {
        const out = supps({
            item_name: 'Cayenne',
            quantity: 1,
            item_extras: generique(1),
            instruction: 'CAYENNE\nPain Sauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise, Ketchup',
        });
        expect(out).toEqual(['+ Sauce frites en plus : Ketchup']);
    });

    it('rien d\'explicable dans l\'instruction → le générique reste visible (une sauce payée ne disparaît jamais)', () => {
        expect(supps({ item_name: 'Cayenne', quantity: 1, item_extras: generique(1), instruction: '' })).toEqual(['+ Sauce supplémentaire']);
    });

    it('contre-épreuve : la résolution produit existante est conservée', () => {
        expect(supps({ item_name: 'Cayenne', quantity: 1, item_extras: generique(1), instruction: 'Sauces en plus : Andalouse' }))
            .toEqual(['+ Sauce supplémentaire : Andalouse']);
    });
});
