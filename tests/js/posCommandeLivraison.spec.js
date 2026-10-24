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

// [CAISSE 2026-10-23 · demande propriétaire] Bouton « Commande livraison » : la livraison (adresse + téléphone) est
// enregistrée SANS encaissement, le livreur encaisse à la porte. Elle reste une LIVRAISON (adresse, frais) ; marqueur
// serveur `delivery_door_payment`, jamais `phone_order` (que la purge « client non venu » annulerait).
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

function monter(store, options = {}) {
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
        ...options,
        global: { stubs: { transition: false }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

function devisValide() {
    axios.post.mockImplementation((url) => (url === 'admin/pos/quote'
        ? Promise.resolve({ data: { data: { quote_token: 'qt', signature: 'sig', total_ttc: 4.9 } } })
        : Promise.resolve({ data: {} })));
}

function enLivraison(w, extra = {}) {
    Object.assign(w.vm.checkoutProps.form, {
        order_type: orderTypeEnum.DELIVERY, customer_id: 77, address_id: 9, branch_id: 1, ...extra,
    });
    Object.assign(w.vm.deliveryInline, { phone: '06 12 34 56 78', name: 'Sophie Martin' });
}

describe('Caisse — « Commande livraison » (paiement à la porte)', () => {
    beforeEach(() => { axios.post.mockReset(); alertService.error.mockClear(); alertService.success.mockClear(); devisValide(); });

    it('le bouton existe en livraison, pas à emporter ; « Commande téléphone » le remplace', async () => {
        const w = monter(storeAvec(PANIER));
        Object.assign(w.vm.checkoutProps.form, { order_type: orderTypeEnum.TAKEAWAY });
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-delivery-order"]').exists()).toBe(false);
        expect(w.find('[data-testid="pos-phone-order"]').exists()).toBe(true);

        Object.assign(w.vm.checkoutProps.form, { order_type: orderTypeEnum.DELIVERY });
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-delivery-order"]').exists()).toBe(true);
        expect(w.find('[data-testid="pos-delivery-order"]').text()).toContain('Commande livraison');
        expect(w.find('[data-testid="pos-delivery-order"]').text(), 'l\'indice reste visible même quand le sous-titre est masqué').toContain('paiement à la porte');
        expect(w.find('[data-testid="pos-phone-order"]').exists()).toBe(false);
    });

    it('envoie une LIVRAISON différée : adresse, téléphone, marqueur porte — sans phone_order ni montant client', async () => {
        const store = storeAvec(PANIER);
        const w = monter(store);
        enLivraison(w);
        store.dispatch.mockClear();

        await w.vm.deliveryOrderSubmit();

        const quoteCall = axios.post.mock.calls.find((c) => c[0] === 'admin/pos/quote');
        expect(quoteCall[1]).toMatchObject({ order_type: orderTypeEnum.DELIVERY, address_id: 9, customer_id: 77, delivery_door_payment: true });
        const envois = store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save').map((c) => c[1]);
        expect(envois).toHaveLength(1);
        expect(envois[0]).toMatchObject({
            order_type: orderTypeEnum.DELIVERY, address_id: 9, customer_id: 77, delivery_door_payment: true,
            pos_customer_phone: '06 12 34 56 78', pos_customer_name: 'Sophie Martin', quote_token: 'qt', quote_signature: 'sig',
        });
        expect(envois[0]).toHaveProperty('scheduled_at', null);
        expect(envois[0].phone_order, 'jamais phone_order : la purge « client non venu » l\'annulerait').toBeUndefined();
        expect(envois[0]).not.toHaveProperty('total');
        expect(envois[0]).not.toHaveProperty('delivery_charge');
        expect(alertService.error).not.toHaveBeenCalled();
        expect(String(alertService.success.mock.calls[0][0])).toContain('4,90');
        expect(String(alertService.success.mock.calls[0][0])).toContain('par le livreur');
        expect(w.vm.deliveryOrderSubmitting).toBe(false);
        // Le client ne survit pas à sa commande.
        expect(w.vm.checkoutProps.form.address_id).toBe(null);
        expect(w.vm.checkoutProps.form.order_type).toBe(orderTypeEnum.TAKEAWAY);
        expect(w.vm.deliveryInline.phone).toBe('');
    });

    it('sans téléphone : refusé avant tout envoi, avec le motif', async () => {
        const store = storeAvec(PANIER);
        const w = monter(store, { attachTo: document.body });
        enLivraison(w);
        Object.assign(w.vm.deliveryInline, { phone: '' });
        store.dispatch.mockClear();

        await w.vm.deliveryOrderSubmit();

        expect(store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save')).toEqual([]);
        expect(axios.post.mock.calls).toEqual([]);
        expect(String(alertService.error.mock.calls[0][0])).toMatch(/téléphone/i);
        await new Promise((r) => setTimeout(r, 0));
        expect(document.activeElement && document.activeElement.id, 'le caissier est amené au champ téléphone').toBe('pos-delivery-phone');
        w.unmount();
    });

    it('sans adresse choisie : refusé, rien n\'est envoyé', async () => {
        const store = storeAvec(PANIER);
        const w = monter(store);
        enLivraison(w, { address_id: null });
        store.dispatch.mockClear();

        await w.vm.deliveryOrderSubmit();

        expect(store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save')).toEqual([]);
        expect(alertService.error).toHaveBeenCalled();
        expect(String(alertService.error.mock.calls[0][0])).toMatch(/adresse/i);
    });

    it('un refus serveur est montré tel quel et ne vide pas le panier', async () => {
        const store = storeAvec(PANIER);
        store.dispatch = vi.fn((action) => (action === 'posOrder/save'
            ? Promise.reject({ response: { data: { errors: { pos_customer_phone: ['Téléphone obligatoire.'] } } } })
            : Promise.resolve({ data: { data: {} } })));
        const w = monter(store);
        enLivraison(w);

        await w.vm.deliveryOrderSubmit();

        expect(alertService.error).toHaveBeenCalledWith('Téléphone obligatoire.');
        expect(alertService.success).not.toHaveBeenCalled();
        expect(store.dispatch.mock.calls.map((c) => c[0])).not.toContain('posCart/resetCart');
        expect(w.vm.checkoutProps.form.address_id).toBe(9);
        expect(w.vm.deliveryOrderSubmitting).toBe(false);
    });
    it('une livraison programmée porte son heure (« Programmer » n\'est pas ignoré en silence)', async () => {
        const store = storeAvec(PANIER);
        const w = monter(store);
        enLivraison(w, { scheduled_at: '2026-10-24 19:30:00' });
        store.dispatch.mockClear();

        await w.vm.deliveryOrderSubmit();

        const envois = store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save').map((c) => c[1]);
        expect(envois[0].scheduled_at).toBe('2026-10-24 19:30:00');
        expect(w.vm.checkoutProps.form.scheduled_at, 'la programmation ne fuit pas vers la commande suivante').toBe(null);
    });
});
