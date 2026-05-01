import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { createStore } from 'vuex';
import {
    assertExplicitKioskOrderType,
    buildKioskOrderPayload,
    kioskCart,
    KIOSK_ORDER_TYPES,
    normalizeKioskOrderType,
} from '../../resources/js/store/modules/kioskCart.js';
import KioskIdleScreenComponent from '../../resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue';
import KioskCategoriesComponent from '../../resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue';

vi.mock('axios', () => ({
    default: { post: vi.fn() },
}));

function cloneCartModule() {
    return {
        ...kioskCart,
        state: JSON.parse(JSON.stringify(kioskCart.state)),
    };
}

function makeStoreWithCart(overrides = {}) {
    return createStore({
        modules: {
            kioskCart: {
                ...cloneCartModule(),
                ...(overrides.kioskCart || {}),
            },
            frontendSetting: {
                namespaced: true,
                actions: { lists: vi.fn().mockResolvedValue({ data: { data: {} } }) },
            },
            kioskSettings: {
                namespaced: true,
                actions: { setLocale: vi.fn() },
            },
        },
    });
}

function makeCategoriesStore(hasExplicitOrderType = true) {
    return createStore({
        modules: {
            kioskMenu: {
                namespaced: true,
                state: () => ({ kioskSandwichSubcolumn: null }),
                getters: {
                    categories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
                    allItems: () => [],
                    selectedCategoryId: () => 1,
                    loading: () => false,
                    isStale: () => false,
                    fromCache: () => false,
                    sidebarCategories: () => [{ id: 1, name: 'Tacos', kioskRowKey: 't' }],
                    kioskCatalogItems: () => [{ id: 10, name: 'Tacos', item_category_id: 1, convert_price: 9 }],
                },
                actions: {
                    fetchMenu: vi.fn().mockResolvedValue(),
                    selectKioskCategory: vi.fn(),
                },
            },
            kioskCart: {
                namespaced: true,
                state: () => ({ branchId: 1, kioskToken: 'token' }),
                getters: {
                    count: () => 1,
                    total: () => 9,
                    isEmpty: () => false,
                    hasExplicitOrderType: () => hasExplicitOrderType,
                },
                actions: { addItem: vi.fn(), reset: vi.fn() },
            },
            frontendItem: {
                namespaced: true,
                actions: { details: vi.fn().mockResolvedValue({ data: { data: { id: 10, name: 'Tacos' } } }) },
            },
        },
    });
}

describe('K-02 kiosk explicit order type', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('starts with no implicit order type and rejects submitOrder until the customer chooses one', async () => {
        const store = makeStoreWithCart();

        expect(store.getters['kioskCart/orderType']).toBeNull();
        expect(store.getters['kioskCart/hasExplicitOrderType']).toBe(false);
        expect(() => assertExplicitKioskOrderType(null)).toThrow('KIOSK_ORDER_TYPE_REQUIRED');

        await expect(store.dispatch('kioskCart/submitOrder', { paymentMethod: 'cash' }))
            .rejects
            .toMatchObject({ code: 'KIOSK_ORDER_TYPE_REQUIRED' });
    });

    it('accepts only KIOSK or TAKEAWAY and builds the payload with the selected order type', async () => {
        const store = makeStoreWithCart();

        await store.dispatch('kioskCart/setOrderType', KIOSK_ORDER_TYPES.TAKEAWAY);

        expect(store.getters['kioskCart/orderType']).toBe(KIOSK_ORDER_TYPES.TAKEAWAY);
        expect(store.getters['kioskCart/hasExplicitOrderType']).toBe(true);

        const payload = buildKioskOrderPayload({
            ...store.state.kioskCart,
            items: [{ item_id: 1, quantity: 1, item_variations: [], item_extras: [] }],
        }, {
            paymentMethod: 'cash',
            requireExplicitOrderType: true,
        });

        expect(payload.order_type).toBe(KIOSK_ORDER_TYPES.TAKEAWAY);
        expect(normalizeKioskOrderType(999)).toBeNull();
    });

    it('idle screen records the chosen type before entering categories', async () => {
        const store = makeStoreWithCart();
        const push = vi.fn();
        const wrapper = mount(KioskIdleScreenComponent, {
            global: {
                plugins: [store],
                mocks: {
                    $router: { push },
                    $t: (key) => key,
                },
                stubs: { KsA11ySettings: true },
            },
        });

        await wrapper.find('[data-testid="kiosk-order-type-takeaway"]').trigger('click');

        expect(store.getters['kioskCart/orderType']).toBe(KIOSK_ORDER_TYPES.TAKEAWAY);
        expect(push).toHaveBeenCalledWith({ name: 'kiosk.categories' });
    });

    it('categories redirects back to idle when the type is still absent', () => {
        const replace = vi.fn();
        mount(KioskCategoriesComponent, {
            global: {
                plugins: [makeCategoriesStore(false)],
                mocks: {
                    $router: { push: vi.fn(), replace },
                    $route: { query: {}, params: {} },
                    $t: (key) => key,
                },
                stubs: {
                    KioskWizardComponent: true,
                    KioskPromoCarouselComponent: true,
                    KsFilterChip: true,
                    KsAllergenBadge: true,
                    KsBadge: true,
                    transition: false,
                },
            },
        });

        expect(replace).toHaveBeenCalledWith({ name: 'kiosk.idle' });
    });
});
