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
import alertService from '../../resources/js/services/alertService';
import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';
import lodash from 'lodash';

// buildFormItemsJson utilise le `_` global (lodash, posé par bootstrap.js dans l'application).
globalThis._ = globalThis._ || lodash;

// [VG-2026-10-21 M4-106] « ✓ Livré » n'est plus proposé sur une LIVRAISON non encaissée (paiement à la porte) :
// un clic la sortait définitivement du Z. Elle se remet par l'écran du livreur.

const PANIER = [{
    item_id: 22, name: 'Cayenne', quantity: 1, price: 1, currency_price: '1,00 €', total_price: 1, item_variations: [], item_extras: [],
    item_variation_total: 0, item_extra_total: 0, discount: 0, instruction: '',
}];

function storeAvec(lignes) {
    return {
        getters: new Proxy({
            'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
            'frontendLanguage/show': { display_mode: 0 },
            'auth/authBranchId': 1,
            'auth/authInfo': {},
            'posCart/lists': lignes,
            'posCart/subtotal': 1,
            'posCart/discount': 0,
        }, { get(target, property) { return property in target ? target[property] : []; } }),
        dispatch: vi.fn(() => Promise.resolve({ data: { data: { id: 8136, branch_id: 1 } } })),
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
            ensureCustomersHydratedForCheckout: vi.fn(() => Promise.resolve()), ensureWalkInCustomer: vi.fn(() => Promise.resolve(true)),
            _subscribeEcho: vi.fn(), _startKioskPolling: vi.fn(), _bindWsService: vi.fn(), _unsubscribeEcho: vi.fn(), _unbindWsService: vi.fn(),
            totalItems: vi.fn(() => 0), currencyFormat: vi.fn(() => '0 EUR'), formatKioskPrice: vi.fn((a) => `${a} EUR`), formatKioskTime: vi.fn(() => '10:00'),
            openCanvas: vi.fn(), closeCanvas: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

function devisValide() {
    axios.post.mockImplementation((url) => (url === 'admin/pos/quote'
        ? Promise.resolve({ data: { data: { quote_token: 'qt', signature: 'sig', total_ttc: 1 } } })
        : Promise.resolve({ data: {} })));
}

describe('Caisse — « ✓ Livré » sur une livraison non encaissée (M4-106)', () => {
    beforeEach(() => { alertService.error.mockClear(); });

    const pret = orderStatusEnum.PREPARED;
    const LIVRAISON_SITE_A_ENCAISSER = { id: 7888, queue_number: 'A0044', total: 4.9, source_surface: 'delivery', order_type: orderTypeEnum.DELIVERY, payment_status: paymentStatusEnum.PENDING_COUNTER, status: pret };
    const LIVRAISON_PAYEE = { id: 7890, queue_number: 'A0050', total: 4.9, source_surface: 'pos', order_type: orderTypeEnum.DELIVERY, payment_status: paymentStatusEnum.PAID, status: pret };
    const TEL_A_EMPORTER = { id: 7981, queue_number: 'A0060', total: 1.9, source_surface: 'phone', order_type: orderTypeEnum.TAKEAWAY, payment_status: paymentStatusEnum.PENDING_COUNTER, status: pret };

    it('panneau « Prêt à livrer » : pas de bouton « Livré » sur la livraison à encaisser à la porte ; présent ailleurs', async () => {
        const w = monter(storeAvec([]));
        w.vm.readyOrders = [LIVRAISON_SITE_A_ENCAISSER, LIVRAISON_PAYEE, TEL_A_EMPORTER];
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-shortcut-ready-7888"]').exists(), 'la livraison reste visible (prête pour le livreur)').toBe(true);
        expect(w.find('[data-testid="pos-shortcut-deliver-7888"]').exists()).toBe(false);
        expect(w.find('[data-testid="pos-shortcut-door-7888"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-shortcut-deliver-7890"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-shortcut-deliver-7981"]').exists()).toBe(true);
    });

    it('markDelivered (tiroir ou panneau) refuse une livraison non encaissée sans rien envoyer', async () => {
        const store = storeAvec([]);
        const w = monter(store);
        store.dispatch.mockClear();
        await w.vm.markDelivered({ ...LIVRAISON_SITE_A_ENCAISSER });
        expect(store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/changeStatus')).toEqual([]);
        expect(alertService.error).toHaveBeenCalledTimes(1);
        expect(String(alertService.error.mock.calls[0][0])).toMatch(/livreur/i);
    });

    it('markDelivered garde la remise d\'une livraison déjà payée', async () => {
        const store = storeAvec([]);
        const w = monter(store);
        store.dispatch.mockClear();
        await w.vm.markDelivered({ ...LIVRAISON_PAYEE });
        expect(store.dispatch).toHaveBeenCalledWith('posOrder/changeStatus', { id: 7890, status: orderStatusEnum.DELIVERED });
    });
});
