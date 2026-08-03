import { describe, it, expect, beforeEach, vi } from 'vitest';

// Mock localStorage (cf. posCart.spec.js + vi.fn sur setItem pour no-op)
const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (key) => store[key] || null,
        setItem: vi.fn((key, value) => { store[key] = value.toString(); }),
        removeItem: (key) => { delete store[key]; },
        clear: () => { store = {}; }
    };
})();
Object.defineProperty(window, 'localStorage', { value: localStorageMock });

function saveCartToStorage(state) {
    localStorage.setItem('pos_cart_v2', JSON.stringify({
        lists: state.lists,
        subtotal: state.subtotal,
        discount: state.discount,
        savedAt: Date.now(),
    }));
}

// Re-implementation: identical copy from resources/js/store/modules/posCart.js (mutation body)
function pruneUnavailable(state, itemId) {
    const id = parseInt(itemId, 10);
    if (!id) return;
    const before = state.lists.length;
    state.lists = state.lists.filter(line => parseInt(line.item_id, 10) !== id);
    if (state.lists.length !== before) {
        state.discount = 0;
        saveCartToStorage(state);
    }
}

describe('posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02]', () => {
    beforeEach(() => {
        localStorageMock.clear();
        localStorageMock.setItem.mockClear();
    });

    it('happy path: removes matching line, resets discount, persists cart', () => {
        const state = {
            lists: [
                { item_id: 1, name: 'A' },
                { item_id: 2, name: 'B' },
                { item_id: 3, name: 'C' },
            ],
            subtotal: 42,
            discount: 7,
        };
        pruneUnavailable(state, 2);
        expect(state.lists.map((l) => l.item_id)).toEqual([1, 3]);
        expect(state.discount).toBe(0);
        expect(localStorageMock.setItem).toHaveBeenCalledTimes(1);
        expect(localStorageMock.setItem).toHaveBeenCalledWith(
            'pos_cart_v2',
            expect.any(String),
        );
        const saved = JSON.parse(localStorageMock.getItem('pos_cart_v2'));
        expect(saved.lists.map((l) => l.item_id)).toEqual([1, 3]);
        expect(saved.subtotal).toBe(42);
        expect(saved.discount).toBe(0);
        expect(typeof saved.savedAt).toBe('number');
    });

    it('no-op when item_id is unknown: cart, discount unchanged; no persistence', () => {
        const state = {
            lists: [
                { item_id: 1 },
                { item_id: 2 },
                { item_id: 3 },
            ],
            subtotal: 10,
            discount: 4,
        };
        pruneUnavailable(state, 99);
        expect(state.lists.map((l) => l.item_id)).toEqual([1, 2, 3]);
        expect(state.discount).toBe(4);
        expect(localStorageMock.setItem).not.toHaveBeenCalled();
    });

    it('early return for falsy itemId (null, 0, empty string, undefined)', () => {
        const line = { item_id: 1 };
        const state = { lists: [line], subtotal: 5, discount: 2 };
        pruneUnavailable(state, null);
        pruneUnavailable(state, 0);
        pruneUnavailable(state, '');
        pruneUnavailable(state, undefined);
        expect(state.lists).toEqual([line]);
        expect(state.discount).toBe(2);
        expect(localStorageMock.setItem).not.toHaveBeenCalled();
    });

    it('string itemId is cast with parseInt so numeric id matches', () => {
        const state = {
            lists: [{ item_id: 5, name: 'X' }],
            subtotal: 9,
            discount: 1,
        };
        pruneUnavailable(state, '5');
        expect(state.lists).toEqual([]);
        expect(state.discount).toBe(0);
        expect(localStorageMock.setItem).toHaveBeenCalled();
    });

    it('removes every line sharing the same item_id', () => {
        const state = {
            lists: [
                { item_id: 7, variant: 'a' },
                { item_id: 7, variant: 'b' },
            ],
            subtotal: 20,
            discount: 3,
        };
        pruneUnavailable(state, 7);
        expect(state.lists).toEqual([]);
        expect(state.discount).toBe(0);
        expect(localStorageMock.setItem).toHaveBeenCalledTimes(1);
    });

    it('discount reset only when a line was removed (no-op keeps discount)', () => {
        const state = {
            lists: [{ item_id: 1 }],
            subtotal: 8,
            discount: 5,
        };
        pruneUnavailable(state, 99);
        expect(state.lists).toEqual([{ item_id: 1 }]);
        expect(state.discount).toBe(5);
        expect(localStorageMock.setItem).not.toHaveBeenCalled();
    });
});
