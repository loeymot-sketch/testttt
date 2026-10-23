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

// [VG-2026-10-21 M4-101] En mode LIVRAISON, « Commande téléphone » réécrivait en silence la commande en
// « à emporter », client de passage, sans adresse ni frais (#8136 : 1,00 € au lieu de 4,90 €, succès affiché,
// client jamais livré). Le parcours « livraison payée à la porte prise par téléphone » n'est pas prévu par le
// contrat docs/LIVRAISON.md (file « À encaisser », purge « client non venu » et cuisine ne le connaissent pas) :
// la caisse le REFUSE avant tout envoi, avec un message clair, et garde le mode livraison intact.
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

describe('Caisse — « Commande téléphone » en mode livraison (M4-101)', () => {
    beforeEach(() => { axios.post.mockReset(); alertService.error.mockClear(); alertService.success.mockClear(); devisValide(); });

    it('mode LIVRAISON : refusé avant tout envoi, message clair, livraison intacte', async () => {
        const store = storeAvec(PANIER);
        const w = monter(store);
        Object.assign(w.vm.checkoutProps.form, {
            order_type: orderTypeEnum.DELIVERY, customer_id: 77, address_id: 9, branch_id: 1,
            pos_customer_name: 'VG-M4ADV Client', pos_customer_phone: '0720849973',
        });
        store.dispatch.mockClear();

        await w.vm.phoneOrderSubmit();

        const envois = store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save').map((c) => c[1]);
        expect(envois, 'aucune commande ne doit partir (elle partait en « à emporter », client de passage)').toEqual([]);
        expect(axios.post.mock.calls.map((c) => c[0])).toEqual([]);
        expect(alertService.success).not.toHaveBeenCalled();
        expect(alertService.error).toHaveBeenCalledTimes(1);
        expect(String(alertService.error.mock.calls[0][0])).toMatch(/livraison/i);
        expect(w.vm.checkoutProps.form.order_type).toBe(orderTypeEnum.DELIVERY);
        expect(w.vm.checkoutProps.form.address_id).toBe(9);
        expect(w.vm.checkoutProps.form.pos_customer_phone).toBe('0720849973');
        expect(w.vm.phoneOrderSubmitting).toBe(false);
    });

    it('mode À EMPORTER : la commande téléphone part toujours (non-régression)', async () => {
        const store = storeAvec(PANIER);
        const w = monter(store);
        Object.assign(w.vm.checkoutProps.form, { order_type: orderTypeEnum.TAKEAWAY, customer_id: 2, branch_id: 1 });
        store.dispatch.mockClear();

        await w.vm.phoneOrderSubmit();

        const envois = store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save').map((c) => c[1]);
        expect(alertService.error.mock.calls).toEqual([]);
        expect(envois).toHaveLength(1);
        expect(envois[0].order_type).toBe(orderTypeEnum.TAKEAWAY);
        expect(envois[0].phone_order).toBe(true);
    });
    // [ÉTAT DES LIEUX 2026-10-23 C-002] Le bouton n'est plus PROPOSÉ en livraison (il refusait à chaque appui).
    it('le bouton « Commande téléphone » est absent en livraison, présent à emporter', async () => {
        const w = monter(storeAvec(PANIER));
        Object.assign(w.vm.checkoutProps.form, { order_type: orderTypeEnum.TAKEAWAY });
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-phone-order"]').exists()).toBe(true);

        Object.assign(w.vm.checkoutProps.form, { order_type: orderTypeEnum.DELIVERY });
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-phone-order"]').exists()).toBe(false);
        expect(w.find('[data-testid="pos-v5-pay"]').exists(), 'le bouton « Commande » reste le chemin de la livraison').toBe(true);
    });
});
