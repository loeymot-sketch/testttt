import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [P2-1 HEAL 2026-07-24] Jumeau de historiqueListStatusFilter.spec.js. La liste
// pos-orders (/admin/pos-orders) n'offrait que Acceptée/En préparation/Livrée au
// filtre Statut ; on expose Annulée(16)/Refusée(19)/Retournée(22), miroir de
// OnlineOrderListComponent. Filtre backend pass-through identique
// (PosOrderController::index → OrderService::list:196-197). On verrouille le
// dropdown + le câblage posOrder/lists.

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => false),
        statusClass: vi.fn(() => ''),
        orderStatusClass: vi.fn(() => ''),
        textShortener: vi.fn((t) => t),
        handleSlide: vi.fn(),
    },
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { success: vi.fn(), error: vi.fn(), successFlip: vi.fn() },
}));

import PosOrderListComponent from '../../resources/js/components/admin/posOrders/PosOrderListComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

const VueSelectStub = {
    name: 'VueSelectStub',
    props: ['options', 'modelValue', 'id'],
    template: `<div class="vs-stub" :data-vs-id="id" :data-vs-options="JSON.stringify(options || [])"></div>`,
};

const makeStore = () => ({
    getters: new Proxy({}, {
        get(_t, key) {
            if (typeof key === 'string' && key.endsWith('/lists')) return [];
            if (key === 'frontendLanguage/show') return { display_mode: 0 };
            return {};
        },
    }),
    dispatch: vi.fn(() => Promise.resolve({ data: {} })),
    commit: vi.fn(),
});

const buildHarness = () => {
    const store = makeStore();
    // mounted() neutralisé : évite this.list() + le dispatch user/lists de montage.
    const Test = { ...PosOrderListComponent, mounted() {} };
    const wrapper = shallowMount(Test, {
        global: {
            stubs: { 'vue-select': VueSelectStub, transition: false },
            mocks: {
                $store: store,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    return { wrapper, store };
};

const statusOptionIds = (wrapper) => {
    const el = wrapper.get('[data-vs-id="searchStatus"]');
    return JSON.parse(el.attributes('data-vs-options')).map((o) => o.id);
};

describe('PosOrderList — filtre Statut expose Annulée/Refusée/Retournée (P2-1)', () => {
    beforeEach(() => vi.clearAllMocks());

    it('le dropdown Statut contient CANCELED(16), REJECTED(19), RETURNED(22)', () => {
        const { wrapper } = buildHarness();
        const ids = statusOptionIds(wrapper);
        expect(ids).toEqual(expect.arrayContaining([
            orderStatusEnum.CANCELED,
            orderStatusEnum.REJECTED,
            orderStatusEnum.RETURNED,
        ]));
        expect(ids).toContain(16);
        expect(ids).toContain(19);
        expect(ids).toContain(22);
    });

    it('non-régression : les options existantes Acceptée/Préparation/Livrée sont conservées', () => {
        const { wrapper } = buildHarness();
        const ids = statusOptionIds(wrapper);
        expect(ids).toEqual(expect.arrayContaining([
            orderStatusEnum.ACCEPT,
            orderStatusEnum.PREPARING,
            orderStatusEnum.DELIVERED,
        ]));
    });

    it('le filtre passe le statut sélectionné au backend (posOrder/lists)', () => {
        const { wrapper, store } = buildHarness();
        wrapper.vm.props.search.status = orderStatusEnum.REJECTED;
        wrapper.vm.search();
        expect(store.dispatch).toHaveBeenCalledWith(
            'posOrder/lists',
            expect.objectContaining({ status: orderStatusEnum.REJECTED }),
        );
    });
});
