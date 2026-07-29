import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [S2 F1 2026-07-29 · reports/goal-s2-caisse-stock/V1-RED-VERDICTS.md]
// Régression : une commande cash-pending (PENDING_COUNTER + COUNTER_DEFERRED)
// doit apparaître dans la voie « À encaisser » QUEL QUE SOIT son statut cuisine.
// Repro d'origine : 5 commandes PREPARED cash-pending visibles dans
// /admin/encaissement mais « À ENCAISSER = 0 » sur le tracker (le prédicat
// n'était évalué que sous status === ACCEPT).

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

import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

const makeStore = () => ({
    getters: new Proxy({ 'auth/authBranchId': 1 }, { get(t, p) { return p in t ? t[p] : undefined; } }),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
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

// PENDING_COUNTER = 15, COUNTER_DEFERRED = 6 (fallback enum de isCashPending).
const cashPending = (id, status) => ({
    id,
    status,
    order_status: status,
    payment_status: 15,
    pos_payment_method: 6,
    source_surface: 'pos',
    created_at: '2026-07-29 10:00:00',
});

describe('Tracker — cash-pending appartient à « À encaisser » quel que soit le statut cuisine', () => {
    it.each([
        ['ACCEPT', orderStatusEnum.ACCEPT],
        ['PREPARING', orderStatusEnum.PREPARING],
        ['PREPARED', orderStatusEnum.PREPARED],
    ])('bucket accept pour un cash-pending en %s', (_label, status) => {
        const wrapper = buildHarness();
        wrapper.vm.orders = [cashPending(101, status)];
        const buckets = wrapper.vm.ordersByStatus;
        expect(buckets.accept.map((o) => o.id)).toContain(101);
        expect(buckets.preparing.map((o) => o.id)).not.toContain(101);
        expect(buckets.prepared.map((o) => o.id)).not.toContain(101);
    });

    it('un PREPARED non cash-pending reste dans « Prêts »', () => {
        const wrapper = buildHarness();
        const o = { id: 202, status: orderStatusEnum.PREPARED, order_status: orderStatusEnum.PREPARED, payment_status: 5, pos_payment_method: 1, created_at: '2026-07-29 10:00:00' };
        wrapper.vm.orders = [o];
        const buckets = wrapper.vm.ordersByStatus;
        expect(buckets.prepared.map((x) => x.id)).toContain(202);
        expect(buckets.accept.map((x) => x.id)).not.toContain(202);
    });
});
