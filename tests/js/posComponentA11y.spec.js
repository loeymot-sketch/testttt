import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
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
import ItemComponent from '../../resources/js/components/admin/pos/ItemComponent.vue';

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

describe('POS operator a11y (T18)', () => {
    it('PosComponent renders skip-link to #pos-cart', () => {
        const wrapper = shallowMount(TestPosComponent, {
            global: {
                stubs: {
                    transition: false,
                    Swiper: true,
                    SwiperSlide: true,
                    ParkedOrdersComponent: true,
                    SkeletonGrid: true,
                    ItemComponent: true,
                },
                mocks: {
                    $store: storeMock,
                    $t: (key) => key,
                    $route: { query: {}, params: {} },
                    $router: { push: vi.fn(), replace: vi.fn() },
                },
            },
        });

        const link = wrapper.find('a[href="#pos-cart"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('a11y.skip_to_cart');
    });

    it('PosComponent cart panel has role=region and aria-label', () => {
        const wrapper = shallowMount(TestPosComponent, {
            global: {
                stubs: {
                    transition: false,
                    Swiper: true,
                    SwiperSlide: true,
                    ParkedOrdersComponent: true,
                    SkeletonGrid: true,
                    ItemComponent: true,
                },
                mocks: {
                    $store: storeMock,
                    $t: (key) => key,
                    $route: { query: {}, params: {} },
                    $router: { push: vi.fn(), replace: vi.fn() },
                },
            },
        });

        const cart = wrapper.find('#pos-cart');
        expect(cart.exists()).toBe(true);
        expect(cart.attributes('role')).toBe('region');
        expect(cart.attributes('aria-label')).toBe('a11y.cart_region');
    });

    it('ItemComponent tile is a native button with an aria-label', () => {
        const tileAvailable = {
            id: 42,
            name: 'Dispo',
            thumb: '',
            offer: [],
            currency_price: '5 EUR',
            is_available: true,
        };

        const wrapper = shallowMount(ItemComponent, {
            props: { items: [tileAvailable] },
            global: {
                stubs: { Swiper: true, SwiperSlide: true },
                mocks: {
                    $t: (key, params) => {
                        if (key === 'a11y.add_item' && params) {
                            return `add:${params.item}:${params.price}`;
                        }
                        return key;
                    },
                    $store: {
                        dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
                        getters: {
                            'frontendSetting/lists': {
                                site_digit_after_decimal_point: 2,
                                site_default_currency_symbol: 'EUR',
                                site_currency_position: 'left',
                            },
                        },
                    },
                },
            },
        });

        const tile = wrapper.find('.pos-item-tile');
        expect(tile.element.tagName).toBe('BUTTON');
        expect(tile.attributes('type')).toBe('button');
        expect(tile.attributes('aria-label')).toContain('Dispo');
        expect(tile.attributes('aria-label')).toContain('5 EUR');
    });
});
