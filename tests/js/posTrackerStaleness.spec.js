import { beforeEach, describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [UX-TRACKER-02 + POSPERF-09 + IMP-AGING 2026-07-22] Régression sur l'écran
// tracker caisse (surveillance des commandes entrantes web/borne) :
//  1. STALENESS — `realtimeConnected` ne reflète que l'état SOCKET (soketi up),
//     pas la LIVRAISON d'événements (queue worker mort ⇒ socket "connected",
//     0 event, board figé à 60s en croyant le temps réel sain). On verrouille :
//     socket connecté MAIS aucun event depuis > 35s (ou board vide) ⇒
//     `_pollInterval()` retombe sur la cadence rapide POLL_NO_WS_MS.
//     Horloge encapsulée dans `_now()` (surchargeable) — zéro Date.now() brut
//     dans les assertions.
//  2. FRESHNESS SUR POLL — la surbrillance `_markFresh` n'était déclenchée que
//     par Echo (morte worker down). `fetchOrders` diffe désormais les ids reçus
//     vs déjà vus : chaque commande INÉDITE est surlignée — sauf le seed
//     initial (1er fetch silencieux, pas de flash de tout le board au load).
//  3. AGING — voie « À encaisser » : ≥5 min ⇒ `tracker-card--aging` (orange),
//     ≥10 min ⇒ `tracker-card--urgent` (rouge pulsé), autres voies jamais.

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

const NOW = 1_800_000_000_000; // horloge fixe — déterminisme total

const POLL_WS_MS = 60000;
// Miroir de la constante du composant (MULTI-DEVICE 2026-08-07 : 8 s → 5 s,
// cadence de repli exigée entre terminaux quand le temps réel est indisponible).
const POLL_NO_WS_MS = 5000;

const makeStore = (dispatchImpl) => ({
    getters: new Proxy({ 'auth/authBranchId': 1 }, { get(t, p) { return p in t ? t[p] : undefined; } }),
    state: { auth: { authBranchId: 1 } },
    dispatch: dispatchImpl || vi.fn(() => Promise.resolve({ data: { data: [] } })),
    commit: vi.fn(),
});

// Harness aligné sur les specs tracker existantes : mounted/beforeUnmount
// neutralisés (pas de timers réels), méthodes surchargeables (Vue bind les
// méthodes ⇒ on garde la référence vi.fn pour les assertions).
const buildHarness = ({ methods = {}, dispatchImpl = null } = {}) => {
    const store = makeStore(dispatchImpl);
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
    return { wrapper, store };
};

const minutesAgoIso = (mins) => new Date(NOW - mins * 60000).toISOString();

describe('PosOrdersTracker — staleness events (UX-TRACKER-02 / POSPERF-09)', () => {
    it('socket "connected" mais dernier event vieux de 40s ⇒ cadence RAPIDE (le worker peut être mort)', () => {
        const { wrapper } = buildHarness();
        wrapper.vm.realtimeConnected = true;
        wrapper.vm.orders = [{ id: 1 }]; // board non vide — seul le staleness joue
        wrapper.vm.lastEventAt = NOW - 40000; // 40s > EVENT_STALE_MS (35s)
        expect(wrapper.vm._pollInterval()).toBe(POLL_NO_WS_MS);
    });

    it('socket connecté + event frais + board non vide ⇒ cadence lente 60s (temps réel sain)', () => {
        const { wrapper } = buildHarness();
        wrapper.vm.realtimeConnected = true;
        wrapper.vm.orders = [{ id: 1 }];
        wrapper.vm.lastEventAt = NOW - 5000; // 5s < 35s
        expect(wrapper.vm._pollInterval()).toBe(POLL_WS_MS);
    });

    it('board VIDE ⇒ cadence rapide même socket connecté + event frais', () => {
        const { wrapper } = buildHarness();
        wrapper.vm.realtimeConnected = true;
        wrapper.vm.orders = [];
        wrapper.vm.lastEventAt = NOW - 1000;
        expect(wrapper.vm._pollInterval()).toBe(POLL_NO_WS_MS);
    });

    it('socket déconnecté ⇒ cadence rapide (comportement historique inchangé)', () => {
        const { wrapper } = buildHarness();
        wrapper.vm.realtimeConnected = false;
        wrapper.vm.orders = [{ id: 1 }];
        wrapper.vm.lastEventAt = NOW;
        expect(wrapper.vm._pollInterval()).toBe(POLL_NO_WS_MS);
    });

    it('_noteRealtimeEvent bump lastEventAt via _now() (seam horloge, pas Date.now brut)', () => {
        const { wrapper } = buildHarness();
        wrapper.vm.lastEventAt = 0;
        wrapper.vm._noteRealtimeEvent();
        expect(wrapper.vm.lastEventAt).toBe(NOW);
    });

    it('watchdog _onAgeTick : cadence armée 60s mais staleness détectée ⇒ re-arm + fetch immédiat', () => {
        const fetchSpy = vi.fn(() => Promise.resolve());
        const { wrapper } = buildHarness({ methods: { fetchOrders: fetchSpy } });
        wrapper.vm.realtimeConnected = true;
        wrapper.vm.orders = [{ id: 1 }];
        wrapper.vm.lastEventAt = NOW - 40000;   // stale ⇒ _pollInterval() = 5000
        wrapper.vm._pollTimer = 123;            // un timer "tourne"
        wrapper.vm._pollTimerMs = POLL_WS_MS;   // ... armé à 60s avant la mort du worker
        wrapper.vm._onAgeTick();
        expect(wrapper.vm._pollTimerMs).toBe(POLL_NO_WS_MS); // re-armé rapide
        expect(fetchSpy).toHaveBeenCalled();                 // gap de fraîcheur fermé tout de suite
    });
});

describe('PosOrdersTracker — freshness sur poll (UX-TRACKER-02b)', () => {
    let payload;
    const dispatchImpl = vi.fn(() => Promise.resolve({ data: { data: payload } }));

    beforeEach(() => {
        dispatchImpl.mockClear();
    });

    it('seed initial SILENCIEUX puis _markFresh uniquement pour les ids inédits (sans doublon)', async () => {
        const markSpy = vi.fn();
        const { wrapper } = buildHarness({ methods: { _markFresh: markSpy }, dispatchImpl });

        // 1er fetch = seed : 2 commandes, AUCUNE surbrillance
        payload = [{ id: 11 }, { id: 12 }];
        await wrapper.vm.fetchOrders();
        expect(markSpy).not.toHaveBeenCalled();

        // 2e fetch : id 13 inédit ⇒ surligné ; 11/12 déjà vus ⇒ silencieux
        payload = [{ id: 11 }, { id: 12 }, { id: 13 }];
        await wrapper.vm.fetchOrders();
        expect(markSpy).toHaveBeenCalledTimes(1);
        expect(markSpy).toHaveBeenCalledWith(13);

        // 3e fetch identique : 13 déjà vu ⇒ pas de re-flash
        await wrapper.vm.fetchOrders();
        expect(markSpy).toHaveBeenCalledTimes(1);
    });

    it('une commande disparue puis revenue dans le payload ne re-flashe pas (id resté "vu")', async () => {
        const markSpy = vi.fn();
        const { wrapper } = buildHarness({ methods: { _markFresh: markSpy }, dispatchImpl });
        payload = [{ id: 21 }];
        await wrapper.vm.fetchOrders(); // seed
        payload = [];
        await wrapper.vm.fetchOrders();
        payload = [{ id: 21 }];
        await wrapper.vm.fetchOrders();
        expect(markSpy).not.toHaveBeenCalled();
    });
});

describe('PosOrdersTracker — aging voie À encaisser (IMP-AGING)', () => {
    const setClock = (wrapper) => { wrapper.vm.ageTick = NOW; };

    it('7 min ⇒ tracker-card--aging ; 12 min ⇒ tracker-card--urgent ; 2 min ⇒ rien', () => {
        const { wrapper } = buildHarness();
        setClock(wrapper);
        expect(wrapper.vm.trackerAgeClass({ created_at: minutesAgoIso(7) }, 'accept')).toBe('tracker-card--aging');
        expect(wrapper.vm.trackerAgeClass({ created_at: minutesAgoIso(12) }, 'accept')).toBe('tracker-card--urgent');
        expect(wrapper.vm.trackerAgeClass({ created_at: minutesAgoIso(2) }, 'accept')).toBe('');
    });

    it('hors voie À encaisser : jamais de classe d\'âge (même à 12 min)', () => {
        const { wrapper } = buildHarness();
        setClock(wrapper);
        expect(wrapper.vm.trackerAgeClass({ created_at: minutesAgoIso(12) }, 'preparing')).toBe('');
        expect(wrapper.vm.trackerAgeClass({ created_at: minutesAgoIso(12) }, 'delivered')).toBe('');
    });

    it('agingLabel : "… 7 min" dès le seuil, vide en-dessous', () => {
        const { wrapper } = buildHarness();
        setClock(wrapper);
        expect(wrapper.vm.agingLabel({ created_at: minutesAgoIso(7) })).toContain('7 min');
        expect(wrapper.vm.agingLabel({ created_at: minutesAgoIso(2) })).toBe('');
    });

    it('TEMPLATE : une carte cash-pending qui attend 7 min porte la classe aging + badge d\'âge', async () => {
        const { wrapper } = buildHarness();
        setClock(wrapper);
        wrapper.vm.orders = [{
            id: 901,
            order_status: orderStatusEnum.ACCEPT,
            is_cash_pending: true,
            source_surface: 'kiosk',
            order_serial_no: 'K-901',
            total: 12.5,
            created_at: minutesAgoIso(7),
            order_items: [],
        }];
        await wrapper.vm.$nextTick();
        const card = wrapper.find('[data-testid="tracker-order-901"]');
        expect(card.exists()).toBe(true);
        expect(card.classes()).toContain('tracker-card--aging');
        expect(wrapper.find('[data-testid="tracker-age-901"]').exists()).toBe(true);
        // testids sentinelles préservés sur la carte (Encaisser CTA intact)
        expect(wrapper.find('[data-testid="tracker-encaisser-901"]').exists()).toBe(true);
    });
});

describe('PosOrdersTracker — POSPERF-07 requête bornée + lean', () => {
    it('fetchOrders demande paginate:1 + per_page:100 + lean:1 (fin du ->get(*) illimité, payload allégé)', async () => {
        const dispatchImpl = vi.fn(() => Promise.resolve({ data: { data: [] } }));
        const { wrapper } = buildHarness({ dispatchImpl });

        await wrapper.vm.fetchOrders();

        // paginate:1 fait honorer per_page côté backend (sinon ->get('*') = TOUTES
        // les commandes du jour) ; lean:1 réduit la charge de relations.
        expect(dispatchImpl).toHaveBeenCalledWith(
            'posOrder/lists',
            expect.objectContaining({ paginate: 1, per_page: 100, lean: 1, vuex: false }),
        );
    });
});
