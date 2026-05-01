import { describe, expect, it } from 'vitest';
import { calculateDeliveryChargeFromDistance } from '../../resources/js/helpers/deliveryCharge.js';

describe('delivery charge preview helper', () => {
    it.each([
        [0, 5],
        [5, 5],
        [5.01, 10],
        [10, 10],
        [10.01, 15],
        [-1, 0],
        ['x', 0],
        [null, 0],
        [Number.NaN, 0],
        [Number.POSITIVE_INFINITY, 0],
    ])('calculates %s km as %s EUR', (distance, expected) => {
        expect(calculateDeliveryChargeFromDistance(distance)).toBe(expected);
    });
});
