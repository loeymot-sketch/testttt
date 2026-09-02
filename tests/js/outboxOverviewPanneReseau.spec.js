import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OutboxOverviewComponent from '../../resources/js/components/admin/observability/OutboxOverviewComponent.vue';

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 5.3 · Codex P1-L]
 *
 * Le cockpit outbox est l'écran qu'on ouvre PRÉCISÉMENT quand quelque chose ne va pas.
 * Trois défauts le rendaient trompeur au pire moment :
 *
 *  1. `loadAll()` n'avait AUCUN `catch`. Si l'appel échoue (500, 403, réseau coupé),
 *     l'exception part dans le vide et l'écran garde les DERNIÈRES valeurs affichées —
 *     zéro en attente, sondes « en service » — pendant que le tuyau est bouché. Un écran
 *     de supervision qui garde son vert quand il ne mesure plus rien est pire qu'un écran
 *     éteint.
 *  2. Le composant lisait `dispatched_24h`, une clé qui comptait un CLAIM comme une
 *     livraison et qui n'existe plus depuis la correction de sémantique. Il affichait donc
 *     zéro livraison sans le dire.
 *  3. « Purger » supprime des lignes définitivement, sans confirmation ni retour.
 *
 * Le banc mesure le rendu, pas le source.
 */
const REPONSE = {
    generated_at: '2026-09-02T10:00:00+02:00',
    pending: { count: 3, rows: [] },
    terminal_failures: { count: 2, contract_violations: 1 },
    in_flight: { count: 5, stale_after_minutes: 5 },
    stale_claimed: { count: 4, rows: [] },
    delivered_24h: { count: 128, latency_p50_ms: 40, latency_p95_ms: 90, latency_p99_ms: 120, samples: 128 },
    queue_high: { available: true, count: 0, oldest_age_seconds: null },
    failed_jobs: { available: true, count: 2, rows: [] },
    health: {
        queue_work: { status: 'up', last_signal_age_seconds: 3, method: 'heartbeat' },
        websockets_serve: { status: 'up', last_signal_age_seconds: 4, method: 'heartbeat' },
    },
};

let axiosMock;

function monter() {
    return mount(OutboxOverviewComponent, {
        global: { mocks: { $t: (k) => k } },
    });
}

describe('Cockpit outbox — honnête quand il ne mesure plus rien', () => {
    beforeEach(() => {
        axiosMock = { get: vi.fn(), post: vi.fn() };
        globalThis.axios = axiosMock;
        window.axios = axiosMock;
        axiosMock.get.mockResolvedValue({ data: REPONSE });
        axiosMock.post.mockResolvedValue({ data: {} });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('affiche les livraisons réelles, pas la clé disparue dispatched_24h', async () => {
        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-delivered-count"]').text()).toContain('128');
    });

    it('une lecture en échec lève une alerte au lieu de garder le vert', async () => {
        const w = monter();
        await flushPromises();

        axiosMock.get.mockRejectedValueOnce(new Error('réseau coupé'));
        await w.vm.loadAll();
        await flushPromises();

        const alerte = w.find('[data-testid="outbox-erreur"]');
        expect(alerte.exists()).toBe(true);
        expect(alerte.attributes('role')).toBe('alert');
    });

    it('les valeurs affichées sont marquées périmées après un échec', async () => {
        const w = monter();
        await flushPromises();

        axiosMock.get.mockRejectedValueOnce(new Error('500'));
        await w.vm.loadAll();
        await flushPromises();

        expect(w.get('[data-testid="outbox-overview-dashboard"]').attributes('data-perime')).toBe('true');
    });

    it('une lecture réussie efface l’alerte précédente', async () => {
        const w = monter();
        await flushPromises();

        axiosMock.get.mockRejectedValueOnce(new Error('500'));
        await w.vm.loadAll();
        await flushPromises();
        expect(w.find('[data-testid="outbox-erreur"]').exists()).toBe(true);

        await w.vm.loadAll();
        await flushPromises();
        expect(w.find('[data-testid="outbox-erreur"]').exists()).toBe(false);
        expect(w.get('[data-testid="outbox-overview-dashboard"]').attributes('data-perime')).toBe('false');
    });

    it('deux lectures concurrentes ne font qu’un seul appel', async () => {
        const w = monter();
        await flushPromises();
        axiosMock.get.mockClear();

        let debloque;
        axiosMock.get.mockReturnValue(new Promise((r) => { debloque = () => r({ data: REPONSE }); }));

        const a = w.vm.loadAll();
        const b = w.vm.loadAll();
        debloque();
        await Promise.all([a, b]);

        expect(axiosMock.get).toHaveBeenCalledTimes(1);
    });

    it('purger sans confirmation n’envoie aucune requête', async () => {
        const w = monter();
        await flushPromises();
        axiosMock.post.mockClear();

        await w.vm.drainFailed();
        await flushPromises();

        expect(axiosMock.post).not.toHaveBeenCalled();
        expect(w.find('[data-testid="outbox-drain-confirm"]').exists()).toBe(true);
    });

    it('purger après confirmation envoie la requête et rend compte', async () => {
        const w = monter();
        await flushPromises();
        axiosMock.post.mockClear();
        axiosMock.post.mockResolvedValue({ data: { deleted: 2 } });

        await w.vm.drainFailed();
        await w.vm.confirmerPurge();
        await flushPromises();

        expect(axiosMock.post).toHaveBeenCalledTimes(1);
        const retour = w.get('[data-testid="outbox-retour-action"]');
        expect(retour.attributes('role')).toBe('status');
    });

    it('une action en échec le dit, au lieu de faire comme si elle avait réussi', async () => {
        const w = monter();
        await flushPromises();
        axiosMock.post.mockRejectedValue(new Error('403'));

        await w.vm.retryFailed();
        await flushPromises();

        const retour = w.get('[data-testid="outbox-retour-action"]');
        expect(retour.attributes('role')).toBe('alert');
    });

    it('relancer n’est proposé que s’il existe des échecs terminaux', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...REPONSE, terminal_failures: { count: 0, contract_violations: 0 }, failed_jobs: { available: true, count: 0, rows: [] } },
        });
        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-retry-failed"]').attributes('disabled')).toBeDefined();
    });
});

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · relevé de la campagne navigateur]
 *
 * Sur la capture, « Rejouer Les Échecs » et « Purger Échecs > 24h » sont rigoureusement
 * identiques à « Rafraîchir » — alors qu'ils sont désactivés (aucun échec terminal).
 * `.db-btn` n'a AUCUN style d'état désactivé dans toute l'administration : un bouton
 * inerte a exactement l'apparence d'un bouton actif. Sur l'écran qu'on ouvre quand
 * quelque chose ne va pas, l'opérateur clique, rien ne se passe, et il en conclut que
 * l'outil est cassé.
 */
describe('Cockpit outbox — un bouton inerte doit se voir', () => {
    beforeEach(() => {
        axiosMock = { get: vi.fn(), post: vi.fn() };
        globalThis.axios = axiosMock;
        window.axios = axiosMock;
    });

    it('les actions indisponibles sont visuellement distinctes', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...REPONSE, terminal_failures: { count: 0, contract_violations: 0 } },
        });
        const w = monter();
        await flushPromises();

        for (const id of ['outbox-retry-failed', 'outbox-drain-failed']) {
            const b = w.get(`[data-testid="${id}"]`);
            expect(b.attributes('disabled'), `${id} doit être désactivé`).toBeDefined();
            expect(b.classes().join(' '), `${id} doit le montrer`).toMatch(/opacity|cursor-not-allowed/);
        }
    });

    it('les actions disponibles ne portent pas ce style', async () => {
        axiosMock.get.mockResolvedValue({ data: REPONSE });
        const w = monter();
        await flushPromises();

        const b = w.get('[data-testid="outbox-retry-failed"]');
        expect(b.attributes('disabled')).toBeUndefined();
        expect(b.classes().join(' ')).not.toMatch(/cursor-not-allowed/);
    });

    it('le bouton dit pourquoi il est inerte', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...REPONSE, terminal_failures: { count: 0, contract_violations: 0 } },
        });
        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-retry-failed"]').attributes('title'))
            .toMatch(/aucun échec/i);
    });
});
