import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ItemComponent.vue', () => ({
    default: { name: 'ItemComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({
    default: { name: 'PaymentComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({
    default: { name: 'CreateCustomerAddressComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({
    default: { name: 'CustomerAddressCreateComponent', template: '<div />' },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

import PosComponent from '../../resources/js/components/admin/pos/PosComponent.vue';

const getterValues = {
    'frontendSetting/lists': {
        site_digit_after_decimal_point: 2,
        site_default_currency_symbol: 'EUR',
        site_currency_position: 'left',
        pos_dine_in_enabled: 0,
    },
    'frontendLanguage/show': { display_mode: 0 },
    'posCategory/lists': [],
    'item/lists': [],
    'user/lists': [],
    'posCart/lists': [],
    'posCart/subtotal': 0,
    'posCart/discount': 0,
    'diningTable/lists': [],
    'user/addressLists': [],
    'auth/authBranchId': 1,
    'auth/authInfo': {},
};

const storeMock = {
    getters: new Proxy(getterValues, {
        get(target, property) {
            return property in target ? target[property] : [];
        },
    }),
    dispatch: vi.fn(() => Promise.resolve({ data: { data: { branch_id: 1 } } })),
    commit: vi.fn(),
};

const TestPosComponent = {
    ...PosComponent,
    mounted() {},
    beforeUnmount() {},
    methods: {
        ...PosComponent.methods,
        closeSidebar: vi.fn(),
        itemCategories: vi.fn(),
        itemList: vi.fn(),
        loadKioskCashOrders: vi.fn(),
        _subscribeEcho: vi.fn(),
        _startKioskPolling: vi.fn(),
        _bindWsService: vi.fn(),
        _unsubscribeEcho: vi.fn(),
        _unbindWsService: vi.fn(),
        totalItems: vi.fn(() => 0),
        currencyFormat: vi.fn(() => '0 EUR'),
        formatKioskPrice: vi.fn((amount) => `${amount} EUR`),
        formatKioskTime: vi.fn(() => '10:00'),
        collectKioskCashOrder: vi.fn(),
        openCanvas: vi.fn(),
        closeCanvas: vi.fn(),
    },
};

describe('PosComponent', () => {
    it('drawer_expandable_details', async () => {
        const wrapper = shallowMount(TestPosComponent, {
            global: {
                stubs: {
                    transition: false,
                },
                mocks: {
                    $store: storeMock,
                    $t: (key) => key,
                    $route: { query: {}, params: {} },
                    $router: { push: vi.fn(), replace: vi.fn() },
                },
            },
        });

        await wrapper.setData({
            showKioskCashPanel: true,
            kioskCashOrders: [
                {
                    id: 101,
                    queue_number: 'A001',
                    order_amount: 24.5,
                    created_at: '2026-04-18T10:00:00Z',
                    order_items: [
                        {
                            id: 5001,
                            item_name: 'Tacos XL',
                            quantity: 2,
                            item_variations: [{ variation_name: 'Taille', name: 'XL' }],
                            item_extras: [{ name: 'Cheddar' }],
                            instruction: 'Bien cuit',
                            allergens_snapshot: ['gluten'],
                        },
                    ],
                },
            ],
        });

        expect(wrapper.find('[data-testid="kiosk-cash-details-101"]').exists()).toBe(false);

        await wrapper.find('[data-testid="kiosk-cash-expand-101"]').trigger('click');

        const details = wrapper.find('[data-testid="kiosk-cash-details-101"]');
        expect(details.exists()).toBe(true);
        expect(details.text()).toContain('Variations:');
        expect(details.text()).toContain('Taille: XL');
        expect(details.text()).toContain('Extras:');
        expect(details.text()).toContain('Cheddar');
        expect(details.text()).toContain('Instructions:');
        expect(details.text()).toContain('Bien cuit');
        expect(details.text()).toContain('Allergenes:');
        expect(details.text()).toContain('gluten');
    });
});
