import { describe, expect, it } from 'vitest';
import { calculateDeliveryChargeFromDistance } from '../../resources/js/helpers/deliveryCharge.js';

// [DEL-FEE whole-km — owner rule, base lowered 5€→4€ on 2026-06-27] Le Cayenne:
// 4€ covers the first 5km, then +1€ per STARTED km beyond (rounded up). Mirrors
// backend DeliveryFeeService (base=4/per_km=1/min=4/free_km=5).
describe('delivery charge preview helper', () => {
    it.each([
        [0, 4],
        [3, 4],
        [5, 4],
        [5.01, 5],   // 4 + ceil(0.01)=1
        [6, 5],      // 4 + ceil(1)
        [8, 7],      // 4 + ceil(3)
        [8.3, 8],    // 4 + ceil(3.3)=4
        [10, 9],     // 4 + ceil(5)
        [12.5, 12],  // 4 + ceil(7.5)=8
        [-1, 0],
        ['x', 0],
        [null, 0],
        [Number.NaN, 0],
        [Number.POSITIVE_INFINITY, 0],
    ])('calculates %s km as %s EUR', (distance, expected) => {
        expect(calculateDeliveryChargeFromDistance(distance)).toBe(expected);
    });
});
