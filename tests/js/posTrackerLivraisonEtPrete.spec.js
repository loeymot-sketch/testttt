import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [ALERTE LIVRAISON + PRÊTE DEPUIS LA CAISSE 2026-11-15] Suivi caisse (PosOrdersTrackerComponent) :
//  1. Une LIVRAISON passée par le site/l'application (source_surface='delivery', source WEB=5 / APP=10)
//     sonne et affiche un message, comme une commande web. Une livraison SAISIE À LA CAISSE
//     (source_surface='delivery' aussi, mais source POS=15) ne sonne pas : le caissier l'a tapée.
//  2. Bouton « Prête » sur une commande acceptée / en préparation : passe par PREPARING (la machine à
//     états refuse ACCEPT → PREPARED), via la route existante posOrder/changeStatus ; un refus serveur
//     est affiché (alertService.error), jamais avalé.

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({
    onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn() },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] },
}));

import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import orderStatusEnum from '../../resources/js/enums/modules/orderStatusEnum';
import alertService from '../../resources/js/services/alertService';

const NOW = 1_800_000_000_000;
const iso = (ts) => new Date(ts).toISOString();

const buildHarness = ({ methods = {}, dispatch } = {}) => {
    const store = {
        getters: new Proxy(
            { 'auth/authBranchId': 1, 'frontendSetting/lists': {} },
            { get(t, p) { return p in t ? t[p] : undefined; } }
        ),
        state: { auth: { authBranchId: 1 } },
        dispatch: dispatch || vi.fn(() => Promise.resolve({ data: { data: [] } })),
        commit: vi.fn(),
    };
    const Test = {
        ...PosOrdersTrackerComponent,
        mounted() {},
        beforeUnmount() {},
        methods: {
            ...PosOrdersTrackerComponent.methods,
            _now: () => NOW,
            ...methods,
        },
    };
    const wrapper = shallowMount(Test, {
        global: {
            stubs: { transition: false, 'transition-group': false, 'router-link': true },
            mocks: {
                $store: store,
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
    wrapper.vm.ageTick = NOW;
    return { wrapper, store };
};

const livraison = (over = {}) => ({
    id: 501,
    queue_number: 12,
    status: orderStatusEnum.PENDING,
    order_type: 5, // DELIVERY
    source_surface: 'delivery',
    payment_status: 10,
    created_at: iso(NOW - 60000),
    ...over,
});

beforeEach(() => {
    vi.clearAllMocks();
});

describe('_maybeNotifyIncomingOrder — livraison venue du client', () => {
    it('livraison du SITE (source=5) ⇒ message + sonnerie', () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep } });
        wrapper.vm._maybeNotifyIncomingOrder(livraison({ id: 601, source: 5 }));
        expect(alertService.info).toHaveBeenCalledWith('Nouvelle livraison en ligne N°12');
        expect(beep).toHaveBeenCalledTimes(1);
    });

    it("livraison de l'APPLICATION (source=10) ⇒ message + sonnerie", () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep } });
        wrapper.vm._maybeNotifyIncomingOrder(livraison({ id: 602, source: 10 }));
        expect(alertService.info).toHaveBeenCalledTimes(1);
        expect(beep).toHaveBeenCalledTimes(1);
    });

    it('livraison SAISIE À LA CAISSE (source=15, payée à la porte) ⇒ silence', () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep } });
        wrapper.vm._maybeNotifyIncomingOrder(livraison({ id: 603, source: 15, status: orderStatusEnum.ACCEPT }));
        expect(alertService.info).not.toHaveBeenCalled();
        expect(beep).not.toHaveBeenCalled();
    });

    it('livraison sans `source` connu ⇒ silence (test positif, jamais par défaut)', () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep } });
        wrapper.vm._maybeNotifyIncomingOrder(livraison({ id: 604 }));
        expect(alertService.info).not.toHaveBeenCalled();
        expect(beep).not.toHaveBeenCalled();
    });

    it('dédup par id : la même livraison revue au sondage suivant ne sonne pas deux fois', () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep } });
        wrapper.vm._maybeNotifyIncomingOrder(livraison({ id: 605, source: 5 }));
        wrapper.vm._maybeNotifyIncomingOrder(livraison({ id: 605, source: 5 }));
        expect(beep).toHaveBeenCalledTimes(1);
    });

    it("le poll-diff fait sonner une NOUVELLE livraison du site (chemin réel fetchOrders)", async () => {
        const beep = vi.fn();
        let lot = [];
        const dispatch = vi.fn((action) => {
            if (action === 'posOrder/serviceDay') return Promise.resolve({ data: { data: lot, meta: {} } });
            return Promise.resolve({ data: { data: [] } });
        });
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep, _refreshOlderPendingCount: vi.fn() }, dispatch });
        await wrapper.vm.fetchOrders(); // premier chargement : semis silencieux
        lot = [livraison({ id: 606, source: 5 })];
        await wrapper.vm.fetchOrders();
        expect(alertService.info).toHaveBeenCalledWith('Nouvelle livraison en ligne N°12');
        expect(beep).toHaveBeenCalledTimes(1);
    });
});

describe('bouton « Prête » — commande acceptée ou en préparation', () => {
    const enCuisine = (over = {}) => ({
        id: 701,
        queue_number: 33,
        status: orderStatusEnum.ACCEPT,
        source_surface: 'web',
        payment_status: 5, // PAID
        created_at: iso(NOW - 3 * 60000),
        order_items: [],
        ...over,
    });

    it('visible sur une carte ACCEPT et PREPARING, absent sur PENDING et PREPARED', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [
            enCuisine({ id: 1, status: orderStatusEnum.ACCEPT }),
            enCuisine({ id: 2, status: orderStatusEnum.PREPARING }),
            enCuisine({ id: 3, status: orderStatusEnum.PREPARED }),
            enCuisine({ id: 4, status: orderStatusEnum.PENDING, payment_status: 10 }),
        ];
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="tracker-ready-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tracker-ready-2"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tracker-ready-3"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="tracker-ready-4"]').exists()).toBe(false);
    });

    it('ACCEPT ⇒ deux étapes sur la route existante : PREPARING puis PREPARED', async () => {
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: [] } }));
        const { wrapper } = buildHarness({ dispatch, methods: { fetchOrders: vi.fn(() => Promise.resolve()) } });
        await wrapper.vm.marquerPrete(enCuisine({ status: orderStatusEnum.ACCEPT }));
        const etapes = dispatch.mock.calls.filter(([a]) => a === 'posOrder/changeStatus').map(([, p]) => p);
        expect(etapes).toEqual([
            { id: 701, status: orderStatusEnum.PREPARING },
            { id: 701, status: orderStatusEnum.PREPARED },
        ]);
        expect(alertService.success).toHaveBeenCalledWith('Commande N°33 prête');
        expect(alertService.error).not.toHaveBeenCalled();
    });

    it('PREPARING ⇒ une seule étape : PREPARED', async () => {
        const dispatch = vi.fn(() => Promise.resolve({ data: { data: [] } }));
        const { wrapper } = buildHarness({ dispatch, methods: { fetchOrders: vi.fn(() => Promise.resolve()) } });
        await wrapper.vm.marquerPrete(enCuisine({ status: orderStatusEnum.PREPARING }));
        const etapes = dispatch.mock.calls.filter(([a]) => a === 'posOrder/changeStatus').map(([, p]) => p);
        expect(etapes).toEqual([{ id: 701, status: orderStatusEnum.PREPARED }]);
    });

    it('refus serveur ⇒ message VISIBLE du serveur, pas de 2e étape, bouton réactivé, tableau relu', async () => {
        const fetchOrders = vi.fn(() => Promise.resolve());
        const dispatch = vi.fn((action) => {
            if (action === 'posOrder/changeStatus') {
                return Promise.reject({ response: { status: 422, data: { message: 'Transition de statut invalide' } } });
            }
            return Promise.resolve({ data: { data: [] } });
        });
        const { wrapper } = buildHarness({ dispatch, methods: { fetchOrders } });
        await wrapper.vm.marquerPrete(enCuisine({ status: orderStatusEnum.ACCEPT }));
        expect(dispatch.mock.calls.filter(([a]) => a === 'posOrder/changeStatus')).toHaveLength(1);
        expect(alertService.error).toHaveBeenCalledWith('Transition de statut invalide');
        expect(alertService.success).not.toHaveBeenCalled();
        expect(wrapper.vm.preteEnCours[701]).toBe(false);
        expect(fetchOrders).toHaveBeenCalled();
    });

    it('erreur réseau sans message ⇒ message français explicite', async () => {
        const dispatch = vi.fn((action) => (action === 'posOrder/changeStatus'
            ? Promise.reject(new Error('Network Error'))
            : Promise.resolve({ data: { data: [] } })));
        const { wrapper } = buildHarness({ dispatch, methods: { fetchOrders: vi.fn(() => Promise.resolve()) } });
        await wrapper.vm.marquerPrete(enCuisine({ status: orderStatusEnum.PREPARING }));
        expect(alertService.error).toHaveBeenCalledWith('Impossible de passer la commande N°33 en « Prête »');
    });

    it('anti double-clic : un second appel pendant le premier ne part pas', async () => {
        let liberer;
        const dispatch = vi.fn((action) => (action === 'posOrder/changeStatus'
            ? new Promise((r) => { liberer = r; })
            : Promise.resolve({ data: { data: [] } })));
        const { wrapper } = buildHarness({ dispatch, methods: { fetchOrders: vi.fn(() => Promise.resolve()) } });
        const premier = wrapper.vm.marquerPrete(enCuisine({ status: orderStatusEnum.PREPARING }));
        await wrapper.vm.marquerPrete(enCuisine({ status: orderStatusEnum.PREPARING }));
        expect(dispatch.mock.calls.filter(([a]) => a === 'posOrder/changeStatus')).toHaveLength(1);
        liberer({ data: { data: {} } });
        await premier;
    });

    it('clic sur le bouton de la carte ⇒ marquerPrete(commande)', async () => {
        const marquerPrete = vi.fn();
        const { wrapper } = buildHarness({ methods: { marquerPrete } });
        wrapper.vm.orders = [enCuisine({ id: 9, status: orderStatusEnum.PREPARING })];
        await wrapper.vm.$nextTick();
        await wrapper.find('[data-testid="tracker-ready-9"]').trigger('click');
        expect(marquerPrete).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
    });
});
