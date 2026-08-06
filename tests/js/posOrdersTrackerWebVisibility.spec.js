import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [WEB-TRACKER-VISIBILITY 2026-07-20] Régression de la plainte owner : « j'ai passé une
// commande de test sur le web et je ne l'ai PAS visualisée dans notre système de commandes
// en cours ». Racine : ordersByStatus() ne bucketait pas PENDING (web) → carte jetée, et
// sourceOf() ne connaissait pas source_surface='web' → classée 🛒 pos. On verrouille :
//  1. sourceOf web → 'online' (chip 🌐 + onglet Online) ;
//  2. isWebPending discrimine web+PENDING (≠ cash-pending borne déjà acceptée) ;
//  3. ordersByStatus bucket la commande web PENDING dans la voie « À encaisser » ;
//  4. acceptWebOrder → POST online-order/change-status (ACCEPT) + idempotency + refresh
//     (miroir exact du panneau web caisse C1 2026-07-18).

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn() },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] },
}));

import axios from 'axios';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import alertService from '../../resources/js/services/alertService';

const makeStore = () => ({
    getters: new Proxy({ 'auth/authBranchId': 1 }, { get(t, p) { return p in t ? t[p] : undefined; } }),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(),
    commit: vi.fn(),
});

const buildHarness = () => {
    const store = makeStore();
    // Vue lie les méthodes à l'instance : wrapper.vm.fetchOrders est la version bindée,
    // pas le spy — on garde donc une référence directe au vi.fn pour les assertions.
    const fetchSpy = vi.fn(() => Promise.resolve());
    const Test = {
        ...PosOrdersTrackerComponent,
        mounted() {},
        beforeUnmount() {},
        methods: { ...PosOrdersTrackerComponent.methods, fetchOrders: fetchSpy },
    };
    const wrapper = shallowMount(Test, {
        global: {
            stubs: { transition: false, 'transition-group': false, 'router-link': true },
            mocks: {
                $store: store,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    return { wrapper, store, fetchSpy };
};

const webPendingOrder = (over = {}) => ({
    id: 901,
    status: orderStatusEnum.PENDING,
    payment_status: 10,           // UNPAID
    source_surface: 'web',
    order_type: 10,               // TAKEAWAY
    queue_number: null,
    order_serial_no: 'WEB-901',
    total: 13.3,
    created_at: new Date().toISOString(),
    ...over,
});

describe('PosOrdersTracker — visibilité commandes WEB (plainte owner)', () => {
    beforeEach(() => {
        axios.post.mockClear();
        alertService.success.mockClear();
        alertService.error.mockClear();
    });

    it('sourceOf classe source_surface=web comme online (chip 🌐), pas pos', () => {
        const { wrapper } = buildHarness();
        expect(wrapper.vm.sourceOf(webPendingOrder())).toBe('online');
        // non-régression : les autres surfaces inchangées
        expect(wrapper.vm.sourceOf({ source_surface: 'kiosk' })).toBe('kiosk');
        expect(wrapper.vm.sourceOf({ source_surface: 'pos' })).toBe('pos');
    });

    it('isWebPending vrai pour web+PENDING, faux pour borne cash-pending ou web accepté', () => {
        const { wrapper } = buildHarness();
        expect(wrapper.vm.isWebPending(webPendingOrder())).toBe(true);
        expect(wrapper.vm.isWebPending(webPendingOrder({ status: orderStatusEnum.ACCEPT }))).toBe(false);
        expect(wrapper.vm.isWebPending({ source_surface: 'kiosk', status: orderStatusEnum.PENDING })).toBe(false);
        expect(wrapper.vm.isWebPending(null)).toBe(false);
    });

    it('LA RÉGRESSION : une commande web PENDING est bucketée dans la voie À encaisser (plus jetée)', async () => {
        const { wrapper } = buildHarness();
        const web = webPendingOrder();
        wrapper.vm.orders = [web];
        await wrapper.vm.$nextTick();
        const buckets = wrapper.vm.ordersByStatus;
        expect(buckets.accept.map(o => o.id)).toContain(901);
        // et nulle part ailleurs (pas de doublon)
        expect(buckets.preparing).toHaveLength(0);
        expect(buckets.prepared).toHaveLength(0);
    });

    it('non-régression : un PENDING NON-web (téléphone/pos naissant) reste hors board', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [webPendingOrder({ id: 902, source_surface: 'pos' })];
        await wrapper.vm.$nextTick();
        const buckets = wrapper.vm.ordersByStatus;
        expect(buckets.accept).toHaveLength(0);
    });

    it('acceptWebOrder POSTe online-order/change-status ACCEPT avec idempotency + toast + refresh', async () => {
        const { wrapper, fetchSpy } = buildHarness();
        const web = webPendingOrder();
        await wrapper.vm.acceptWebOrder(web);
        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, body, cfg] = axios.post.mock.calls[0];
        expect(url).toBe('admin/online-order/change-status/901');
        // [CAISSE-WEB-INTEL 2026-08-06 · RED heal P2] preparation_time TOUJOURS envoyé
        // (défaut affiché 15) : ce que le caissier VOIT est ce qui est ENVOYÉ — sans ça,
        // un défaut settings ≠ 15 rendait le select menteur.
        expect(body).toEqual({ status: orderStatusEnum.ACCEPT, preparation_time: 15 });
        expect(cfg.headers['X-Idempotency-Key']).toMatch(/^web-accept-901-\d+$/);
        expect(alertService.success).toHaveBeenCalled();
        expect(fetchSpy).toHaveBeenCalled();
    });

    it('acceptWebOrder anti double-clic (webAccepting) + erreur backend → toast erreur', async () => {
        const { wrapper } = buildHarness();
        const web = webPendingOrder();
        wrapper.vm.webAccepting = { 901: true };
        await wrapper.vm.acceptWebOrder(web);
        expect(axios.post).not.toHaveBeenCalled();   // court-circuité

        wrapper.vm.webAccepting = {};
        axios.post.mockRejectedValueOnce({ response: { data: { message: 'nope' } } });
        await wrapper.vm.acceptWebOrder(web);
        expect(alertService.error).toHaveBeenCalledWith('nope');
    });
});
