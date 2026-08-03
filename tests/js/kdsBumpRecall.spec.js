import { describe, expect, it, beforeEach } from 'vitest';
import { createStore } from 'vuex';

import { kds as kdsModule } from '../../resources/js/store/modules/kds';

function buildStore() {
    return createStore({
        modules: { kds: { ...kdsModule } },
    });
}

describe('KDS bump / recall store', () => {
    beforeEach(() => {
        localStorage.removeItem('kds.bumped_items_v1');
    });

    it('bumpItem records bumped timestamp for order line', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 9, itemId: 101 });
        const b = store.getters['kds/bumpedItems'](9);
        expect(typeof b[101]).toBe('number');
        expect(b[101]).toBeGreaterThan(0);
    });

    it('recallItem clears bump when within 60s grace window', async () => {
        const store = buildStore();
        const t0 = 1_700_000_000_000;
        await store.dispatch('kds/bumpItem', { orderId: 1, itemId: 55 });
        const ts = store.getters['kds/bumpedItems'](1)[55];
        const r = await store.dispatch('kds/recallItem', {
            orderId: 1,
            itemId: 55,
            now: ts + 15_000,
        });
        expect(r.ok).toBe(true);
        expect(store.getters['kds/bumpedItems'](1)[55]).toBeUndefined();
    });

    it('recallItem rejects when grace window expired', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 2, itemId: 66 });
        const ts = store.getters['kds/bumpedItems'](2)[66];
        const r = await store.dispatch('kds/recallItem', {
            orderId: 2,
            itemId: 66,
            now: ts + 61_000,
        });
        expect(r.ok).toBe(false);
        expect(r.reason).toBe('grace_expired');
        expect(store.getters['kds/bumpedItems'](2)[66]).toBeDefined();
    });

    it('isReadyOrder is true when every order line id is bumped', async () => {
        const store = buildStore();
        const order = {
            id: 5,
            status: 3,
            order_items: [{ id: 201 }, { id: 202 }],
        };
        await store.dispatch('kds/bumpItem', { orderId: 5, itemId: 201 });
        expect(store.getters['kds/isReadyOrder'](order)).toBe(false);
        await store.dispatch('kds/bumpItem', { orderId: 5, itemId: 202 });
        expect(store.getters['kds/isReadyOrder'](order)).toBe(true);
    });
});
