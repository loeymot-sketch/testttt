import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import axios from 'axios';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [VG-2026-10-21 M9-103] GET admin/pos/web-orders/pending renvoie au plus 200 commandes (les plus récentes depuis
// a6dcc5074) et, en plus, meta {total, returned, limit, truncated} — forme relevée par curl sur :8766 :
// { data: [...], meta: { total: 131, returned: 131, limit: 200, truncated: false } }. Au-delà de 200, des commandes
// web sortaient de la file caisse SANS un mot.
const cmd = (id, created_at) => ({ id, order_type: 10, queue_number: `W${id}`, total: 10, created_at, order_items: [] });

function monter() {
    const store = {
        getters: new Proxy({
            'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
            'frontendLanguage/show': { display_mode: 0 },
            'auth/authBranchId': 1, 'auth/authInfo': {}, 'posCart/lists': [], 'posCart/subtotal': 0, 'posCart/discount': 0,
        }, { get(target, property) { return property in target ? target[property] : []; } }),
        dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
        commit: vi.fn(),
    };
    const Test = {
        ...PosComponent,
        mounted() {},
        beforeUnmount() {},
        computed: { ...PosComponent.computed, canProcessWebOrders: () => true },
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(() => Promise.resolve()), loadActiveOrdersStats: vi.fn(() => Promise.resolve()), loadReadyOrders: vi.fn(() => Promise.resolve()),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            _notifyPolledNewOrders: vi.fn(), totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

describe('caisse — file des commandes web tronquée (M9-103)', () => {
    beforeEach(() => { axios.get.mockReset(); });

    it('au-delà de la limite, la file DIT combien de commandes plus anciennes ne sont pas affichées', async () => {
        axios.get.mockResolvedValueOnce({ data: {
            data: [cmd(9002, '2026-10-22T17:08:00+02:00'), cmd(9001, '2026-10-22T17:00:00+02:00')],
            meta: { total: 205, returned: 200, limit: 200, truncated: true },
        } });
        const w = monter();
        await w.vm.loadWebOrders();
        await w.vm.$nextTick();
        const avis = w.find('[data-testid="pos-shortcuts-web-truncated"]');
        expect(avis.exists()).toBe(true);
        expect(avis.text()).toContain('5 commandes plus anciennes non affichées');
        w.unmount();
    });

    it('file complète : aucun avis', async () => {
        axios.get.mockResolvedValueOnce({ data: {
            data: [cmd(9001, '2026-10-22T17:00:00+02:00')],
            meta: { total: 1, returned: 1, limit: 200, truncated: false },
        } });
        const w = monter();
        await w.vm.loadWebOrders();
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-shortcuts-web-truncated"]').exists()).toBe(false);
        w.unmount();
    });

    it('une seule commande cachée : singulier', async () => {
        axios.get.mockResolvedValueOnce({ data: { data: [cmd(9001, '2026-10-22T17:00:00+02:00')], meta: { total: 201, returned: 200, limit: 200, truncated: true } } });
        const w = monter();
        await w.vm.loadWebOrders();
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-shortcuts-web-truncated"]').text()).toContain('1 commande plus ancienne non affichée');
        w.unmount();
    });
});
