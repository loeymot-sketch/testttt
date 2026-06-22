import { describe, expect, it } from 'vitest';
import { calculateDeliveryChargeFromDistance } from '../../resources/js/helpers/deliveryCharge.js';

// [DEL-FEE whole-km 2026-06-01, owner decision] Le Cayenne rule: 5€ covers the
// first 5km, then +1€ per STARTED km beyond (rounded up). Mirrors backend
// DeliveryFeeService (base=5/per_km=1/min=5/free_km=5). Was: 5€ per 5km tranche.
describe('delivery charge preview helper', () => {
    it.each([
        [0, 5],
        [3, 5],
        [5, 5],
        [5.01, 6],   // 5 + ceil(0.01)=1
        [6, 6],      // 5 + ceil(1)
        [8, 8],      // 5 + ceil(3)
        [8.3, 9],    // 5 + ceil(3.3)=4
        [10, 10],    // 5 + ceil(5)
        [12.5, 13],  // 5 + ceil(7.5)=8
        [-1, 0],
        ['x', 0],
        [null, 0],
        [Number.NaN, 0],
        [Number.POSITIVE_INFINITY, 0],
    ])('calculates %s km as %s EUR', (distance, expected) => {
        expect(calculateDeliveryChargeFromDistance(distance)).toBe(expected);
    });
});
