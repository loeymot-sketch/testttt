import { describe, it, expect } from 'vitest';
import { kioskMenu } from '../../resources/js/store/modules/kioskMenu.js';

/**
 * Kiosk Phase 9.1.5 — Mutation `UPDATE_ITEM` doit :
 *  - patcher `is_available` + `unavailable_reason` (broadcast ou 409 local),
 *  - rester idempotente / non-destructrice,
 *  - rétrocompat status sans is_available.
 */
describe('kioskMenu.UPDATE_ITEM — is_available + unavailable_reason', () => {
    function buildState(items = []) {
        return JSON.parse(JSON.stringify({ ...kioskMenu.state, items }));
    }

    it('patch is_available=false + unavailable_reason depuis un broadcast branch-scoped', () => {
        const state = buildState([
            { id: 42, name: 'Burger', status: 1, is_available: true, unavailable_reason: null, allergens: ['gluten'] },
        ]);

        kioskMenu.mutations.UPDATE_ITEM(state, {
            item_id: 42,
            type: 'branch_availability',
            is_available: false,
            reason: 'stock_rupture',
        });

        expect(state.items[0].is_available).toBe(false);
        expect(state.items[0].unavailable_reason).toBe('stock_rupture');
        expect(state.items[0].allergens).toEqual(['gluten']);
    });

    it('ne touche pas aux champs absents du payload (merge non-destructif)', () => {
        const state = buildState([
            { id: 7, name: 'Tacos', price: 8.5, category: 'tacos', variations: { x: 1 }, is_available: true },
        ]);

        kioskMenu.mutations.UPDATE_ITEM(state, { item_id: 7, status: 1 });

        expect(state.items[0].price).toBe(8.5);
        expect(state.items[0].category).toBe('tacos');
        expect(state.items[0].variations).toEqual({ x: 1 });
    });

    it('déduit is_available depuis status si is_available absent (rétrocompat)', () => {
        const state = buildState([{ id: 9, status: 1, is_available: true }]);

        kioskMenu.mutations.UPDATE_ITEM(state, { item_id: 9, status: 0 });
        expect(state.items[0].is_available).toBe(false);

        kioskMenu.mutations.UPDATE_ITEM(state, { item_id: 9, status: 1 });
        expect(state.items[0].is_available).toBe(true);
    });

    it('nettoie unavailable_reason quand l’item redevient disponible', () => {
        const state = buildState([
            { id: 5, status: 0, is_available: false, unavailable_reason: 'stock_rupture' },
        ]);

        kioskMenu.mutations.UPDATE_ITEM(state, {
            item_id: 5,
            is_available: true,
        });

        expect(state.items[0].is_available).toBe(true);
        expect(state.items[0].unavailable_reason).toBeNull();
    });

    it('type="full" invalide le cache sans écraser les items en place', () => {
        const state = buildState([{ id: 1, is_available: true }]);
        state.lastFetchedAt = Date.now();

        kioskMenu.mutations.UPDATE_ITEM(state, { type: 'full' });

        expect(state.lastFetchedAt).toBeNull();
        expect(state.items[0].is_available).toBe(true);
    });

    it('no-op si item_id inconnu (pas de side-effect)', () => {
        const state = buildState([{ id: 1, is_available: true }]);

        kioskMenu.mutations.UPDATE_ITEM(state, {
            item_id: 999,
            is_available: false,
        });

        expect(state.items[0].is_available).toBe(true);
    });

    it('tolère un payload null/invalide sans throw', () => {
        const state = buildState([{ id: 1 }]);
        expect(() => kioskMenu.mutations.UPDATE_ITEM(state, null)).not.toThrow();
        expect(() => kioskMenu.mutations.UPDATE_ITEM(state, undefined)).not.toThrow();
    });
});
