import { describe, it, expect, beforeEach, vi } from 'vitest';
import { posCart } from '../../resources/js/store/modules/posCart';

const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (k) => (Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null),
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
        clear: () => { store = {}; },
    };
})();
Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock, writable: true });

const minimalItem = {
    name: 'Test',
    image: '',
    item_id: 42,
    quantity: 1,
    discount: 0,
    currency_price: '1€',
    convert_price: 1,
    item_variations: [],
    item_extras: [],
    item_variation_total: 0,
    item_extra_total: 0,
    instruction: '',
    pos_line_addons: [],
    cart_display: '',
};

function freshState() {
    return {
        lists: [],
        subtotal: 0,
        discount: 0,
        restoredFromStorage: false,
    };
}

describe('posCart optimistic add (T12)', () => {
    beforeEach(() => {
        localStorageMock.clear();
    });

    it('addOptimistic commits a line with _optimistic: true immediately', () => {
        const state = freshState();
        const commit = vi.fn((type, payload) => {
            if (type === '__optimisticAdd') {
                posCart.mutations.__optimisticAdd(state, payload);
            } else if (type === 'subtotal') {
                posCart.mutations.subtotal(state);
            }
        });
        const tempId = posCart.actions.addOptimistic({ commit }, { item: minimalItem });
        expect(tempId).toBeTruthy();
        expect(commit).toHaveBeenCalledWith('__optimisticAdd', { tempId: tempId, item: minimalItem });
        expect(state.lists).toHaveLength(1);
        expect(state.lists[0]._optimistic).toBe(true);
        expect(state.lists[0]._tempId).toBe(tempId);
    });

    it('__optimisticConfirm clears optimistic meta (single-line merge fix)', () => {
        const state = freshState();
        const tempId = 't1';
        posCart.mutations.__optimisticAdd(state, { tempId, item: minimalItem });
        expect(state.lists[0].quantity).toBe(1);
        state.lists[0].quantity = 2;
        posCart.mutations.__optimisticConfirm(state, tempId);
        expect(state.lists).toHaveLength(1);
        expect(state.lists[0].quantity).toBe(1);
        expect(state.lists[0]._optimistic).toBeUndefined();
        expect(state.lists[0]._tempId).toBeUndefined();
    });

    it('__optimisticRollback removes the provisional line', () => {
        const state = freshState();
        const tempId = 't2';
        posCart.mutations.__optimisticAdd(state, { tempId, item: minimalItem });
        posCart.mutations.__optimisticRollback(state, tempId);
        expect(state.lists).toHaveLength(0);
    });

    it('backward-compat: core merge/remove mutations keep their signatures', () => {
        expect(posCart.mutations.lists).toBeTypeOf('function');
        expect(posCart.mutations.lists.length).toBe(2);
        expect(posCart.mutations.deleteCartItem).toBeTypeOf('function');
        expect(posCart.mutations.deleteCartItem.length).toBe(2);
    });
});
