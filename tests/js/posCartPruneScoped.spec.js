import { describe, it, expect, beforeEach } from 'vitest';
import { posCart, _applyPosCartScope } from '../../resources/js/store/modules/posCart';

/**
 * [P12 / POS-9.1.9] pruneUnavailable must persist only under getScopedKey()
 * and must not cross-contaminate branch-scoped keys.
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

function makeStateWithLines(itemIds) {
    return {
        lists: itemIds.map((id) => ({
            item_id: id,
            name: `Item ${id}`,
            quantity: 1,
            convert_price: 5.0,
            item_variation_total: 0,
            item_extra_total: 0,
            item_variations: { variations: {}, names: {} },
            item_extras: { extras: [], names: [] },
            image: null,
            instruction: '',
            discount: 0,
            currency_price: '5.00€',
            pos_line_addons: [],
            cart_display: '',
        })),
        subtotal: itemIds.length * 5.0,
        discount: 0,
        restoredFromStorage: false,
    };
}

describe('posCart/pruneUnavailable scoped persistence [P12_POS_CART_PRUNE / POS-9.1.9 interaction]', () => {
    beforeEach(() => {
        localStorageMock.clear();
        _applyPosCartScope(null, null);
    });

    it('scope unset → pruneUnavailable does not persist', () => {
        const state = makeStateWithLines([1]);
        posCart.mutations.pruneUnavailable(state, 1);
        expect(state.lists).toHaveLength(0);
        expect(localStorageMock._dump()).toEqual([]);
    });

    it('scope set → pruneUnavailable persists under the scoped key only', () => {
        _applyPosCartScope(7, 42);
        const state = makeStateWithLines([1, 2]);
        posCart.mutations.pruneUnavailable(state, 1);
        expect(state.lists).toHaveLength(1);
        expect(state.lists[0].item_id).toBe(2);
        const keys = localStorageMock._dump();
        expect(keys).toEqual(['pos_cart_v3:b7:u42']);
        const raw = localStorageMock.getItem('pos_cart_v3:b7:u42');
        const parsed = JSON.parse(raw);
        expect(parsed.lists).toHaveLength(1);
        expect(parsed.lists[0].item_id).toBe(2);
        expect(parsed.branchId).toBe(7);
        expect(parsed.userId).toBe(42);
    });

    it('scope set + no-op (unknown item) → no localStorage write', () => {
        _applyPosCartScope(7, 42);
        const state = makeStateWithLines([1]);
        posCart.mutations.pruneUnavailable(state, 999);
        expect(state.lists).toHaveLength(1);
        expect(localStorageMock._dump()).toEqual([]);
    });

    it('isolation: two branch keys coexist without cross-write (b7 vs b8)', () => {
        _applyPosCartScope(7, 42);
        const state7 = makeStateWithLines([1, 2]);
        posCart.mutations.pruneUnavailable(state7, 1);
        expect(localStorageMock._dump()).toEqual(['pos_cart_v3:b7:u42']);

        _applyPosCartScope(8, 42);
        const state8 = makeStateWithLines([1]);
        posCart.mutations.pruneUnavailable(state8, 1);

        const keys = localStorageMock._dump().slice().sort();
        expect(keys).toEqual(['pos_cart_v3:b7:u42', 'pos_cart_v3:b8:u42'].sort());

        const b7 = JSON.parse(localStorageMock.getItem('pos_cart_v3:b7:u42'));
        const b8 = JSON.parse(localStorageMock.getItem('pos_cart_v3:b8:u42'));
        expect(b7.lists.map((l) => l.item_id)).toEqual([2]);
        expect(b8.lists).toEqual([]);
        expect(b7.branchId).toBe(7);
        expect(b8.branchId).toBe(8);
        expect(b7.userId).toBe(42);
        expect(b8.userId).toBe(42);
    });
});
