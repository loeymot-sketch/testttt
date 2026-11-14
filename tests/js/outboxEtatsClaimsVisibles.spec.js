import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OutboxOverviewComponent from '../../resources/js/components/admin/observability/OutboxOverviewComponent.vue';

/**
 * [G2 2026-09-03 · T2.2 · défaut V-03] Les états chargés ne sont jamais rendus.
 *
 * `loadAll()` affecte `this.inFlight`, `this.staleClaimed` et `this.terminalFailures`
 * (OutboxOverviewComponent.vue, méthode loadAll) — mais le TEMPLATE n'affiche aucun
 * des trois. Le cockpit peut donc connaître 2 149 claims orphelins (le chiffre mesuré
 * sur la base servie le 2026-09-02) et n'en montrer aucun : l'écran est muet sur
 * exactement la population qu'on vient y chercher.
 *
 * `terminalFailures.count` n'apparaît que dans un `title` de bouton — donc invisible
 * sans survol, et absent de tout lecteur d'écran qui parcourt la page.
 *
 * Le banc mesure le RENDU.
 */
const REPONSE = {
    generated_at: '2026-09-03T10:00:00+02:00',
    pending: { count: 7, rows: [] },
    terminal_failures: { count: 5, contract_violations: 2 },
    replayable_events: { count: 3, max_age_days: 7 },
    in_flight: { count: 12, stale_after_minutes: 10 },
    stale_claimed: {
        count: 2149,
        rows: [
            {
                id: 90001,
                event_type: 'OrderStatusChanged',
                aggregate_type: 'order',
                aggregate_id: 4242,
                branch_id: 1,
                attempts: 1,
                last_error: null,
                occurred_at: '2026-09-03T08:00:00+02:00',
                created_at: '2026-09-03T08:00:00+02:00',
                dispatched_at: '2026-09-03T08:00:05+02:00',
            },
            {
                id: 90002,
                event_type: 'OrderCreated',
                aggregate_type: 'order',
                aggregate_id: 4243,
                branch_id: 1,
                attempts: 2,
                last_error: 'broker unreachable',
                occurred_at: '2026-09-03T08:01:00+02:00',
                created_at: '2026-09-03T08:01:00+02:00',
                dispatched_at: '2026-09-03T08:01:05+02:00',
            },
        ],
    },
    delivered_24h: { count: 3, latency_p50_ms: 10, latency_p95_ms: 20, latency_p99_ms: 30, samples: 3 },
    queue_high: { available: true, count: 0, oldest_age_seconds: null },
    failed_jobs: { available: true, count: 0, rows: [] },
    purgeable_failed_jobs: { count: 0, older_than_hours: 24 },
    health: {
        queue_work: { status: 'down', last_signal_age_seconds: null, method: 'x' },
        websockets_serve: { status: 'down', last_signal_age_seconds: null, method: 'x' },
    },
};

let axiosMock;

function monter() {
    return mount(OutboxOverviewComponent, {
        global: { mocks: { $t: (k) => k } },
    });
}

describe('Cockpit outbox — les claims en vol et orphelins sont visibles', () => {
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

    it('affiche le nombre de claims EN VOL', async () => {
        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-in-flight-count"]').text()).toContain('12');
    });

    it('affiche le nombre de claims ORPHELINS', async () => {
        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-stale-claimed-count"]').text()).toContain('2149');
    });

    it('affiche le nombre d’échecs TERMINAUX ailleurs que dans un attribut title', async () => {
        const w = monter();
        await flushPromises();

        const bloc = w.get('[data-testid="outbox-terminal-count"]');
        expect(bloc.text()).toContain('5');
        expect(bloc.text(), 'les violations de contrat, non rejouables, sont distinguées').toContain('2');
    });

    it('la liste des orphelins est consultable, ligne par ligne', async () => {
        const w = monter();
        await flushPromises();

        expect(w.find('[data-testid="outbox-stale-row-90001"]').exists()).toBe(true);
        expect(w.get('[data-testid="outbox-stale-row-90002"]').text()).toContain('OrderCreated');
        expect(w.get('[data-testid="outbox-stale-row-90002"]').text()).toContain('broker unreachable');
    });

    it('le seuil d’orphelinat affiché est celui que le serveur applique', async () => {
        const w = monter();
        await flushPromises();

        // 10 min = CLAIM_STALE_MINUTES côté serveur. Afficher « 5 » (l'ancienne valeur
        // par défaut du composant) ferait dire à l'écran autre chose que la mesure.
        expect(w.get('[data-testid="outbox-claims"]').text()).toContain('10');
    });

    it('la zone des claims est annoncée quand elle change', async () => {
        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-claims"]').attributes('aria-live')).toBe('polite');
    });

    it('sans orphelin, la zone le dit au lieu de rester vide', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...REPONSE, in_flight: { count: 0, stale_after_minutes: 10 }, stale_claimed: { count: 0, rows: [] } },
        });

        const w = monter();
        await flushPromises();

        expect(w.get('[data-testid="outbox-stale-claimed-count"]').text()).toContain('0');
        expect(w.find('[data-testid="outbox-stale-row-90001"]').exists()).toBe(false);
    });
});
