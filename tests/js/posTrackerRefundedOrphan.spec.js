import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [SYNC gateway-refund coherence 2026-08-05] Régression du P2 « orphelin caisse » (audit LOGIQUE L1) :
// un refund GATEWAY (Mollie/Stripe webhook) pose payment_status=REFUNDED SANS toucher `status` →
// le KDS/OSS retirent la carte (KitchenReleaseRule::applyBoardReleaseFilter exclut REFUNDED) mais le
// tracker POS, bucketé par STATUT, la montrait « en préparation », un-bumpable = carte orpheline.
// On verrouille : une commande REFUNDED (payment_status=20) sort des voies ACTIVES du tracker
// (miroir du board-release), sans faux blocage des commandes PAID.

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

import { onEvents } from '../../resources/js/services/eventContract';
import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

const makeStore = () => ({
    getters: new Proxy({ 'auth/authBranchId': 1 }, { get(t, p) { return p in t ? t[p] : undefined; } }),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(),
    commit: vi.fn(),
});

const buildHarness = () => {
    const Test = {
        ...PosOrdersTrackerComponent,
        mounted() {},
        beforeUnmount() {},
        methods: { ...PosOrdersTrackerComponent.methods, fetchOrders: vi.fn(() => Promise.resolve()) },
    };
    return shallowMount(Test, {
        global: {
            stubs: { transition: false, 'transition-group': false, 'router-link': true },
            mocks: {
                $store: makeStore(),
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
};

const preparingOrder = (over = {}) => ({
    id: 701,
    status: orderStatusEnum.PREPARING,
    payment_status: 5,            // PAID par défaut
    source_surface: 'web',
    order_type: 10,
    queue_number: 'A0007',
    order_serial_no: 'ORD-701',
    total: 18.5,
    created_at: new Date().toISOString(),
    ...over,
});

describe('PosOrdersTracker — commande remboursée (gateway) n\'orpheline plus la caisse', () => {
    beforeEach(() => { vi.clearAllMocks(); });

    it('isRefunded vrai pour payment_status=REFUNDED(20), faux sinon', () => {
        const wrapper = buildHarness();
        expect(wrapper.vm.isRefunded(preparingOrder({ payment_status: 20 }))).toBe(true);
        expect(wrapper.vm.isRefunded(preparingOrder({ payment_status: 5 }))).toBe(false);
        expect(wrapper.vm.isRefunded(null)).toBe(false);
    });

    it('LA RÉGRESSION : une commande PREPARING mais REFUNDED sort de la voie « en préparation »', async () => {
        const wrapper = buildHarness();
        wrapper.vm.orders = [preparingOrder({ payment_status: 20 })];
        await wrapper.vm.$nextTick();
        const buckets = wrapper.vm.ordersByStatus;
        expect(buckets.preparing).toHaveLength(0);
        expect(buckets.accept).toHaveLength(0);
        expect(buckets.prepared).toHaveLength(0);
        expect(buckets.delivered).toHaveLength(0); // exclue de TOUTES les voies actives
    });

    it('non-régression : une commande PREPARING PAYÉE reste bien « en préparation »', async () => {
        const wrapper = buildHarness();
        wrapper.vm.orders = [preparingOrder({ payment_status: 5 })];
        await wrapper.vm.$nextTick();
        const buckets = wrapper.vm.ordersByStatus;
        expect(buckets.preparing.map(o => o.id)).toContain(701);
    });

    it('le tracker s\'abonne à OrderPaymentStatusChanged (le refund gateway pousse en temps-réel)', () => {
        window.Echo = {}; // truthy → _subscribeEcho procède
        const wrapper = buildHarness();
        wrapper.vm._subscribeEcho();
        expect(onEvents).toHaveBeenCalled();
        const bindings = onEvents.mock.calls[onEvents.mock.calls.length - 1][1];
        expect(bindings.map((b) => b.broadcastAs)).toContain('OrderPaymentStatusChanged');
        delete window.Echo;
    });
});
