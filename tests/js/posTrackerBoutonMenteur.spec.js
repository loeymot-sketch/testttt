import { describe, it, expect, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

/**
 * [BOUTON MENTEUR 2026-08-19] Le tableau de suivi promettait deux annulations impossibles.
 *
 *  1. Commande enfermée dans un Z CLOS → le serveur refuse (NF525, SealedOrderGuard). Le bouton
 *     « Annuler » restait affiché : clic → erreur, aucune issue proposée. La sortie légitime
 *     existait déjà (contrepartie comptable) mais n'était offerte nulle part depuis cet écran.
 *  2. Commande PAYÉE sous un compte Caissier → annuler REND l'argent, donc
 *     PosOrderController::changeStatus exige `pos-refund`, que le rôle POS Operator ne porte
 *     PAS (refus délibéré, vecteur de remboursement de masse). Clic → 403 muet.
 *
 * Ces specs épinglent que l'écran ne promet plus que ce que le serveur accepte. Aucune garde
 * n'est contournée : on rend l'affichage HONNÊTE, les permissions restent intactes.
 */

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })), get: vi.fn(() => Promise.resolve({ data: { data: [] } })) },
}));
vi.mock('../../resources/js/services/eventContract', () => ({ onEvents: vi.fn(() => ({ unsubscribe: vi.fn() })) }));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { info: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));
vi.mock('../../resources/js/components/common/ConnectionStatusBanner.vue', () => ({
    default: { name: 'ConnectionStatusBanner', template: '<div />' },
}));
vi.mock('../../resources/js/components/admin/pos/ReceiptComponent.vue', () => ({
    default: { name: 'ReceiptComponent', template: '<div />', props: ['order'] },
}));

// Le droit de rembourser est piloté par ce mock, comme en vrai (appService.permissionChecker).
const permissionChecker = vi.fn(() => false);
vi.mock('../../resources/js/services/appService', () => ({
    default: { modalShow: vi.fn(), modalHide: vi.fn(), permissionChecker: (...a) => permissionChecker(...a) },
}));

import PosOrdersTrackerComponent from '../../resources/js/components/admin/pos/PosOrdersTrackerComponent.vue';
import paymentStatusEnum from '../../resources/js/enums/modules/paymentStatusEnum';

const makeStore = () => ({
    getters: new Proxy({ 'auth/authBranchId': 1, 'frontendSetting/lists': {} },
        { get(t, p) { return p in t ? t[p] : undefined; } }),
    state: { auth: { authBranchId: 1 } },
    dispatch: vi.fn(() => Promise.resolve({ data: { data: [] } })),
    commit: vi.fn(),
});

const buildHarness = () => {
    const Test = { ...PosOrdersTrackerComponent, mounted() {}, beforeUnmount() {} };
    return shallowMount(Test, {
        global: {
            stubs: { transition: false, 'transition-group': false, 'router-link': true },
            mocks: {
                $store: makeStore(),
                $t: (key) => key,
                $route: { query: {}, params: {} },
                $router: { push: vi.fn(), replace: vi.fn() },
            },
        },
    });
};

describe('cancelBlockedReason — ne promettre que ce que le serveur accepte', () => {
    it('commande NON scellée et NON payée → annulation réellement possible', () => {
        permissionChecker.mockReturnValue(false);
        const vm = buildHarness().vm;
        expect(vm.cancelBlockedReason({ id: 1, is_sealed: false, payment_status: paymentStatusEnum.UNPAID })).toBe(null);
    });

    it('commande scellée dans un Z → « sealed », quel que soit le droit de rembourser', () => {
        permissionChecker.mockReturnValue(true);
        const vm = buildHarness().vm;
        expect(vm.cancelBlockedReason({ id: 2, is_sealed: true, payment_status: paymentStatusEnum.UNPAID })).toBe('sealed');
        expect(vm.cancelBlockedReason({ id: 3, is_sealed: true, payment_status: paymentStatusEnum.PAID })).toBe('sealed');
    });

    it('commande PAYÉE sans le droit `pos-refund` → « refund_right » (le 403 que le clic produisait)', () => {
        permissionChecker.mockReturnValue(false);
        const vm = buildHarness().vm;
        expect(vm.cancelBlockedReason({ id: 4, is_sealed: false, payment_status: paymentStatusEnum.PAID })).toBe('refund_right');
    });

    it('commande PAYÉE AVEC le droit `pos-refund` → annulation possible (responsable)', () => {
        permissionChecker.mockReturnValue(true);
        const vm = buildHarness().vm;
        expect(vm.cancelBlockedReason({ id: 5, is_sealed: false, payment_status: paymentStatusEnum.PAID })).toBe(null);
    });

    it('en attente d’encaissement (PENDING_COUNTER) → annulation possible : aucun argent n’a bougé', () => {
        permissionChecker.mockReturnValue(false);
        const vm = buildHarness().vm;
        expect(vm.cancelBlockedReason({ id: 6, is_sealed: false, payment_status: paymentStatusEnum.PENDING_COUNTER })).toBe(null);
    });

    it('`is_sealed` absent (endpoint qui ne le calcule pas) → comportement historique, jamais un blocage inventé', () => {
        permissionChecker.mockReturnValue(false);
        const vm = buildHarness().vm;
        expect(vm.cancelBlockedReason({ id: 7, payment_status: paymentStatusEnum.UNPAID })).toBe(null);
        expect(vm.cancelBlockedReason(null)).toBe(null);
    });

    it('le vérificateur de droit qui explose ne fait pas tomber l’écran (repli : pas de remboursement)', () => {
        permissionChecker.mockImplementation(() => { throw new Error('store absent'); });
        const vm = buildHarness().vm;
        expect(vm.canRefundSealed).toBe(false);
        expect(vm.cancelBlockedReason({ id: 8, is_sealed: false, payment_status: paymentStatusEnum.PAID })).toBe('refund_right');
    });
});

describe('panneau « en souffrance » — état défensif', () => {
    it('démarre fermé, à zéro, sans erreur', () => {
        permissionChecker.mockReturnValue(false);
        const vm = buildHarness().vm;
        expect(vm.staleOpen).toBe(false);
        expect(vm.staleOrders).toEqual([]);
        expect(vm.staleMeta).toEqual({ count: 0, shown: 0, truncated: false });
        expect(vm.staleError).toBe('');
    });

    it('une lecture en échec le DIT — un panneau vide se lirait « il n’y en a pas »', async () => {
        permissionChecker.mockReturnValue(false);
        const axios = (await import('axios')).default;
        axios.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Boom' } } });
        const vm = buildHarness().vm;
        await vm.fetchStaleOrders();
        expect(vm.staleError).toBe('Boom');
        expect(vm.staleOrders).toEqual([]);
    });

    it('une réponse sans meta ne fabrique pas un compteur fantôme', async () => {
        permissionChecker.mockReturnValue(false);
        const axios = (await import('axios')).default;
        axios.get.mockResolvedValueOnce({ data: { data: [{ id: 1 }] } });
        const vm = buildHarness().vm;
        await vm.fetchStaleOrders();
        expect(vm.staleOrders).toHaveLength(1);
        expect(vm.staleMeta).toEqual({ count: 0, shown: 0, truncated: false });
    });
});

describe('staleStatusLabel — jamais une clé brute à l’écran', () => {
    it('rend le MÊME vocabulaire que les voies du tableau, jamais « all.order.status.X »', () => {
        permissionChecker.mockReturnValue(false);
        const vm = buildHarness().vm;
        // $t est mocké en identité : on vérifie donc la CLÉ choisie, et qu’elle appartient
        // bien au jeu de libellés des voies (celui qui existe côté front).
        expect(vm.staleStatusLabel(8)).toBe('pos.tracker.col_prepared');
        expect(vm.staleStatusLabel(7)).toBe('pos.tracker.col_preparing');
        expect(vm.staleStatusLabel(4)).toBe('pos.tracker.col_accept');
        expect(vm.staleStatusLabel(10)).toBe('pos.tracker.col_on_the_way');
        expect(vm.staleStatusLabel(13)).toBe('pos.tracker.col_delivered');
        expect(vm.staleStatusLabel(1)).toBe('En attente');
        // Statut inconnu : rien, plutôt qu’un identifiant technique montré au caissier.
        expect(vm.staleStatusLabel(999)).toBe('');
        // Le défaut EXACT vu à l’écran le 2026-08-19 : la clé PHP affichée telle quelle.
        [1, 4, 7, 8, 10, 13, 999].forEach((s) => {
            expect(String(vm.staleStatusLabel(s))).not.toMatch(/^all\.order\.status\./);
        });
    });
});

describe('rendu réel du panneau « en souffrance » — ce que le caissier voit', () => {
    const ligne = (over = {}) => ({
        id: 900, order_serial_no: 'ORD-900-XX', queue_number: 'A0090',
        order_datetime: '20:56, 17-08-2026', status: 8, total: 2.5, payment_status: 10,
        is_sealed: false, ...over,
    });

    it('ligne ORDINAIRE → bouton Annuler, aucun cadenas', async () => {
        permissionChecker.mockReturnValue(false);
        const w = buildHarness();
        await w.setData({ staleOpen: true, staleOrders: [ligne()], staleMeta: { count: 1, shown: 1, truncated: false } });
        expect(w.find('[data-testid="tracker-stale-cancel-900"]').exists()).toBe(true);
        expect(w.find('.pos-tracker-stale-sealed').exists()).toBe(false);
    });

    it('ligne CLÔTURÉE dans un Z → cadenas « Clôturé », et PAS de bouton Annuler', async () => {
        permissionChecker.mockReturnValue(false);
        const w = buildHarness();
        await w.setData({ staleOpen: true, staleOrders: [ligne({ is_sealed: true })], staleMeta: { count: 1, shown: 1, truncated: false } });
        const cadenas = w.find('.pos-tracker-stale-sealed');
        expect(cadenas.exists()).toBe(true);
        expect(cadenas.text()).toContain('Clôturé');
        // Le bouton qui produisait une erreur certaine a disparu.
        expect(w.find('[data-testid="tracker-stale-cancel-900"]').exists()).toBe(false);
        // Sans le droit de rembourser, on ne propose PAS un second bouton mort.
        expect(w.find('[data-testid="tracker-stale-refund-900"]').exists()).toBe(false);
    });

    it('ligne CLÔTURÉE + droit de rembourser → la contrepartie est proposée', async () => {
        permissionChecker.mockReturnValue(true);
        const w = buildHarness();
        await w.setData({ staleOpen: true, staleOrders: [ligne({ is_sealed: true })], staleMeta: { count: 1, shown: 1, truncated: false } });
        expect(w.find('[data-testid="tracker-stale-refund-900"]').exists()).toBe(true);
        expect(w.find('[data-testid="tracker-stale-cancel-900"]').exists()).toBe(false);
    });

    it('la troncature est ÉCRITE à l’écran, jamais tue', async () => {
        permissionChecker.mockReturnValue(false);
        const w = buildHarness();
        await w.setData({ staleOpen: true, staleOrders: [ligne()], staleMeta: { count: 577, shown: 50, truncated: true } });
        expect(w.find('.pos-tracker-stale-truncated').text()).toContain('50');
        expect(w.find('.pos-tracker-stale-truncated').text()).toContain('577');
    });

    it('le statut est écrit en toutes lettres, jamais en clé technique', async () => {
        permissionChecker.mockReturnValue(false);
        const w = buildHarness();
        await w.setData({ staleOpen: true, staleOrders: [ligne({ status: 8 })], staleMeta: { count: 1, shown: 1, truncated: false } });
        expect(w.find('.pos-tracker-stale-status').text()).not.toMatch(/^all\.order\.status\./);
    });
});
