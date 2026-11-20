import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import VueNextSelect from 'vue-next-select';

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

// [E2E 2026-10-23 C2-001] « TypeError: Cannot read properties of undefined (reading 'id') » à CHAQUE « Commande livraison »
// (console de la caisse, audit caisse-site-complet). Cause prouvée : vue-next-select plante si la valeur (customer_id du
// client créé par téléphone) arrive AVANT son option ; la garde M4-104 ajoutait l'option dans le MÊME cycle, trop tard.
// Son test ne vérifiait que le calcul isolé. Ici : le VRAI sélecteur, monté dans la caisse, et la vraie séquence.
const ERREURS = [];
const PANIER = [{
    item_id: 22, name: 'Cayenne', quantity: 1, price: 1, currency_price: '1,00 €', total_price: 1, item_variations: [], item_extras: [],
    item_variation_total: 0, item_extra_total: 0, discount: 0, instruction: '',
}];

function storeAvec(lignes, clients = []) {
    return {
        getters: new Proxy({
            'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
            'frontendLanguage/show': { display_mode: 0 },
            'auth/authBranchId': 1,
            'auth/authInfo': {},
            'posCart/lists': lignes,
            'user/lists': clients,
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
        global: { components: { 'vue-select': VueNextSelect }, stubs: { transition: false, 'vue-select': false }, config: { errorHandler: (e) => ERREURS.push(String(e)) }, mocks: { $store: store, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}


function reseau() {
    axios.post.mockImplementation((url) => {
        if (url === '/admin/pos-delivery/customer') return Promise.resolve({ data: { data: { id: 8858, name: 'Bob Livraison' } } });
        if (url === '/admin/pos-delivery/address') return Promise.resolve({ data: { data: { id: 55, allowed: true, fee: 3.9, distance_km: 1.04 } } });
        if (url === 'admin/pos/quote') return Promise.resolve({ data: { data: { quote_token: 'qt', signature: 'sig', total_ttc: 4.9 } } });
        return Promise.resolve({ data: {} });
    });
}

describe('caisse — sélecteur client réel pendant « Commande livraison » (C2-001)', () => {
    beforeEach(() => { ERREURS.length = 0; axios.post.mockReset(); alertService.error.mockClear(); alertService.success.mockClear(); reseau(); });

    for (const [cas, clients] of [['liste de clients chargée', [{ id: 1, name: 'Client comptoir' }, { id: 2, name: 'Alice' }]], ['liste encore vide', []]]) {
        it(`client créé par téléphone, ${cas} : aucune erreur, la commande part`, async () => {
            const store = storeAvec(PANIER, clients);
            const w = monter(store);
            Object.assign(w.vm.checkoutProps.form, { order_type: orderTypeEnum.DELIVERY, customer_id: null, address_id: null, branch_id: 1 });
            Object.assign(w.vm.deliveryInline, { phone: '06 12 34 56 78', name: 'Bob Livraison', address: '10 Rue de Test 62110 Hénin-Beaumont', confirmed: true });
            await flushPromises();
            store.dispatch.mockClear();

            await w.vm.deliveryOrderSubmit();
            await flushPromises();

            expect(ERREURS, 'le sélecteur client ne doit jamais planter').toEqual([]);
            expect(alertService.error).not.toHaveBeenCalled();
            const envois = store.dispatch.mock.calls.filter((c) => c[0] === 'posOrder/save').map((c) => c[1]);
            expect(envois).toHaveLength(1);
            expect(envois[0]).toMatchObject({ customer_id: 8858, address_id: 55, delivery_door_payment: true });
        });
    }

    it('la garde d\'avant (option ajoutée dans le même cycle) plantait : instrument prouvé', async () => {
        const Parent = {
            components: { VueNextSelect },
            data: () => ({ choisi: 1 }),
            computed: { options() { const l = [{ id: 1, name: 'A' }]; return l.some((u) => u.id === this.choisi) ? l : l.concat([{ id: this.choisi, name: 'X' }]); } },
            template: '<VueNextSelect v-model="choisi" :options="options" value-by="id" label-by="name" />',
        };
        const erreurs = [];
        const { mount } = await import('@vue/test-utils');
        const w = mount(Parent, { global: { config: { errorHandler: (e) => erreurs.push(String(e)) } } });
        w.vm.choisi = 9;
        await flushPromises();
        expect(erreurs.join(' ')).toContain("reading 'id'");
    });
});
