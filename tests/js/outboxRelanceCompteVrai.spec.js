import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OutboxOverviewComponent from '../../resources/js/components/admin/observability/OutboxOverviewComponent.vue';

/**
 * [G2 2026-09-03 · T2.1 · défaut V-02] La relance ne dit jamais combien.
 *
 * Le serveur répond `{"requeued": 37}` (`SyncOverviewController::outboxRetryFailed`,
 * clé `requeued`). Le composant lit `data.retried` — une clé qui n'existe dans AUCUNE
 * réponse du contrôleur. `combien` vaut donc TOUJOURS `null`, et l'écran affiche
 * « Relance demandée. » quel que soit le résultat : 37 événements remis en file, ou zéro.
 *
 * Sur l'écran qu'on ouvre quand la synchro a un doute, une action dont on ne sait pas
 * si elle a porté sur 37 lignes ou sur aucune ne rend pas compte de ce qu'elle a fait.
 *
 * Le banc mesure le TEXTE RENDU, pas le source.
 */
const REPONSE = {
    generated_at: '2026-09-03T10:00:00+02:00',
    pending: { count: 3, rows: [] },
    terminal_failures: { count: 2, contract_violations: 0 },
    replayable_events: { count: 2, max_age_days: 7 },
    in_flight: { count: 0, stale_after_minutes: 10 },
    stale_claimed: { count: 0, rows: [] },
    delivered_24h: { count: 1, latency_p50_ms: 10, latency_p95_ms: 20, latency_p99_ms: 30, samples: 1 },
    queue_high: { available: true, count: 0, oldest_age_seconds: null },
    failed_jobs: { available: true, count: 0, rows: [] },
    purgeable_failed_jobs: { count: 0, older_than_hours: 24 },
    health: {
        queue_work: { status: 'up', last_signal_age_seconds: 3, method: 'x' },
        websockets_serve: { status: 'up', last_signal_age_seconds: 4, method: 'x' },
    },
};

let axiosMock;

function monter() {
    return mount(OutboxOverviewComponent, {
        global: { mocks: { $t: (k) => k } },
    });
}

describe('Cockpit outbox — la relance rend compte de ce qu’elle a fait', () => {
    beforeEach(() => {
        axiosMock = { get: vi.fn(), post: vi.fn() };
        globalThis.axios = axiosMock;
        window.axios = axiosMock;
        axiosMock.get.mockResolvedValue({ data: REPONSE });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('annonce le nombre RÉELLEMENT remis en file (clé `requeued` du serveur)', async () => {
        axiosMock.post.mockResolvedValue({
            data: { requeued: 37, audit_failed: 0, dispatch_failed: 0, limit: 50 },
        });

        const w = monter();
        await flushPromises();
        await w.vm.retryFailed();
        await flushPromises();

        const retour = w.get('[data-testid="outbox-retour-action"]');
        expect(retour.text(), 'le nombre remis en file doit être à l’écran').toContain('37');
        expect(retour.text()).not.toMatch(/^Relance demandée\.$/);
    });

    it('« zéro remis en file » se dit, au lieu de passer pour un succès muet', async () => {
        axiosMock.post.mockResolvedValue({
            data: { requeued: 0, audit_failed: 0, dispatch_failed: 0, limit: 50 },
        });

        const w = monter();
        await flushPromises();
        await w.vm.retryFailed();
        await flushPromises();

        expect(w.get('[data-testid="outbox-retour-action"]').text()).toContain('0');
    });

    it('un serveur qui échoue à auditer ou à ré-expédier le dit aussi', async () => {
        axiosMock.post.mockResolvedValue({
            data: { requeued: 4, audit_failed: 2, dispatch_failed: 1, limit: 50 },
        });

        const w = monter();
        await flushPromises();
        await w.vm.retryFailed();
        await flushPromises();

        const texte = w.get('[data-testid="outbox-retour-action"]').text();
        expect(texte).toContain('4');
        expect(texte, 'les échecs partiels ne doivent pas disparaître').toMatch(/2/);
    });

    it('repli daté sur `retried` : un serveur non redéployé reste lisible', async () => {
        // Repli de dépréciation (à retirer après V1.1) : si un backend antérieur
        // répond encore `retried`, l'écran doit compter, pas se taire.
        axiosMock.post.mockResolvedValue({ data: { retried: 12 } });

        const w = monter();
        await flushPromises();
        await w.vm.retryFailed();
        await flushPromises();

        expect(w.get('[data-testid="outbox-retour-action"]').text()).toContain('12');
    });
});
