import { describe, expect, it } from 'vitest';
import {
    calculateDeliveryChargeFromDistance,
    quoteDelivery,
    isHomeCity,
} from '../../resources/js/helpers/deliveryCharge.js';

// [LIVRAISON 2026-10-20] Barème propriétaire : Hénin-Beaumont 3,90 · ≤5 km 4,90 · ≤8 km 5,90 ·
// ≤10 km 6,90 · au-delà refusé (Uber Eats). Miroir d'affichage de DeliveryTariff (serveur = autorité).
describe('delivery charge preview helper', () => {
    it.each([
        [0.1, 4.9], [3, 4.9], [5, 4.9],
        [5.01, 5.9], [8, 5.9],
        [8.01, 6.9], [10, 6.9],
    ])('autre commune à %s km = %s EUR', (distance, expected) => {
        expect(calculateDeliveryChargeFromDistance(distance, 'Oignies', '62590')).toBe(expected);
    });

    it('Hénin-Beaumont est un forfait unique, quelle que soit la distance', () => {
        for (const km of [0, 2, 7, 11]) {
            expect(quoteDelivery(km, 'Hénin-Beaumont', '62110')).toMatchObject({ allowed: true, fee: 3.9, tier: 'home' });
        }
    });

    it('reconnaît la commune par code postal ou par toute orthographe', () => {
        expect(isHomeCity(null, '62110')).toBe(true);
        expect(isHomeCity('HENIN BEAUMONT')).toBe(true);
        expect(isHomeCity('henin-beaumont')).toBe(true);
        expect(isHomeCity('Hénin–Beaumont')).toBe(true);
        expect(isHomeCity('Oignies', '62590')).toBe(false);
        expect(isHomeCity(null, null)).toBe(false);
    });

    it('refuse au-delà de 10 km et propose Uber Eats', () => {
        expect(quoteDelivery(10.01, 'Lens', '62300')).toMatchObject({ allowed: false, fee: 0, suggestUberEats: true });
    });

    it.each([[-1], ['x'], [null], [Number.NaN], [Number.POSITIVE_INFINITY]])('distance illisible %s : refusée, jamais facturée', (d) => {
        expect(quoteDelivery(d, 'Oignies', '62590')).toMatchObject({ allowed: false, fee: 0, tier: 'invalid' });
    });
});
