import { beforeEach, describe, it, expect, vi } from 'vitest';
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
    beforeEach(() => {
        storeMock.dispatch.mockClear();
        TestPosComponent.methods.itemList.mockClear();
    });

    it('category selection clears text search before fetching the category items', async () => {
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
            props: {
                search: {
                    ...wrapper.vm.props.search,
                    name: 'ancien filtre',
                    item_category_id: '',
                },
            },
        });

        wrapper.vm.setCategory(123);

        expect(wrapper.vm.props.search.name).toBe('');
        expect(wrapper.vm.props.search.item_category_id).toBe(123);
        expect(TestPosComponent.methods.itemList).toHaveBeenCalled();
    });

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

    it('uses the authenticated cashier branch when default access is missing', async () => {
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

        expect(wrapper.vm.resolveDefaultAccessBranchId({ data: { data: { branch_id: null } } })).toBe(1);
    });

    it('applies POS branch scope to search and cart scope together', async () => {
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

        expect(wrapper.vm.applyPosBranchScope(7)).toBe(7);
        expect(wrapper.vm.checkoutProps.form.branch_id).toBe(7);
        expect(wrapper.vm.props.search.branch_id).toBe(7);
        expect(storeMock.dispatch).toHaveBeenCalledWith('posCart/setScope', {
            branchId: 7,
            userId: null,
        });
    });

    // [POS-V4-CASHIER-OPS 2026-05-02] Cancel-last-line dispatches the canonical
    // posCart/deleteCartItem mutation with the last index, so it stays compatible
    // with the existing Vuex contract used by per-line trash buttons.
    it('cancelLastCartLine removes only the last cart line via posCart/deleteCartItem', async () => {
        const localStore = {
            ...storeMock,
            getters: new Proxy({
                ...getterValues,
                'posCart/lists': [
                    { id: 'a', name: 'Tacos M', quantity: 1 },
                    { id: 'b', name: 'Coca', quantity: 2 },
                ],
            }, { get(t, p) { return p in t ? t[p] : []; } }),
            dispatch: vi.fn(() => Promise.resolve()),
        };

        const wrapper = shallowMount(TestPosComponent, {
            global: {
                stubs: { transition: false },
                mocks: {
                    $store: localStore,
                    $t: (key) => key,
                    $route: { query: {}, params: {} },
                    $router: { push: vi.fn(), replace: vi.fn() },
                },
            },
        });

        wrapper.vm.cancelLastCartLine();

        expect(localStore.dispatch).toHaveBeenCalledWith('posCart/deleteCartItem', {
            id: 1,
            status: 'decrement',
        });
    });

    // [POS-V4-CASHIER-OPS 2026-05-02] Discount-with-reason UI guard.
    // When a positive discount is set but reason is empty / too short, the
    // apply button must be disabled (mirrors backend
    // assertPosManualDiscountAllowed which requires reason length ≥ 3).
    it('disables the discount apply button when a positive discount has no reason', async () => {
        const wrapper = shallowMount(TestPosComponent, {
            global: {
                stubs: { transition: false },
                mocks: {
                    $store: storeMock,
                    $t: (key) => key,
                    $route: { query: {}, params: {} },
                    $router: { push: vi.fn(), replace: vi.fn() },
                },
            },
        });

        await wrapper.setData({
            discount: '10',
            discountReason: '',
        });
        expect(wrapper.vm.discountReasonRequired).toBe(true);
        expect(wrapper.vm.discountReasonInvalid).toBe(true);
        expect(wrapper.vm.isDiscountApplyable).toBe(false);

        await wrapper.setData({ discountReason: 'ab' });
        expect(wrapper.vm.isDiscountApplyable).toBe(false);

        await wrapper.setData({ discountReason: 'erreur de saisie' });
        expect(wrapper.vm.discountReasonInvalid).toBe(false);
        expect(wrapper.vm.isDiscountApplyable).toBe(true);

        // Empty discount stays applyable so the cashier can clear an existing
        // discount without re-typing a reason.
        await wrapper.setData({ discount: '0', discountReason: '' });
        expect(wrapper.vm.discountReasonRequired).toBe(false);
        expect(wrapper.vm.isDiscountApplyable).toBe(true);
    });
});

// [POS-CATEGORY-FIRST 2026-06-23 /goal] Category-first browse flow.
// Owner direction: the POS landing screen must show the CATEGORY GRID hub,
// not the full product dump. Picking a category drills into its products with
// a back bar; adding a line auto-returns to the grid.
describe('PosComponent — category-first browse (POS-CATEGORY-FIRST)', () => {
    function makeBrowseStore(overrides = {}) {
        const getters = {
            ...getterValues,
            'posCategory/lists': [
                { id: 0, name: 'Toutes les catégories', featured: true },
                { id: 3, name: 'Sandwichs', featured: true, thumb: '' },
                { id: 8, name: 'Tacos', featured: false, thumb: '' },
            ],
            'item/lists': [
                { id: 101, name: 'Cayenne', item_category_id: 3 },
                { id: 102, name: 'Tacos M', item_category_id: 8 },
            ],
            ...overrides,
        };
        return {
            getters: new Proxy(getters, {
                get(target, property) {
                    return property in target ? target[property] : [];
                },
            }),
            dispatch: vi.fn(() => Promise.resolve({ data: { data: {} } })),
            commit: vi.fn(),
        };
    }

    function mountBrowse(store) {
        return shallowMount(TestPosComponent, {
            global: {
                stubs: { transition: false },
                mocks: {
                    $store: store,
                    $t: (key) => key,
                    $route: { query: {}, params: {} },
                    $router: { push: vi.fn(), replace: vi.fn() },
                },
            },
        });
    }

    it('landing renders the category grid hub (real categories only), not the product dump', () => {
        const wrapper = mountBrowse(makeBrowseStore());

        expect(wrapper.vm.showCategoryGrid).toBe(true);
        expect(wrapper.find('[data-testid="pos-category-grid"]').exists()).toBe(true);
        // id=0 "Toutes" sentinel is excluded; 2 real category tiles render.
        const tiles = wrapper.findAll('[data-testid="pos-category-tile"]');
        expect(tiles.length).toBe(2);
        expect(wrapper.text()).toContain('Sandwichs');
        expect(wrapper.text()).toContain('Tacos');
        // The product region is NOT shown on the landing hub.
        expect(wrapper.find('.pos-menu-products-region').exists()).toBe(false);
    });

    it('picking a category drills into products and surfaces the back bar', async () => {
        const wrapper = mountBrowse(makeBrowseStore());

        wrapper.vm.setCategory(8);
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.showCategoryGrid).toBe(false);
        expect(wrapper.find('[data-testid="pos-category-grid"]').exists()).toBe(false);
        expect(wrapper.find('.pos-menu-products-region').exists()).toBe(true);
        // Back bar with the drilled-in category name.
        expect(wrapper.find('[data-testid="pos-browse-back-bar"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pos-browse-back"]').exists()).toBe(true);
        expect(wrapper.vm.activeBrowseCategory).toEqual({ id: 8, name: 'Tacos', featured: false, thumb: '' });
    });

    it('the back control returns to the category grid hub', async () => {
        const wrapper = mountBrowse(makeBrowseStore());

        wrapper.vm.setCategory(8);
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.showCategoryGrid).toBe(false);

        await wrapper.find('[data-testid="pos-browse-back"]').trigger('click');

        expect(wrapper.vm.props.search.item_category_id).toBe('');
        expect(wrapper.vm.showCategoryGrid).toBe(true);
    });

    it('taking a cart line auto-returns to the category grid ("après chaque prise de commande")', async () => {
        const wrapper = mountBrowse(makeBrowseStore());

        wrapper.vm.setCategory(8);
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.showCategoryGrid).toBe(false);

        // ItemComponent emits `item:added` after a NEW line is taken.
        wrapper.vm.onProductAddedReturnToCategories();
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.props.search.item_category_id).toBe('');
        expect(wrapper.vm.showCategoryGrid).toBe(true);
    });

    it('an active search shows products (search spans the catalogue), with a back-to-categories bar', async () => {
        const wrapper = mountBrowse(makeBrowseStore());

        await wrapper.setData({
            props: { search: { ...wrapper.vm.props.search, name: 'cola', item_category_id: '' } },
        });

        expect(wrapper.vm.showCategoryGrid).toBe(false);
        expect(wrapper.find('.pos-menu-products-region').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pos-browse-back-bar"]').exists()).toBe(true);
    });
});
