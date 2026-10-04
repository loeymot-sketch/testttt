import { describe, expect, it, beforeEach, vi } from 'vitest';
import { createStore } from 'vuex';

// [KDS-ITEM-READY-SYNC 2026-09-23] bumpItem/recallItem appellent désormais le
// serveur (audit finding #4 : la pastille "prêt" par article ne vivait qu'en
// localStorage, invisible d'un second écran). Mock réseau best-effort — ces
// tests vérifient le comportement LOCAL immédiat, qui ne doit jamais dépendre
// du réseau pour rester instantané en cuisine.
const axiosPost = vi.fn(() => Promise.resolve({ data: { status: true } }));
vi.mock('axios', () => ({ default: { post: (...args) => axiosPost(...args) } }));

const { kds: kdsModule } = await import('../../resources/js/store/modules/kds');

function buildStore() {
    return createStore({
        modules: { kds: { ...kdsModule } },
    });
}

describe('KDS bump / recall store', () => {
    beforeEach(() => {
        localStorage.removeItem('kds.bumped_items_v1');
        axiosPost.mockClear();
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

    // [Note d'isolation] Le module `kds` n'est PAS ré-instancié par test — `state`
    // est un objet PARTAGÉ entre tous les `buildStore()` de ce fichier (seul le
    // wrapper module est spread par `{ ...kdsModule }`, pas `state` en profondeur).
    // Chaque test doit donc utiliser des orderId/itemId JAMAIS réutilisés
    // ailleurs dans ce fichier — d'où la plage 901+ ci-dessous, dédiée aux tests
    // 2026-09-23 pour ne jamais collisionner avec les orderId/itemId historiques
    // (9/101, 1/55, 2/66, 5/201-202) utilisés par les tests au-dessus.
    it('[2026-09-23] bumpItem persists server-side via POST .../items/{itemId}/bump with an idempotency key', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 901, itemId: 9101 });

        expect(axiosPost).toHaveBeenCalledTimes(1);
        const [url, body, config] = axiosPost.mock.calls[0];
        expect(url).toBe('admin/kds-order/items/9101/bump');
        expect(body).toEqual({});
        expect(config.headers['X-Idempotency-Key']).toMatch(/^kds-bump-9101-/);
    });

    it('[2026-09-23] rebumping the same item stays local-idempotent AND does not re-post', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 902, itemId: 9102 });
        const first = store.getters['kds/bumpedItems'](902)[9102];
        axiosPost.mockClear();

        await store.dispatch('kds/bumpItem', { orderId: 902, itemId: 9102 });

        expect(store.getters['kds/bumpedItems'](902)[9102]).toBe(first);
        expect(axiosPost).not.toHaveBeenCalled();
    });

    it('[2026-09-23] recallItem within grace window also calls the server recall endpoint', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 903, itemId: 9103 });
        const ts = store.getters['kds/bumpedItems'](903)[9103];
        axiosPost.mockClear();

        await store.dispatch('kds/recallItem', { orderId: 903, itemId: 9103, now: ts + 15_000 });

        expect(axiosPost).toHaveBeenCalledTimes(1);
        const [url, , config] = axiosPost.mock.calls[0];
        expect(url).toBe('admin/kds-order/items/9103/recall');
        expect(config.headers['X-Idempotency-Key']).toMatch(/^kds-recall-9103-/);
    });

    it('[2026-09-23] recallItem rejected by local grace window never calls the server', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 904, itemId: 9104 });
        const ts = store.getters['kds/bumpedItems'](904)[9104];
        axiosPost.mockClear();

        await store.dispatch('kds/recallItem', { orderId: 904, itemId: 9104, now: ts + 61_000 });

        expect(axiosPost).not.toHaveBeenCalled();
    });

    it('[2026-09-23] bumpItem keeps the optimistic local state even if the network call fails', async () => {
        axiosPost.mockImplementationOnce(() => Promise.reject(new Error('network down')));
        const store = buildStore();

        await store.dispatch('kds/bumpItem', { orderId: 905, itemId: 9105 });

        expect(store.getters['kds/bumpedItems'](905)[9105]).toBeGreaterThan(0);
    });

    it('[2026-09-23] hydrateFromOrders adopts a bump made on ANOTHER screen (a second KDS device converges)', async () => {
        const store = buildStore();
        // This screen never bumped item 9107 itself — the server says a
        // colleague's screen did.
        await store.dispatch('kds/hydrateFromOrders', [
            { id: 906, order_items: [{ id: 9106, kitchen_bumped_at: null }, { id: 9107, kitchen_bumped_at: '2026-09-23T02:00:00+02:00' }] },
        ]);

        expect(store.getters['kds/bumpedItems'](906)[9107]).toBeGreaterThan(0);
        expect(store.getters['kds/bumpedItems'](906)[9106]).toBeUndefined();
    });

    it('[2026-09-23] hydrateFromOrders drops a local bump the server no longer has (recalled elsewhere)', async () => {
        const store = buildStore();
        await store.dispatch('kds/bumpItem', { orderId: 908, itemId: 9108 });
        expect(store.getters['kds/bumpedItems'](908)[9108]).toBeDefined();

        // Another screen recalled it — server now reports it as not bumped.
        await store.dispatch('kds/hydrateFromOrders', [
            { id: 908, order_items: [{ id: 9108, kitchen_bumped_at: null }] },
        ]);

        expect(store.getters['kds/bumpedItems'](908)[9108]).toBeUndefined();
    });
});
