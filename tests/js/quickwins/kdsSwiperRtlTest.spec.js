import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const kdsSyncServiceMock = vi.hoisted(() => ({
    lastSyncAt: null,
    on: vi.fn(() => vi.fn()),
    start: vi.fn(),
    stop: vi.fn(),
}));

vi.mock('../../../resources/js/services/KdsSyncService', () => ({
    default: kdsSyncServiceMock,
}));
vi.mock('../../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => vi.fn()),
}));
vi.mock('../../../resources/js/services/appService', () => ({
    default: {
        openFilterSlide: vi.fn(),
        closeFilterSlide: vi.fn(),
    },
}));
vi.mock('../../../resources/js/services/alertService', () => ({
    default: {
        error: vi.fn(),
        successFlip: vi.fn(),
    },
}));

import KitchenDisplaySystemComponent from '../../../resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue';
import displayModeEnum from '../../../resources/js/enums/modules/displayModeEnum';

const TestKdsComponent = {
    ...KitchenDisplaySystemComponent,
    created() {},
    mounted() {},
    beforeUnmount() {},
};

const makeStore = (displayMode) => ({
    getters: new Proxy({
        'frontendLanguage/show': { display_mode: displayMode },
        'auth/authBranchId': 1,
        'auth/authInfo': { id: 1 },
    }, {
        get(target, property) {
            return property in target ? target[property] : {};
        },
    }),
    dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
    commit: vi.fn(),
});

const mountKds = (displayMode) => shallowMount(TestKdsComponent, {
    global: {
        stubs: {
            Swiper: {
                template: '<div class="swiper-stub" :dir="$attrs.dir"><slot /></div>',
                inheritAttrs: false,
            },
            SwiperSlide: true,
            LoadingComponent: true,
            ConnectionStatusBanner: true,
            // [Sprint 3C 2026-05-16] V2 grid stays mounted under v-if when
            // useV2Layout returns true (production default). This test asserts
            // legacy Swiper RTL — stub the V2 wrapper so it does not register
            // its own kbd / ticker side effects in the smoke render.
            KdsV2Grid: true,
        },
        mocks: {
            $store: makeStore(displayMode),
            $t: (key) => key,
            $route: { query: {}, params: {} },
            $router: { push: vi.fn(), replace: vi.fn() },
        },
    },
});

describe('KDS Swiper RTL quickwin', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // [Sprint 3C 2026-05-16] Pin legacy layout for the Swiper assertions
        // below — V2 grid is now production-default and does not render the
        // legacy Swiper at all. ?v2=0 is the documented emergency revert path
        // and the test exercises legacy behaviour explicitly.
        const url = new URL(window.location.href);
        url.searchParams.set('v2', '0');
        window.history.replaceState({}, '', url.toString());
    });

    it('uses ltr by default and passes it to Swiper', () => {
        const wrapper = mountKds(displayModeEnum.LTR);

        expect(wrapper.vm.direction).toBe('ltr');
        expect(wrapper.find('.swiper-stub').attributes('dir')).toBe('ltr');
    });

    it('uses rtl when the frontend language is RTL', () => {
        const wrapper = mountKds(displayModeEnum.RTL);

        expect(wrapper.vm.direction).toBe('rtl');
    });

    it('falls back to ltr when display_mode is missing', () => {
        const wrapper = mountKds(undefined);

        expect(wrapper.vm.direction).toBe('ltr');
    });
});
