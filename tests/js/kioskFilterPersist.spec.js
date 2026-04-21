import { describe, it, expect, beforeEach, vi } from 'vitest';
import { createStore } from 'vuex';
import kioskFilterModule from '../../resources/js/store/modules/kioskFilter.js';
import { isVariationAllowedByFilters } from '../../resources/js/helpers/kioskFilters.js';

const STORAGE_FILTERS = 'kiosk:filters';
const STORAGE_ALLERGENS = 'kiosk:customer_allergens';

function makeStore() {
    return createStore({
        modules: {
            kioskFilter: { ...kioskFilterModule },
        },
    });
}

describe('P-MEGA-09 kioskFilter store + isVariationAllowedByFilters', () => {
    let store;
    let getItem;
    let setItem;
    let removeItem;

    beforeEach(() => {
        getItem = vi.fn(() => null);
        setItem = vi.fn();
        removeItem = vi.fn();
        vi.stubGlobal('localStorage', {
            getItem,
            setItem,
            removeItem,
        });
        store = makeStore();
    });

    it('init() lit localStorage existant et hydrate state', async () => {
        getItem.mockImplementation((key) => {
            if (key === STORAGE_FILTERS) return '["gluten_free"]';
            if (key === STORAGE_ALLERGENS) return '[]';
            return null;
        });
        await store.dispatch('kioskFilter/init');
        expect(store.getters['kioskFilter/activeFilters']).toEqual(['gluten_free']);
        expect(store.getters['kioskFilter/hydrated']).toBe(true);
    });

    it('toggle() ajoute puis retire et persiste', async () => {
        getItem.mockReturnValue(null);
        await store.dispatch('kioskFilter/init');
        await store.dispatch('kioskFilter/toggle', 'vegetarian');
        expect(store.getters['kioskFilter/activeFilters']).toEqual(['vegetarian']);
        expect(setItem).toHaveBeenCalledWith(STORAGE_FILTERS, '["vegetarian"]');
        await store.dispatch('kioskFilter/toggle', 'vegetarian');
        expect(store.getters['kioskFilter/activeFilters']).toEqual([]);
        expect(setItem).toHaveBeenCalledWith(STORAGE_FILTERS, '[]');
    });

    it('reset() vide state + localStorage', async () => {
        getItem.mockImplementation((key) => {
            if (key === STORAGE_FILTERS) return '["halal"]';
            if (key === STORAGE_ALLERGENS) return '["gluten"]';
            return null;
        });
        await store.dispatch('kioskFilter/init');
        await store.dispatch('kioskFilter/reset');
        expect(store.getters['kioskFilter/activeFilters']).toEqual([]);
        expect(store.getters['kioskFilter/customerAllergens']).toEqual([]);
        expect(removeItem).toHaveBeenCalledWith(STORAGE_FILTERS);
        expect(removeItem).toHaveBeenCalledWith(STORAGE_ALLERGENS);
    });

    it('localStorage corrompu → fallback []', async () => {
        getItem.mockReturnValue('not json{{');
        await store.dispatch('kioskFilter/init');
        expect(store.getters['kioskFilter/activeFilters']).toEqual([]);
        expect(store.getters['kioskFilter/hydrated']).toBe(true);
    });

    it('Mode privé (localStorage throw) — init ne crash pas', async () => {
        getItem.mockImplementation(() => {
            throw new Error('private');
        });
        await expect(store.dispatch('kioskFilter/init')).resolves.toBeUndefined();
        expect(store.getters['kioskFilter/activeFilters']).toEqual([]);
    });

    it('isVariationAllowedByFilters — prédicats et tolérance', () => {
        expect(isVariationAllowedByFilters({ is_vegetarian: false }, ['vegetarian'])).toBe(false);
        expect(isVariationAllowedByFilters({}, ['gluten_free'])).toBe(true);
        expect(isVariationAllowedByFilters({ is_gluten_free: false }, ['gluten_free'])).toBe(false);
        expect(isVariationAllowedByFilters({ is_vegetarian: true }, [])).toBe(true);
    });
});
