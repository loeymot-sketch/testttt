import { describe, it, expect, beforeEach } from 'vitest';
import { posCart, _applyPosCartScope } from '../../resources/js/store/modules/posCart';

/**
 * [POS-9.1.9] posCart localStorage must be scoped per (branch_id, user_id).
 *
 * Audit POS-GA-F-41 — without scoping, cashier B logging in on the same
 * machine as cashier A inherits A's open cart, which leaks customer/order
 * data and corrupts the next ticket.
 */

const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (k) => Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null,
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
        clear: () => { store = {}; },
        _dump: () => Object.keys(store),
    };
})();
Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock, writable: true });

function makeStateWithLine() {
    return {
        lists: [{
            item_id: 1, name: 'Tacos M', quantity: 2,
            convert_price: 6.50, item_variation_total: 0, item_extra_total: 0,
            item_variations: { variations: {}, names: {} },
            item_extras: { extras: [], names: [] },
            image: null, instruction: '', discount: 0,
            currency_price: '6.50€', pos_line_addons: [], cart_display: '',
        }],
        subtotal: 13.00,
        discount: 0,
        restoredFromStorage: false,
    };
}

describe('posCart scoped localStorage [POS-9.1.9]', () => {
    beforeEach(() => {
        localStorageMock.clear();
        // Reset module-level scope.
        _applyPosCartScope(null, null);
    });

    it('does not persist any cart while scope is unset (no auth yet)', () => {
        const state = makeStateWithLine();
        // subtotal mutation triggers saveCartToStorage internally — simulate it
        // by going through the public mutation API.
        posCart.mutations.subtotal(state);
        expect(localStorageMock._dump()).toHaveLength(0);
    });

    it('writes ONLY under pos_cart_v3:b<branch>:u<user> once scope is set', () => {
        _applyPosCartScope(7, 42);
        const state = makeStateWithLine();
        posCart.mutations.subtotal(state);
        const keys = localStorageMock._dump();
        expect(keys).toEqual(['pos_cart_v3:b7:u42']);
    });

    it('cashier B does NOT see cashier A cart on the same machine', () => {
        // Cashier A on branch 7
        _applyPosCartScope(7, 42);
        posCart.mutations.subtotal(makeStateWithLine());
        expect(localStorageMock.getItem('pos_cart_v3:b7:u42')).toBeTruthy();

        // Cashier B (user 99) logs in on the same machine, same branch
        _applyPosCartScope(7, 99);
        const restored = JSON.parse(localStorageMock.getItem('pos_cart_v3:b7:u42') || 'null');
        expect(restored).toBeTruthy(); // A's cart still on disk

        // setScope for B should hydrate an empty state (B has no saved cart).
        // [W5-PERF B1 2026-07-06] setScope commits hydrateFromScope PUIS
        // subtotal (recompute des totaux de lignes restaurées) — le mock
        // vérifie l'ordre et le payload null du restore.
        const commits = [];
        const fakeContext = {
            commit: (mutation, payload) => { commits.push({ mutation, payload }); },
        };
        posCart.actions.setScope(fakeContext, { branchId: 7, userId: 99 });
        expect(commits.map((c) => c.mutation)).toEqual(['hydrateFromScope', 'subtotal']);
        // payload is null because b7:u99 key does not exist
        expect(commits[0].payload).toBeNull();
    });

    it('cross-scope tampering: a saved cart with a different branchId is rejected', () => {
        // Manually plant a key whose stored branchId/userId mismatch the active scope.
        localStorageMock.setItem('pos_cart_v3:b7:u42', JSON.stringify({
            lists: [{ item_id: 999 }],
            subtotal: 5,
            discount: 0,
            savedAt: Date.now(),
            branchId: 999,   // <-- mismatch
            userId: 42,
        }));
        _applyPosCartScope(7, 42);
        // setScope must hydrate empty (refused due to mismatch).
        // [W5-PERF B1] hydrateFromScope(null) puis recompute subtotal.
        const commits = [];
        const fakeContext = {
            commit: (mutation, payload) => { commits.push({ mutation, payload }); },
        };
        posCart.actions.setScope(fakeContext, { branchId: 7, userId: 42 });
        expect(commits.map((c) => c.mutation)).toEqual(['hydrateFromScope', 'subtotal']);
        expect(commits[0].payload).toBeNull();
    });

    it('purges the legacy unscoped key on every setScope', () => {
        localStorageMock.setItem('pos_cart_v2', JSON.stringify({ lists: [{}], subtotal: 1, discount: 0, savedAt: Date.now() }));
        _applyPosCartScope(7, 42);
        expect(localStorageMock.getItem('pos_cart_v2')).toBeNull();
    });
});
