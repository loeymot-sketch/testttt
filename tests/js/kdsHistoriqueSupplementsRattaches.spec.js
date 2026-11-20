import { describe, expect, it } from 'vitest';

import { mount } from '@vue/test-utils';

import { renderItem } from '../../resources/js/helpers/kdsCustomization.js';
import { collapseBundledAddonItems } from '../../resources/js/helpers/kdsBundledAddons';
import KdsOrderLine from '../../resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue';

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

    it('[revue] l\'en-tête du tiroir porte le « # » quand le produit a un supplément payé ou offert', () => {
        const avec = renderItem({ item_name: 'Cayenne', quantity: 1, item_extras: [{ name: 'Cheddar', unit_price: 0.9, quantity: 1 }], instruction: '' });
        const sans = renderItem({ item_name: 'Cayenne', quantity: 1, item_extras: [{ name: 'Salade', unit_price: 0, quantity: 1 }], instruction: '' });
        expect(avec.lines.find((l) => l.type === 'header').hasSupplement).toBe(true);
        expect(sans.lines.find((l) => l.type === 'header').hasSupplement).toBeFalsy();
    });

    it('[revue] le composant affiche « # » sur une ligne d\'en-tête marquée', () => {
        const w = mount(KdsOrderLine, { props: { line: { type: 'header', qty: 1, label: 'Cayenne', hasSupplement: true } }, global: { mocks: { $t: (k) => k } } });
        expect(w.find('.kds-line__hash').exists()).toBe(true);
    });

    it('[revue 2 · P3-1] option de formule repliée : « Frites : Cheddar Fondu » une seule fois, distincte du Cheddar', () => {
        const parent = {
            id: 1, item_name: 'Cayenne', quantity: 1,
            item_extras: [{ name: 'Cheddar', unit_price: 0.9, quantity: 1 }],
            instruction: 'CAYENNE\nSauce : Algérienne\n+ Menu (Frites + Boisson) (+2,50 €)\n↳ Sauce frites: Mayonnaise\n↳ Cheddar Fondu (+1.00€)',
        };
        const formule = {
            id: 2, item_name: 'Menu (Frites + Boisson)', quantity: 1,
            item_extras: [{ name: 'Cheddar Fondu', unit_price: 1, quantity: 1 }],
            instruction: 'Sauce frites: Mayonnaise\n↳ Cheddar Fondu (+1.00€)',
        };
        const [seul] = collapseBundledAddonItems([parent, formule]);
        const out = renderItem(seul);
        const sup = out.lines.filter((l) => l.type === 'supplement').map((l) => l.label);
        expect(sup).toContain('+ Cheddar');
        expect(sup).toContain('+ Frites : Cheddar Fondu');
        expect(out.lines.map((l) => l.label).join('\n').match(/Cheddar Fondu/g) || []).toHaveLength(1);
    });

    it('contre-épreuve : la résolution produit existante est conservée', () => {
        expect(supps({ item_name: 'Cayenne', quantity: 1, item_extras: generique(1), instruction: 'Sauces en plus : Andalouse' }))
            .toEqual(['+ Sauce supplémentaire : Andalouse']);
    });
});
