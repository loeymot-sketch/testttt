import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/services/alertService', () => ({ default: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import * as livraison from '../../resources/js/helpers/posLivraison.js';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [VG-2026-10-21 M4-002] Forme RÉELLE (curl :8766 après 3211854f5) : counter-collect/pending et web-orders/pending
// portent `delivery_slot_at` (ISO 8601, heure murale du restaurant + décalage) pour une LIVRAISON, null sinon.
// #8099 : « tout de suite » prise à 00:00, départ 23:30 ; #8115 : départ le LENDEMAIN 00:30.
const LIV_SOIR = { id: 8099, order_type: 5, queue_number: 'A0001', total: 9.8, created_at: '2026-10-22T00:00:14+02:00', order_datetime: '12:00 AM, 22-10-2026', delivery_slot_at: '2026-10-22T23:30:00+02:00', prevue_le: null, order_items: [] };
const LIV_DEMAIN = { id: 8115, order_type: 5, queue_number: 'A0002', total: 9.8, created_at: '2026-10-22T00:04:00+02:00', order_datetime: '12:04 AM, 22-10-2026', delivery_slot_at: '2026-10-23T00:30:00+02:00', prevue_le: null, order_items: [] };
const EMPORTER = { id: 8200, order_type: 10, queue_number: 'A0003', total: 5, created_at: '2026-10-22T17:00:00+02:00', order_datetime: '05:00 PM, 22-10-2026', delivery_slot_at: null, order_items: [] };
const WEB_LIV = { id: 8392, order_type: 5, queue_number: 'W0001', total: 12, created_at: '2026-10-22T17:08:18+02:00', delivery_slot_at: '2026-10-22T22:30:00+02:00', prevue_le: '2026-10-22', order_items: [] };

function monter() {
    const store = {
        getters: new Proxy({
            'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
            'frontendLanguage/show': { display_mode: 0 },
            'auth/authBranchId': 1,
            'auth/authInfo': {},
            'posCart/lists': [],
            'posCart/subtotal': 0,
            'posCart/discount': 0,
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
            ensureCustomersHydratedForCheckout: vi.fn(() => Promise.resolve()), ensureWalkInCustomer: vi.fn(() => Promise.resolve(true)),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

describe('caisse — heure de départ d\'une livraison dans les files (M4-002)', () => {
    beforeEach(() => { vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date(2026, 9, 22, 17, 40)); });
    afterEach(() => { vi.useRealTimers(); });

    it('même fonction d\'affichage que le devis : aujourd\'hui / demain avec la date / rien hors livraison', () => {
        expect(livraison.describeOrderDeparture(LIV_SOIR)).toBe('Départ prévu aujourd\'hui à 23:30');
        expect(livraison.describeOrderDeparture(LIV_DEMAIN)).toBe('Départ prévu demain (ven. 23/10) à 00:30');
        expect(livraison.describeOrderDeparture(EMPORTER)).toBe('');
        expect(livraison.describeOrderDeparture(null)).toBe('');
        // Le devis et la file disent la même chose pour la même tournée.
        const devis = { enabled: true, allowed: true, waves: [{ wave_at: '2026-10-23 00:30:00', full: false }] };
        expect(livraison.describeDeparture(devis)).toBe(livraison.describeOrderDeparture(LIV_DEMAIN));
    });

    it('la file « À encaisser » (raccourcis + panneau complet) et la file web affichent le départ', async () => {
        const w = monter();
        w.vm.kioskCashOrders = [LIV_SOIR, LIV_DEMAIN, EMPORTER];
        w.vm.webOrders = [WEB_LIV];
        w.vm.showKioskCashPanel = true; // panneau complet « À encaisser » ouvert
        await w.vm.$nextTick();

        expect(w.find('[data-testid="pos-shortcut-depart-8099"]').text()).toContain('Départ prévu aujourd\'hui à 23:30');
        expect(w.find('[data-testid="pos-shortcut-depart-8115"]').text()).toContain('Départ prévu demain (ven. 23/10) à 00:30');
        expect(w.find('[data-testid="pos-shortcut-depart-8200"]').exists()).toBe(false);
        expect(w.find('[data-testid="kiosk-cash-depart-8115"]').text()).toContain('demain (ven. 23/10) à 00:30');
        expect(w.find('[data-testid="pos-shortcut-web-depart-8392"]').text()).toContain('Départ prévu aujourd\'hui à 22:30');
        w.unmount();
    });
});
