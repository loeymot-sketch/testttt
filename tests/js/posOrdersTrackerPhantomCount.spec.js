import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [D-2 HEAL 2026-07-24 · reports/audit-sync-gestion-2026-07-23/D-caisse-kds-findings.md §D-2]
// Bucket fantôme : `fetchOrders` tire les commandes du JOUR sans filtre de statut, donc une
// commande PENDING NON-web (panier borne abandonné, commande téléphone/pos naissante,
// source NULL) tombe dans `this.orders` et gonfle `stats.todayCount` — MAIS `ordersByStatus`
// ne la bucketise nulle part (seul le web PENDING a une voie « À encaisser »). Résultat :
// le compteur « X aujourd'hui » ment (compte des cartes invisibles ; 162 en base).
// Option (a) retenue : EXCLURE les PENDING non-web non-actionnables de `todayCount` — le
// compteur ne compte plus que ce qui est réellement représentable sur le board. Le web
// PENDING (actionnable, CTA Accepter) reste compté ET dans sa voie ; le bucketing est
// INCHANGÉ (le non-web PENDING reste hors board, comme avant).

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
    const store = makeStore();
    const Test = {
        ...PosOrdersTrackerComponent,
        mounted() {},
        beforeUnmount() {},
        methods: { ...PosOrdersTrackerComponent.methods, fetchOrders: vi.fn(() => Promise.resolve()) },
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
    return { wrapper };
};

const webPending = (over = {}) => ({
    id: 901, status: orderStatusEnum.PENDING, payment_status: 10,
    source_surface: 'web', order_type: 10, order_serial_no: 'WEB-901',
    total: 13.3, created_at: new Date().toISOString(), ...over,
});
const nonWebPending = (over = {}) => ({
    id: 902, status: orderStatusEnum.PENDING, payment_status: 10,
    source_surface: 'kiosk', order_type: 17, order_serial_no: 'K-902',
    total: 9.9, created_at: new Date().toISOString(), ...over,
});
const preparing = (over = {}) => ({
    id: 903, status: orderStatusEnum.PREPARING, payment_status: 5,
    source_surface: 'pos', order_type: 15, order_serial_no: 'P-903',
    total: 18.0, created_at: new Date().toISOString(), ...over,
});

describe('PosOrdersTracker — compteur honnête (D-2 bucket fantôme)', () => {
    let wrapper;
    beforeEach(() => { ({ wrapper } = buildHarness()); });

    it('LA RÉGRESSION : un PENDING non-web n\'inflate plus stats.todayCount', async () => {
        // Board : 1 web PENDING (voie À encaisser), 1 kiosk PENDING abandonné (fantôme),
        // 1 en préparation. Seuls les 2 représentables comptent — le fantôme est retiré.
        wrapper.vm.orders = [webPending(), nonWebPending(), preparing()];
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.stats.todayCount).toBe(2);
    });

    it('tous les PENDING non-web (kiosk/null/pos/téléphone) sont exclus du compteur', async () => {
        wrapper.vm.orders = [
            nonWebPending({ id: 910, source_surface: 'kiosk' }),
            nonWebPending({ id: 911, source_surface: null }),
            nonWebPending({ id: 912, source_surface: 'pos' }),
            nonWebPending({ id: 913, source_surface: 'phone' }),
        ];
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.stats.todayCount).toBe(0);
    });

    it('le web PENDING reste COMPTÉ et dans sa voie « À encaisser » (CTA Accepter intact)', async () => {
        wrapper.vm.orders = [webPending()];
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.stats.todayCount).toBe(1);
        expect(wrapper.vm.ordersByStatus.accept.map(o => o.id)).toContain(901);
    });

    it('buckets existants intacts : le non-web PENDING reste HORS board, le PREPARING dans sa voie', async () => {
        wrapper.vm.orders = [webPending(), nonWebPending(), preparing()];
        await wrapper.vm.$nextTick();
        const b = wrapper.vm.ordersByStatus;
        expect(b.accept.map(o => o.id)).toEqual([901]);      // web pending seul en À encaisser
        expect(b.preparing.map(o => o.id)).toEqual([903]);   // preparing en préparation
        // le fantôme kiosk (902) n'apparaît dans AUCUN bucket
        const everywhere = [...b.accept, ...b.preparing, ...b.prepared, ...b.onTheWay, ...b.delivered].map(o => o.id);
        expect(everywhere).not.toContain(902);
    });

    it('active/ready inchangés (le fantôme ne les touchait déjà pas)', async () => {
        wrapper.vm.orders = [webPending(), nonWebPending(), preparing()];
        await wrapper.vm.$nextTick();
        // active = accept + preparing + prepared = 1(web) + 1(prepa) + 0
        expect(wrapper.vm.stats.active).toBe(2);
        expect(wrapper.vm.stats.ready).toBe(0);
    });
});
