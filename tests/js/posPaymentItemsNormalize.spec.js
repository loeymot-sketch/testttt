import { describe, it, expect } from 'vitest';
import { normalizeCartForApi } from '../../resources/js/store/modules/posCart';

/**
 * V14 Vague B - HOLE B-6 sentinel
 *
 * Reproduces the PaymentComponent.vue normalization sequence:
 *   - PosComponent.vue#orderSubmit JSON.stringifies form.items before
 *     opening the payment modal.
 *   - PaymentComponent.vue#confirmOrder must:
 *       1. Detect string vs array,
 *       2. Parse if string,
 *       3. Run normalizeCartForApi (multi-qty + ID coercion),
 *       4. Re-stringify so the backend ValidJsonOrder rule keeps receiving
 *          a JSON string.
 *
 * Bug B-6: passing the raw JSON string to normalizeCartForApi yields []
 * (Array.isArray check fails) -> empty cart -> ValidJsonOrder 422.
 */
function paymentNormalize(rawItems) {
    let arr;
    if (typeof rawItems === 'string') {
        try { arr = JSON.parse(rawItems) || []; }
        catch (_e) { arr = []; }
    } else if (Array.isArray(rawItems)) {
        arr = rawItems;
    } else {
        arr = [];
    }
    const normalized = normalizeCartForApi(arr);
    return (typeof rawItems === 'string') ? JSON.stringify(normalized) : normalized;
}

const cart = [
    {
        item_id: '42',
        branch_id: '7',
        quantity: 1,
        convert_price: 9.5,
        item_variation_total: 0,
        item_extra_total: 0,
        item_variations: [{ id: '101', quantity: 2 }, { id: '102' }],
        item_extras: [{ id: '201', quantity: 3 }],
    },
];

describe('PaymentComponent items normalization (HOLE B-6 sentinel)', () => {
    it('keeps cart non-empty when form.items is a JSON string (the actual POS path)', () => {
        const stringInput = JSON.stringify(cart);
        const out = paymentNormalize(stringInput);

        expect(typeof out).toBe('string');
        const parsed = JSON.parse(out);
        expect(Array.isArray(parsed)).toBe(true);
        expect(parsed).toHaveLength(1);
        expect(parsed[0].item_id).toBe(42);
        expect(parsed[0].branch_id).toBe(7);
    });

    it('preserves multi-qty in variations + extras (T05/T20 contract)', () => {
        const stringInput = JSON.stringify(cart);
        const parsed = JSON.parse(paymentNormalize(stringInput));

        const v = parsed[0].item_variations;
        expect(Array.isArray(v)).toBe(true);
        const v101 = v.find((x) => x.id === 101);
        const v102 = v.find((x) => x.id === 102);
        expect(v101.quantity).toBe(2);
        expect(v102.quantity).toBe(1);

        const e = parsed[0].item_extras;
        expect(Array.isArray(e)).toBe(true);
        expect(e.find((x) => x.id === 201).quantity).toBe(3);
    });

    it('still works when an array is passed (back-compat)', () => {
        const out = paymentNormalize(cart);
        expect(Array.isArray(out)).toBe(true);
        expect(out).toHaveLength(1);
        expect(out[0].item_id).toBe(42);
    });

    it('returns "[]" (string) on malformed JSON input rather than crashing', () => {
        const out = paymentNormalize('{not valid json');
        expect(out).toBe('[]');
    });

    it('returns "[]" on empty string instead of throwing', () => {
        const out = paymentNormalize('');
        expect(out).toBe('[]');
    });

    it('regression: raw normalizeCartForApi(string) DOES return [] (proves bug existed)', () => {
        const empty = normalizeCartForApi(JSON.stringify(cart));
        expect(empty).toEqual([]);
    });
});
