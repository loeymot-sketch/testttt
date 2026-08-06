import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

// [CAISSE-WEB-INTEL 2026-08-06] Intelligence caisse pour les commandes WEB :
//  1. Badge « ✅ CB » payé en ligne — PAID + source web, jamais cash-pending.
//  2. Commandes PROGRAMMÉES — badge « pour HH:MM » + PAS d'aging tant que
//     now < scheduled_at − lead (20 min).
//  3. Pill header « 🌐 N web à traiter » — web PENDING + web cash-pending,
//     jamais les terminales/refunded, jamais la borne.
//  4. Tri composite voie « À encaisser » — web PENDING d'abord, puis âge.
//  5. Alerte sonore/toast — web/borne uniquement, dédupliquée, opt-out via
//     pos_new_order_sound_enabled.
//  6. Bandeau instruction client — texte de la 1re ligne qui en porte une.

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

const makeStore = (settingLists = {}) => ({
    getters: new Proxy(
        { 'auth/authBranchId': 1, 'frontendSetting/lists': settingLists },
        { get(t, p) { return p in t ? t[p] : undefined; } }
    ),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
    commit: vi.fn(),
});

const buildHarness = ({ methods = {}, settingLists = {} } = {}) => {
    const store = makeStore(settingLists);
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

// Commande web PENDING type (payload SimpleOrderResource réel : created_at ISO).
const webPending = (over = {}) => ({
    id: 101,
    status: orderStatusEnum.PENDING,
    source_surface: 'web',
    payment_status: 10, // UNPAID
    created_at: iso(NOW - 2 * 60000),
    ...over,
});

describe('isPaidOnline — badge « payé en ligne »', () => {
    it('PAID + source web ⇒ true ; jamais pour une cash-pending ou une commande caisse', () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        expect(vm.isPaidOnline({ payment_status: 5, source_surface: 'web' })).toBe(true);
        // cash-pending (PENDING_COUNTER + COUNTER_DEFERRED) ⇒ pas payé en ligne
        expect(vm.isPaidOnline({ payment_status: 15, pos_payment_method: 6, source_surface: 'web' })).toBe(false);
        // commande tapée à la caisse, même payée ⇒ pas de badge (aucun doute à lever)
        expect(vm.isPaidOnline({ payment_status: 5, source_surface: 'pos' })).toBe(false);
        expect(vm.isPaidOnline(null)).toBe(false);
    });
});

describe('commandes programmées — badge + exclusion aging', () => {
    it('scheduled_at futur au-delà du lead ⇒ aucun aging, badge « pour HH:MM »', () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        const sched = webPending({
            created_at: iso(NOW - 30 * 60000), // créée il y a 30 min (aging normal = urgent)
            scheduled_at: iso(NOW + 60 * 60000), // pour dans 1h
            scheduled_hm: '19:30',
        });
        expect(vm.trackerAgeClass(sched, 'accept')).toBe('');
        expect(vm.scheduledLabel(sched)).toContain('19:30');
        expect(vm.scheduledLabel(sched)).toMatch(/^pour /);
    });

    it('échéance − lead atteinte ⇒ l\'aging reprend ses droits', () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        const due = webPending({
            created_at: iso(NOW - 30 * 60000),
            scheduled_at: iso(NOW + 10 * 60000), // dans 10 min < lead 20 min ⇒ due
        });
        expect(vm.trackerAgeClass(due, 'accept')).toBe('tracker-card--urgent');
    });

    it('sans scheduled_at ⇒ comportement aging inchangé', () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        expect(vm.trackerAgeClass(webPending({ created_at: iso(NOW - 7 * 60000) }), 'accept')).toBe('tracker-card--aging');
        expect(vm.scheduledLabel(webPending())).toBe('');
    });
});

describe('webActionableCount — pill « 🌐 N web à traiter »', () => {
    it('compte web PENDING + web cash-pending ; exclut terminales, refunded et borne', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [
            webPending({ id: 1 }),
            // web acceptée, à encaisser
            { id: 2, status: orderStatusEnum.ACCEPT, source_surface: 'web', payment_status: 15, pos_payment_method: 6, created_at: iso(NOW) },
            // borne cash-pending ⇒ hors pill (déjà couverte par la voie 🔔)
            { id: 3, status: orderStatusEnum.ACCEPT, source_surface: 'kiosk', payment_status: 15, pos_payment_method: 6, created_at: iso(NOW) },
            // web annulée ⇒ exclue
            { id: 4, status: orderStatusEnum.CANCELED, source_surface: 'web', payment_status: 15, pos_payment_method: 6, created_at: iso(NOW) },
            // web remboursée ⇒ exclue
            { id: 5, status: orderStatusEnum.PREPARING, source_surface: 'web', payment_status: 20, created_at: iso(NOW) },
            // web payée en ligne en préparation ⇒ aucune action caissier
            { id: 6, status: orderStatusEnum.PREPARING, source_surface: 'web', payment_status: 5, created_at: iso(NOW) },
        ];
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.webActionableCount).toBe(2);
    });
});

describe('tri composite voie « À encaisser »', () => {
    it('une web PENDING plus RÉCENTE passe devant une cash-pending plus ancienne', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [
            { id: 11, status: orderStatusEnum.ACCEPT, source_surface: 'kiosk', payment_status: 15, pos_payment_method: 6, created_at: iso(NOW - 20 * 60000) },
            webPending({ id: 12, created_at: iso(NOW - 1 * 60000) }),
        ];
        await wrapper.vm.$nextTick();
        const lane = wrapper.vm.ordersByStatus.accept.map((o) => o.id);
        expect(lane).toEqual([12, 11]);
    });

    it('à priorité égale, plus ancien d\'abord (ordre historique conservé)', async () => {
        const { wrapper } = buildHarness();
        wrapper.vm.orders = [
            webPending({ id: 21, created_at: iso(NOW - 1 * 60000) }),
            webPending({ id: 22, created_at: iso(NOW - 9 * 60000) }),
        ];
        await wrapper.vm.$nextTick();
        expect(wrapper.vm.ordersByStatus.accept.map((o) => o.id)).toEqual([22, 21]);
    });
});

describe('_maybeNotifyIncomingOrder — alerte sonore/toast', () => {
    it('web ⇒ toast + beep ; caisse ⇒ silence ; dédup par id', () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({ methods: { _playNewOrderBeep: beep } });
        const vm = wrapper.vm;
        vm._maybeNotifyIncomingOrder(webPending({ id: 31, queue_number: 7 }));
        expect(beep).toHaveBeenCalledTimes(1);
        expect(alertService.info).toHaveBeenCalledWith(expect.stringContaining('N°7'));
        // dédup — même commande revue au poll suivant ⇒ pas de 2e signal
        vm._maybeNotifyIncomingOrder(webPending({ id: 31, queue_number: 7 }));
        expect(beep).toHaveBeenCalledTimes(1);
        // commande tapée à la caisse ⇒ jamais de signal (le caissier l'a créée)
        vm._maybeNotifyIncomingOrder({ id: 32, status: orderStatusEnum.ACCEPT, source_surface: 'pos', created_at: iso(NOW) });
        expect(beep).toHaveBeenCalledTimes(1);
    });

    it('réglage pos_new_order_sound_enabled=0 ⇒ toast mais pas de beep', () => {
        const beep = vi.fn();
        const { wrapper } = buildHarness({
            methods: { _playNewOrderBeep: beep },
            settingLists: { pos_new_order_sound_enabled: '0' },
        });
        wrapper.vm._maybeNotifyIncomingOrder(webPending({ id: 41 }));
        expect(beep).not.toHaveBeenCalled();
    });
});

describe('instructionPreview — bandeau allergie/note', () => {
    it('rend le texte de la première ligne portant une instruction, sinon rien', () => {
        const { wrapper } = buildHarness();
        const vm = wrapper.vm;
        const o = webPending({
            has_instruction: true,
            order_items: [
                { item_name: 'Cayenne', quantity: 1, instruction: null },
                { item_name: 'Tacos', quantity: 1, instruction: 'Allergie arachide' },
            ],
        });
        expect(vm.instructionPreview(o)).toBe('Allergie arachide');
        expect(vm.instructionPreview(webPending({ has_instruction: false, order_items: [] }))).toBe('');
    });
});
