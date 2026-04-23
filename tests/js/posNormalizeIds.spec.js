import { describe, expect, it } from 'vitest';

import {
    normalizeCartForApi,
    normalizeExtrasPayload,
    normalizeId,
    normalizeQuantity,
    normalizeVariationEntries,
    normalizeVariationsPayload,
} from '../../resources/js/helpers/posNormalizeIds';

describe('posNormalizeIds', () => {
    it('normalizes numeric ids from strings and integers', () => {
        expect(normalizeId('42')).toBe(42);
        expect(normalizeId(42)).toBe(42);
        expect(normalizeId('042')).toBe(42);
    });

    it('rejects empty, null, invalid and non-positive ids', () => {
        expect(normalizeId('')).toBeNull();
        expect(normalizeId(null)).toBeNull();
        expect(normalizeId(undefined)).toBeNull();
        expect(normalizeId('-1')).toBeNull();
        expect(normalizeId('abc')).toBeNull();
        expect(normalizeId(NaN)).toBeNull();
        expect(normalizeId('0')).toBeNull();
    });

    it('floors decimal ids before returning them', () => {
        expect(normalizeId(1.5)).toBe(1);
        expect(normalizeId('9.9')).toBe(9);
    });

    it('normalizes quantities with floor and fallback guards', () => {
        expect(normalizeQuantity('3')).toBe(3);
        expect(normalizeQuantity(1.9)).toBe(1);
        expect(normalizeQuantity(null, 1)).toBe(1);
        expect(normalizeQuantity('0', 2)).toBe(2);
    });

    it('normalizes variation payloads and drops invalid ids', () => {
        expect(normalizeVariationsPayload([
            { id: '12', quantity: '3' },
            { id: '', quantity: 9 },
            { id: '7.8', quantity: 0 },
        ])).toEqual([
            { id: 12, quantity: 3 },
            { id: 7, quantity: 1 },
        ]);
    });

    it('normalizes extras payloads and preserves extra fields', () => {
        expect(normalizeExtrasPayload([
            { id: '5', quantity: '2', name: 'Cheddar' },
            { id: null, quantity: 4, name: 'Ignore' },
        ])).toEqual([
            { id: 5, quantity: 2, name: 'Cheddar' },
        ]);
    });

    it('normalizes outgoing cart items for api payloads', () => {
        expect(normalizeCartForApi([
            {
                item_id: '14',
                branch_id: '3',
                item_variations: [{ id: '9', quantity: '4' }],
                item_extras: [{ id: '17', quantity: '2' }],
            },
        ])).toEqual([
            {
                item_id: 14,
                branch_id: 3,
                item_variations: [{ id: 9, quantity: 4 }],
                item_extras: [{ id: 17, quantity: 2 }],
            },
        ]);
    });

    it('normalizeVariationEntries preserves variation_name + name (wizard edit / restore path)', () => {
        const out = normalizeVariationEntries([
            {
                id: '1',
                item_attribute_id: '2',
                quantity: '1',
                name: 'Steak',
                variation_name: 'Viande 1',
            },
        ]);
        expect(out[0].variation_name).toBe('Viande 1');
        expect(out[0].name).toBe('Steak');
        expect(out[0].id).toBe(1);
    });
});
