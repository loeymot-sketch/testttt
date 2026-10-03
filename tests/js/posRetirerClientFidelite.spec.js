import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import fs from 'node:fs';
import path from 'node:path';

vi.mock('axios', () => ({ default: { get: vi.fn(() => Promise.resolve({ data: {} })), post: vi.fn(() => Promise.resolve({ data: {} })) } }));
vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({ default: { name: 'LoadingComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({ default: { name: 'ItemComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({ default: { name: 'PaymentComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({ default: { name: 'CreateCustomerAddressComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({ default: { name: 'CustomerAddressCreateComponent', template: '<div />' } }));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({ default: { name: 'ConnectionStatusBanner', template: '<div />' } }));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';
import orderTypeEnum from '../../resources/js/enums/modules/orderTypeEnum';

// [GOAL REMARQUES 2026-10-03 · T-3.2 R-015] Propriétaire, 21/08 : « je me connecte sur le système de
// fidélité de Client et après je veux annuler […] j'arrive pas ça reste pour toute la command ». Le client
// ne se détachait qu'en vidant le panier ou en changeant de client. Un ✕ sur la pastille fidélité le retire
// de la vente EN COURS — sans toucher au panier, et sans laisser de rachat de points armé.

const storeMock = {
    getters: new Proxy({
        'frontendSetting/lists': { site_digit_after_decimal_point: 2, site_default_currency_symbol: 'EUR', site_currency_position: 'left', pos_dine_in_enabled: 0 },
        'frontendLanguage/show': { display_mode: 0 },
        'auth/authBranchId': 1,
        'auth/authInfo': {},
        'posCart/subtotal': 0,
        'posCart/discount': 0,
    }, { get(target, property) { return property in target ? target[property] : []; } }),
    dispatch: vi.fn(() => Promise.resolve({ data: { data: { branch_id: 1 } } })),
    commit: vi.fn(),
};

function monter() {
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
            openCanvas: vi.fn(), closeCanvas: vi.fn(), gettingUserAddress: vi.fn(), _loadCustomerLoyalty: vi.fn(),
        },
    };
    return shallowMount(Test, {
        global: { stubs: { transition: false }, mocks: { $store: storeMock, $t: (k) => k, $route: { query: {}, params: {} }, $router: { push: vi.fn(), replace: vi.fn() } } },
    });
}

describe('Caisse — retirer le client fidélité de la vente en cours (R-015)', () => {
    it('le ✕ apparaît sur la pastille fidélité', async () => {
        const w = monter();
        w.vm.selectedCustomerLoyalty = { code: 'CAY-42', points: 120, loading: false };
        await w.vm.$nextTick();
        expect(w.find('[data-testid="pos-loyalty-detach"]').exists()).toBe(true);
    });

    it('retire client, code fidélité et rachat de points — le panier reste intact', async () => {
        const w = monter();
        w.vm.checkoutProps.form.customer_id = 17;
        w.vm.checkoutProps.form.loyalty_customer_code = 'CAY-42';
        w.vm.checkoutProps.form.loyalty_redeem_points = 50;
        w.vm.selectedCustomerLoyalty = { code: 'CAY-42', points: 120, loading: false };
        storeMock.commit.mockClear();

        w.vm.retirerClientFidelite();

        expect(w.vm.checkoutProps.form.customer_id).toBeNull();
        expect(w.vm.checkoutProps.form.loyalty_customer_code).toBeNull();
        expect(w.vm.checkoutProps.form.loyalty_redeem_points).toBeNull();
        expect(w.vm.selectedCustomerLoyalty.code).toBeNull();
        expect(storeMock.commit.mock.calls.map((c) => c[0]).filter((n) => /posCart\/(reset|clear|remove)/.test(n))).toEqual([]);
    });

    it('[revue vague 3 · P2-2] en LIVRAISON, le client et son adresse restent ; seule la fidélité part', () => {
        const w = monter();
        w.vm.checkoutProps.form.order_type = orderTypeEnum.DELIVERY;
        w.vm.checkoutProps.form.customer_id = 17;
        w.vm.checkoutProps.form.address_id = 99;
        w.vm.checkoutProps.form.loyalty_customer_code = 'CAY-42';
        w.vm.checkoutProps.form.loyalty_redeem_points = 50;
        w.vm.selectedCustomerLoyalty = { code: 'CAY-42', points: 120, loading: false };

        w.vm.retirerClientFidelite();

        expect(w.vm.checkoutProps.form.customer_id).toBe(17);
        expect(w.vm.checkoutProps.form.address_id).toBe(99);
        expect(w.vm.checkoutProps.form.loyalty_customer_code).toBeNull();
        expect(w.vm.checkoutProps.form.loyalty_redeem_points).toBeNull();
        expect(w.vm.selectedCustomerLoyalty.code).toBeNull();
    });

    it('[revue vague 3 · P2-2] à emporter, plus aucune adresse armée ne reste après le retrait du client', () => {
        const w = monter();
        w.vm.checkoutProps.form.order_type = orderTypeEnum.TAKEAWAY;
        w.vm.checkoutProps.form.customer_id = 17;
        w.vm.checkoutProps.form.address_id = 99;
        w.vm.checkoutProps.form.delivery_distance_km = 3.2;

        w.vm.retirerClientFidelite();

        expect(w.vm.checkoutProps.form.customer_id).toBeNull();
        expect(w.vm.checkoutProps.form.address_id).toBeNull();
        expect(w.vm.checkoutProps.form.delivery_distance_km).toBeNull();
    });

    it('libellé accessible en fr, en, ar', () => {
        for (const code of ['fr', 'en', 'ar']) {
            const l = JSON.parse(fs.readFileSync(path.resolve(__dirname, `../../resources/js/languages/${code}.json`), 'utf8'));
            expect(l.pos.loyalty_detach_aria, `${code}.pos.loyalty_detach_aria`).toBeTruthy();
        }
    });
});
