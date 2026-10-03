import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

// [GOAL REMARQUES 2026-10-03 · T-3.6 R-038 · Codex P1-19] Un supplément libre COMPLÈTE une commande : seul
// dans un panier vide, il ferait une vente d'un « Supplément — X » sans produit. Rien ne l'empêchait.

function storeAvec(lignes) {
    return {
        getters: new Proxy({
            'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
            'frontendLanguage/show': { display_mode: 0 },
            'auth/authBranchId': 1,
            'auth/authInfo': {},
            'posCart/lists': lignes,
            'posCart/subtotal': 0,
            'posCart/discount': 0,
        }, { get(target, property) { return property in target ? target[property] : []; } }),
        dispatch: vi.fn(() => Promise.resolve({ data: { data: { branch_id: 1 } } })),
        commit: vi.fn(),
    };
}

function monter(store) {
    const Test = {
        ...PosComponent,
        mounted() {},
        beforeUnmount() {},
        methods: {
            ...PosComponent.methods,
            closeSidebar: vi.fn(), itemCategories: vi.fn(), itemList: vi.fn(),
            loadKioskCashOrders: vi.fn(() => Promise.resolve()), loadActiveOrdersStats: vi.fn(() => Promise.resolve()), loadReadyOrders: vi.fn(() => Promise.resolve()),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), formatKioskPrice: vi.fn((a) => `${a} EUR`), formatKioskTime: vi.fn(() => '10:00'),
            openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

describe('Caisse — supplément libre interdit sur un panier sans produit (R-038)', () => {
    let store;
    beforeEach(() => { store = null; });

    it('panier vide : refusé avec un message, rien n\'est ajouté', () => {
        store = storeAvec([]);
        const w = monter(store);
        w.vm.manualSupplement = { open: true, label: 'Sauce maison', amount: '1,50', editIndex: null, error: '' };
        store.dispatch.mockClear();
        w.vm.saveManualSupplement();
        expect(w.vm.manualSupplement.error).toMatch(/produit/i);
        expect(store.dispatch.mock.calls.filter((c) => c[0] === 'posCart/lists')).toHaveLength(0);
    });

    it('panier ne contenant QUE des suppléments libres : refusé aussi', () => {
        store = storeAvec([{ line_type: 'manual_supplement', manual_label: 'X', manual_amount: 1 }]);
        const w = monter(store);
        w.vm.manualSupplement = { open: true, label: 'Y', amount: '2', editIndex: null, error: '' };
        store.dispatch.mockClear();
        w.vm.saveManualSupplement();
        expect(store.dispatch.mock.calls.filter((c) => c[0] === 'posCart/lists')).toHaveLength(0);
    });

    it('avec un produit au panier : le supplément s\'ajoute', () => {
        store = storeAvec([{ item_id: 22, name: 'Cayenne', quantity: 1 }]);
        const w = monter(store);
        w.vm.manualSupplement = { open: true, label: 'Sauce maison', amount: '1,50', editIndex: null, error: '' };
        store.dispatch.mockClear();
        w.vm.saveManualSupplement();
        expect(store.dispatch.mock.calls.filter((c) => c[0] === 'posCart/lists')).toHaveLength(1);
    });
});
