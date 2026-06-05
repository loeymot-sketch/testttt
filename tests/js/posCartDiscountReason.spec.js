import { describe, it, expect, beforeEach } from 'vitest';
import { posCart, _applyPosCartScope } from '../../resources/js/store/modules/posCart.js';

/**
 * M4-02 — the manual-discount REASON must persist with the cart so a page
 * reload (cart restored from localStorage) keeps it. Before the fix only the
 * discount AMOUNT was persisted; on reload orderSubmit sent discount>0 with an
 * empty reason → backend 422 ("a reason is required"), invisible to the cashier.
 *
 * Tests the REAL posCart store module (mutations + saveCartToStorage + hydrateFromScope).
 */
let lastSaved = null;
const lsMock = (() => {
    let s = {};
    return {
        getItem: (k) => (k in s ? s[k] : null),
        setItem: (k, v) => { s[k] = String(v); try { lastSaved = JSON.parse(v); } catch (e) { /* noop */ } },
        removeItem: (k) => { delete s[k]; },
        clear: () => { s = {}; lastSaved = null; },
    };
})();
Object.defineProperty(global, 'localStorage', { value: lsMock, configurable: true });
if (typeof window !== 'undefined') {
    Object.defineProperty(window, 'localStorage', { value: lsMock, configurable: true });
}

describe('M4-02 posCart discountReason persistence', () => {
    beforeEach(() => {
        lsMock.clear();
        _applyPosCartScope(1, 1); // scope so saveCartToStorage actually persists
    });

    it('persists discountReason to localStorage alongside the discount amount', () => {
        const state = { lists: [{ item_id: 1 }], subtotal: 10, discount: 0, discountReason: '', restoredFromStorage: false };

        posCart.mutations.discount(state, 5);
        posCart.mutations.discountReason(state, 'Geste commercial');

        expect(lastSaved).not.toBeNull();
        expect(lastSaved.discount).toBe(5);
        expect(lastSaved.discountReason).toBe('Geste commercial');
    });

    it('restores discountReason via hydrateFromScope (the reload path)', () => {
        const state = { lists: [{ item_id: 1 }], subtotal: 10, discount: 5, discountReason: 'Geste commercial', restoredFromStorage: false };
        posCart.mutations.discount(state, 5);
        posCart.mutations.discountReason(state, 'Geste commercial');
        const saved = lastSaved; // what would be in localStorage after a reload

        const fresh = { lists: [], subtotal: 0, discount: 0, discountReason: '', restoredFromStorage: false };
        posCart.mutations.hydrateFromScope(fresh, saved);

        expect(fresh.discount).toBe(5);
        expect(fresh.discountReason).toBe('Geste commercial'); // <-- was lost before the fix
    });

    it('clears discountReason on resetCart', () => {
        const state = { lists: [{ item_id: 1 }], subtotal: 10, discount: 5, discountReason: 'X', restoredFromStorage: false };
        posCart.mutations.resetCart(state);
        expect(state.discountReason).toBe('');
        expect(state.discount).toBe(0);
    });

    it('getter exposes discountReason', () => {
        const state = { discountReason: 'Motif' };
        expect(posCart.getters.discountReason(state)).toBe('Motif');
    });
});
