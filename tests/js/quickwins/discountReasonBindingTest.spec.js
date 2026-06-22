import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const alertServiceMock = vi.hoisted(() => ({
    error: vi.fn(),
    success: vi.fn(),
    successFlip: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
}));

vi.mock('../../../resources/js/services/alertService', () => ({
    default: alertServiceMock,
}));
vi.mock('../../../resources/js/components/admin/components/LoadingComponent.vue', () => ({
    default: { name: 'LoadingComponent', template: '<div />' },
}));
vi.mock('../../../resources/js/components/admin/pos/ItemComponent.vue', () => ({
    default: { name: 'ItemComponent', template: '<div />' },
}));
vi.mock('../../../resources/js/components/admin/pos/PaymentComponent.vue', () => ({
    default: { name: 'PaymentComponent', template: '<div />' },
}));
vi.mock('../../../resources/js/components/admin/pos/ParkedOrdersComponent.vue', () => ({
    default: { name: 'ParkedOrdersComponent', template: '<div />' },
}));
vi.mock('../../../resources/js/components/admin/pos/CreateCustomerAddressComponent.vue', () => ({
    default: { name: 'CreateCustomerAddressComponent', template: '<div />' },
}));
vi.mock('../../../resources/js/components/admin/customers/address/CustomerAddressCreateComponent.vue', () => ({
    default: { name: 'CustomerAddressCreateComponent', template: '<div />' },
}));
vi.mock('../../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));

import PosComponent from '../../../resources/js/components/admin/pos/PosComponent.vue';

const posMethods = Object.fromEntries(
    Object.entries(PosComponent.methods).filter(([, value]) => typeof value === 'function')
);

const cartLine = {
    id: 1,
    name: 'Tacos',
    quantity: 1,
    total: 10,
    item_variations: [],
    item_extras: [],
    pos_line_addons: [],
};

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
    'posCart/lists': [cartLine],
    'posCart/subtotal': 10,
    'posCart/discount': 0,
    'posParked/count': 0,
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
        ...posMethods,
        closeSidebar: vi.fn(),
        itemCategories: vi.fn(),
        itemList: vi.fn(),
        loadKioskCashOrders: vi.fn(),
        _subscribeEcho: vi.fn(),
        _startKioskPolling: vi.fn(),
        _bindWsService: vi.fn(),
        _unsubscribeEcho: vi.fn(),
        _unbindWsService: vi.fn(),
        totalItems: vi.fn(() => 1),
        currencyFormat: vi.fn(() => '10 EUR'),
        formatKioskPrice: vi.fn((amount) => `${amount} EUR`),
        formatKioskTime: vi.fn(() => '10:00'),
        collectKioskCashOrder: vi.fn(),
        openCanvas: vi.fn(),
        closeCanvas: vi.fn(),
    },
};

const mountPos = () => shallowMount(TestPosComponent, {
    global: {
        stubs: {
            transition: false,
            Swiper: true,
            SwiperSlide: true,
            ParkedOrdersComponent: true,
            SkeletonGrid: true,
            ItemComponent: true,
            RouterLink: true,
            'router-link': true,
            'vue-select': true,
        },
        mocks: {
            $store: storeMock,
            $t: (key) => key,
            $route: { query: {}, params: {} },
            $router: { push: vi.fn(), replace: vi.fn() },
        },
    },
});

describe('POS discount reason binding quickwin', () => {
    beforeEach(() => {
        alertServiceMock.error.mockReset();
    });

    it('renders an accessible discount reason input', () => {
        const wrapper = mountPos();

        // Stable selector owned by this quickwin: avoids depending on nearby cart inputs.
        const input = wrapper.find('#pos-discount-reason');

        expect(input.exists()).toBe(true);
        expect(input.attributes('type')).toBe('text');
        expect(input.attributes('maxlength')).toBe('255');
        expect(wrapper.find('label[for="pos-discount-reason"]').exists()).toBe(true);
    });

    it('binds the discount reason input through v-model', async () => {
        const wrapper = mountPos();

        await wrapper.find('#pos-discount-reason').setValue('Geste commercial');

        expect(wrapper.vm.discountReason).toBe('Geste commercial');
    });

    it('keeps the existing validation error when a positive discount has no reason', async () => {
        const wrapper = mountPos();

        await wrapper.setData({ discount: '10', discountReason: '' });
        wrapper.vm.applyDiscount();

        expect(alertServiceMock.error).toHaveBeenCalledWith('message.discount_reason_required');
    });
});
