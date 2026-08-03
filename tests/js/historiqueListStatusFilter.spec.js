import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [P2-1 HEAL 2026-07-24] Audit reports/goal-global-validation-2026-07-24/
// ACCES-caisse-gestion-findings.md : l'Historique unifié (/admin/historique)
// ne pouvait PAS filtrer les commandes Annulées(16)/Refusées(19)/Retournées(22)
// — le <vue-select> Statut n'offrait qu'Acceptée/En préparation/Prête/Livrée,
// alors que 98+150+26=274 commandes concernées existent en base et que
// OnlineOrderListComponent PROPOSE déjà ces 3 options. Le filtre backend est
// pass-through (OrderService::list:196-197 → where('status',(int)$request),
// status ∈ orderFilter), donc il suffit d'EXPOSER les 3 options.
// Ce spec verrouille : (1) le dropdown Statut contient CANCELED/REJECTED/RETURNED
// sans perdre les options existantes ; (2) le filtre passe bien la valeur au
// backend via orderHistory/lists.

vi.mock('../../resources/js/services/appService', () => ({
    default: {
        permissionChecker: vi.fn(() => false),
        orderStatusClass: vi.fn(() => ''),
        handleSlide: vi.fn(),
    },
}));

import HistoriqueListComponent from '../../resources/js/components/admin/orderHistory/HistoriqueListComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';

// Stub vue-select : capture les props `id` + `options` dans des data-attrs DOM
// (déterministe, indépendant de la lib de select réelle stubbée par shallowMount).
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
    // On neutralise mounted() pour éviter le this.list() de montage (dispatch réseau).
    const Test = { ...HistoriqueListComponent, mounted() {} };
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

describe('HistoriqueList — filtre Statut expose Annulée/Refusée/Retournée (P2-1)', () => {
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

    it('non-régression : les options existantes Acceptée/Préparation/Prête/Livrée sont conservées', () => {
        const { wrapper } = buildHarness();
        const ids = statusOptionIds(wrapper);
        expect(ids).toEqual(expect.arrayContaining([
            orderStatusEnum.ACCEPT,
            orderStatusEnum.PREPARING,
            orderStatusEnum.PREPARED,
            orderStatusEnum.DELIVERED,
        ]));
    });

    it('le filtre passe le statut sélectionné au backend (orderHistory/lists)', () => {
        const { wrapper, store } = buildHarness();
        wrapper.vm.props.search.status = orderStatusEnum.CANCELED;
        wrapper.vm.search();
        expect(store.dispatch).toHaveBeenCalledWith(
            'orderHistory/lists',
            expect.objectContaining({ status: orderStatusEnum.CANCELED }),
        );
    });
});
