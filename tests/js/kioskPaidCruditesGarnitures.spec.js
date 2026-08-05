import { describe, expect, it } from 'vitest';

import { partitionKioskExtras } from '../../resources/js/helpers/kioskExtrasPartition';
import { calculateKioskRunningTotal } from '../../resources/js/helpers/kioskPricing';

/**
 * [GOAL-8AXES V3 2026-08-05] Crudités PAYANTES borne (Poivrons cuits / Maïs /
 * Olives, 0,90 €, group_label='crudite') :
 *  1. partition → étape GARNITURES (« à côté des crudités », demande owner),
 *     pas Gourmands ;
 *  2. total local : une crudité payante cochée dans selections.garnitures
 *     augmente le running total d'exactement son prix — parité avec le scellé
 *     backend (NewSupplementsBilledTest). Précédent : sauce frites affichée
 *     non facturée (2026-07-29).
 */
const item = {
    convert_price: 6.5,
    extras: [
        { id: 1, name: 'Salade', price: 0, group_label: 'crudite' },
        { id: 2, name: 'Tomate', price: 0, group_label: 'crudite' },
        { id: 3, name: 'Poivrons cuits', price: 0.9, group_label: 'crudite' },
        { id: 4, name: 'Maïs', price: 0.9, group_label: 'crudite' },
        { id: 5, name: 'Cheddar', price: 1.0, group_label: 'supplement' },
    ],
};

describe('Crudités payantes borne — partition + total', () => {
    it('les crudités payantes partent dans GARNITURES, pas dans Gourmands', () => {
        const p = partitionKioskExtras(item);
        expect(p.garnitures.map((g) => g.name)).toEqual(['Salade', 'Tomate', 'Poivrons cuits', 'Maïs']);
        expect(p.supplements.map((s) => s.name)).toEqual(['Cheddar']);
        // Le prix reste porté pour le badge « +0,90 € ».
        expect(p.garnitures.find((g) => g.name === 'Poivrons cuits').price).toBeCloseTo(0.9);
    });

    it('une crudité payante cochée augmente le total local de son prix exact', () => {
        const base = calculateKioskRunningTotal(item, { garnitures: { 1: true, 2: true } });
        const withPoivrons = calculateKioskRunningTotal(item, { garnitures: { 1: true, 2: true, 3: true } });
        expect(base).toBeCloseTo(6.5, 5);
        expect(withPoivrons - base).toBeCloseTo(0.9, 5);
    });

    it('les crudités GRATUITES restent neutres sur le total', () => {
        const none = calculateKioskRunningTotal(item, { garnitures: {} });
        const all = calculateKioskRunningTotal(item, { garnitures: { 1: true, 2: true } });
        expect(all - none).toBeCloseTo(0, 5);
    });

    it('deux crudités payantes = 2 × 0,90 €', () => {
        const both = calculateKioskRunningTotal(item, { garnitures: { 3: true, 4: true } });
        expect(both).toBeCloseTo(6.5 + 1.8, 5);
    });
});
