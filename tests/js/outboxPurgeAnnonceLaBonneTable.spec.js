import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OutboxOverviewComponent from '../../resources/js/components/admin/observability/OutboxOverviewComponent.vue';

/**
 * [G2 2026-09-03 · T2.3 · défaut V-04] La purge compte la mauvaise table.
 *
 * « Purger » appelle `POST /outbox/drain-failed`, qui supprime des lignes `failed_jobs`
 * (`SyncOverviewController::outboxDrainFailed` → `DB::table('failed_jobs')->…->delete()`).
 * Mais la confirmation annonce `terminalFailures.count` — un compteur de `domain_events`
 * terminaux — et le bouton lui-même est activé/désactivé sur ce compteur étranger.
 *
 * Conséquences mesurables, toutes deux fausses :
 *   - 5 événements terminaux et 0 job purgeable ⇒ le bouton invite à purger, la
 *     confirmation annonce « 5 supprimés », et la purge n'efface rien ;
 *   - 0 événement terminal et 3 jobs purgeables ⇒ le bouton est inerte alors qu'il y a
 *     bien quelque chose à purger.
 *
 * Le compteur qui gouverne l'action doit être celui de la table que l'action touche.
 */
const REPONSE = {
    generated_at: '2026-09-03T10:00:00+02:00',
    pending: { count: 5, rows: [] },
    terminal_failures: { count: 5, contract_violations: 0 },
    replayable_events: { count: 5, max_age_days: 7 },
    in_flight: { count: 0, stale_after_minutes: 10 },
    stale_claimed: { count: 0, rows: [] },
    delivered_24h: { count: 0, latency_p50_ms: null, latency_p95_ms: null, latency_p99_ms: null, samples: 0 },
    queue_high: { available: true, count: 0, oldest_age_seconds: null },
    failed_jobs: { available: true, count: 2, rows: [] },
    purgeable_failed_jobs: { count: 0, older_than_hours: 24 },
    health: {
        queue_work: { status: 'up', last_signal_age_seconds: 1, method: 'x' },
        websockets_serve: { status: 'up', last_signal_age_seconds: 1, method: 'x' },
    },
};

let axiosMock;

function monter() {
    return mount(OutboxOverviewComponent, {
        global: { mocks: { $t: (k) => k } },
    });
}

describe('Cockpit outbox — la purge annonce la table qu’elle purge', () => {
    beforeEach(() => {
        axiosMock = { get: vi.fn(), post: vi.fn() };
        globalThis.axios = axiosMock;
        window.axios = axiosMock;
        axiosMock.post.mockResolvedValue({ data: { deleted: 0 } });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('5 échecs terminaux mais 0 job purgeable ⇒ le bouton Purger est inerte', async () => {
        axiosMock.get.mockResolvedValue({ data: REPONSE });

        const w = monter();
        await flushPromises();

        const bouton = w.get('[data-testid="outbox-drain-failed"]');
        expect(bouton.attributes('disabled'), 'rien à purger ⇒ bouton désactivé').toBeDefined();
        expect(bouton.attributes('title')).toMatch(/aucun/i);
    });

    it('la confirmation n’annonce JAMAIS le compteur des domain_events', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...REPONSE, purgeable_failed_jobs: { count: 3, older_than_hours: 24 } },
        });

        const w = monter();
        await flushPromises();
        await w.vm.drainFailed();
        await flushPromises();

        const texte = w.get('[data-testid="outbox-drain-confirm"]').text();
        expect(texte, 'le nombre de jobs réellement purgeables').toContain('3');
        expect(texte, 'le compteur domain_events (5) n’a rien à faire ici').not.toContain('5');
    });

    it('0 échec terminal mais 3 jobs purgeables ⇒ le bouton Purger reste offert', async () => {
        axiosMock.get.mockResolvedValue({
            data: {
                ...REPONSE,
                terminal_failures: { count: 0, contract_violations: 0 },
                replayable_events: { count: 0, max_age_days: 7 },
                purgeable_failed_jobs: { count: 3, older_than_hours: 24 },
            },
        });

        const w = monter();
        await flushPromises();

        const purger = w.get('[data-testid="outbox-drain-failed"]');
        expect(purger.attributes('disabled'), 'il y a bien 3 jobs à purger').toBeUndefined();
        // …et l'autre action reste, elle, correctement inerte : deux compteurs, deux boutons.
        expect(w.get('[data-testid="outbox-retry-failed"]').attributes('disabled')).toBeDefined();
    });

    it('la purge dit combien de JOBS ont été supprimés, pas « événements »', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...REPONSE, purgeable_failed_jobs: { count: 3, older_than_hours: 24 } },
        });
        axiosMock.post.mockResolvedValue({ data: { deleted: 2, older_than_hours: 24, exported_to: 'outbox/x.json' } });

        const w = monter();
        await flushPromises();
        await w.vm.drainFailed();
        await w.vm.confirmerPurge();
        await flushPromises();

        const texte = w.get('[data-testid="outbox-retour-action"]').text();
        expect(texte).toContain('2');
        expect(texte, 'la purge nomme les travaux en échec').toMatch(/travail|travaux|jobs/i);
        expect(texte, 'aucun `domain_event` n’a été supprimé : ne pas le laisser croire')
            .not.toMatch(/événement/i);
    });

    it('le bouton Rejouer suit son propre compteur, pas celui de la purge', async () => {
        axiosMock.get.mockResolvedValue({
            data: {
                ...REPONSE,
                terminal_failures: { count: 4, contract_violations: 4 },
                // 4 terminaux mais tous en violation de contrat : aucun n'est rejouable.
                replayable_events: { count: 0, max_age_days: 7 },
                purgeable_failed_jobs: { count: 9, older_than_hours: 24 },
            },
        });

        const w = monter();
        await flushPromises();

        expect(
            w.get('[data-testid="outbox-retry-failed"]').attributes('disabled'),
            'une violation de contrat n’est pas rejouable : le bouton doit le refléter'
        ).toBeDefined();
        expect(w.get('[data-testid="outbox-drain-failed"]').attributes('disabled')).toBeUndefined();
    });
});
