import { describe, expect, it } from 'vitest';

import {
    orderHasAllergens,
    sortedAllergens,
    normalizeAllergensForHash,
} from '../../resources/js/helpers/kdsAllergens';

/**
 * Lot 2.I — KDS allergens helpers (G-4 badge gate + G-5 split-grouping parity).
 *
 * Mirrors the backend `KitchenDisplaySystemOrderService::normalizeAllergensForHash`
 * normalization rules so frontend assumptions stay consistent with server-side
 * line splitting.
 */
describe('orderHasAllergens', () => {
    it('returns false for null / undefined / non-object input', () => {
        expect(orderHasAllergens(null)).toBe(false);
        expect(orderHasAllergens(undefined)).toBe(false);
        expect(orderHasAllergens({})).toBe(false);
    });

    it('returns false when no item carries a non-empty allergens_snapshot', () => {
        expect(orderHasAllergens({ orderItems: [{ allergens_snapshot: [] }] })).toBe(false);
        expect(orderHasAllergens({ orderItems: [{ allergens_snapshot: null }] })).toBe(false);
        expect(orderHasAllergens({ orderItems: [{}] })).toBe(false);
    });

    it('returns true when at least one item carries a populated allergens_snapshot', () => {
        const order = {
            orderItems: [
                { allergens_snapshot: [] },
                { allergens_snapshot: ['arachides'] },
                { allergens_snapshot: [] },
            ],
        };
        expect(orderHasAllergens(order)).toBe(true);
    });

    it('accepts both orderItems and snake_case order_items shapes', () => {
        const snake = { order_items: [{ allergens_snapshot: ['gluten'] }] };
        expect(orderHasAllergens(snake)).toBe(true);
    });

    it('is defensive against non-array allergens_snapshot (legacy JSON object shape)', () => {
        const legacy = { orderItems: [{ allergens_snapshot: { foo: 'bar' } }] };
        expect(orderHasAllergens(legacy)).toBe(false);
    });

    it('order_items snake_case: mix of empty and populated snapshots returns true (audit T-04)', () => {
        const o = {
            order_items: [
                { allergens_snapshot: [] },
                { allergens_snapshot: ['gluten'] },
            ],
        };
        expect(orderHasAllergens(o)).toBe(true);
    });
});

describe('sortedAllergens', () => {
    it('returns [] for non-array input', () => {
        expect(sortedAllergens(null)).toEqual([]);
        expect(sortedAllergens(undefined)).toEqual([]);
        expect(sortedAllergens('peanuts')).toEqual([]);
    });

    it('strips empty / null entries and dedupes', () => {
        expect(sortedAllergens(['gluten', '', null, 'gluten', 'arachides'])).toEqual(['arachides', 'gluten']);
    });

    it('produces alphabetical, deterministic order for any input ordering', () => {
        expect(sortedAllergens(['gluten', 'arachides'])).toEqual(['arachides', 'gluten']);
        expect(sortedAllergens(['arachides', 'gluten'])).toEqual(['arachides', 'gluten']);
    });

    it('coerces numeric entries to strings (parity with strval on backend)', () => {
        expect(sortedAllergens([2, 1, 1])).toEqual(['1', '2']);
    });
});

describe('normalizeAllergensForHash (server-parity)', () => {
    it('null and [] hash inputs are identical (G-5: no-allergens lines must merge)', () => {
        expect(normalizeAllergensForHash(null)).toEqual(normalizeAllergensForHash([]));
    });

    it('unsorted snapshots produce identical normalized arrays (G-5: server merge semantics)', () => {
        expect(normalizeAllergensForHash(['gluten', 'arachides']))
            .toEqual(normalizeAllergensForHash(['arachides', 'gluten']));
    });

    it('different non-empty snapshots produce different normalized arrays (G-5: split semantics)', () => {
        expect(normalizeAllergensForHash(['arachides']))
            .not.toEqual(normalizeAllergensForHash(['gluten']));
    });
});
