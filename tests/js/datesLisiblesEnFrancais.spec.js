import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import appService from '../../resources/js/services/appService';
import OutboxOverviewComponent from '../../resources/js/components/admin/observability/OutboxOverviewComponent.vue';
import LastZReportWidget from '../../resources/js/components/admin/dashboard/LastZReportWidget.vue';

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · relevé de la campagne navigateur]
 *
 * Constaté sur capture : le cockpit outbox affichait « Généré à 9/2/2026, 7:38:28 PM » et
 * le widget de clôture « 7/16/2026, 6:57:02 AM » — dans une interface entièrement
 * française, sur une machine dont la locale applicative est `fr` (config/app.php : « DO
 * NOT CHANGE — French locale required »).
 *
 * `toLocaleString()` sans argument suit la locale du NAVIGATEUR, pas celle du produit.
 * « 9/2/2026 » se lit 9 février pour un lecteur français et 2 septembre pour un lecteur
 * américain : sur une date de clôture Z, l'ambiguïté porte sur une pièce fiscale.
 */
/**
 * Sous Node, `toLocaleString()` sans argument rend DÉJÀ un format français : un banc naïf
 * resterait vert alors que Chromium, lui, rend « 9/2/2026, 7:38:28 PM » — c'est ce que la
 * campagne navigateur a photographié. On simule donc un navigateur américain en faisant
 * répondre `toLocaleString()` SANS argument à l'américaine ; l'appel AVEC locale reste
 * intact. Un banc qui ne reproduit pas le défaut ne prouve rien.
 */
function simulerNavigateurAmericain() {
    const vrai = Date.prototype.toLocaleString;
    vi.spyOn(Date.prototype, 'toLocaleString').mockImplementation(function (locales, options) {
        if (locales === undefined) {
            return vrai.call(this, 'en-US', options);
        }

        return vrai.call(this, locales, options);
    });
}

describe('les dates affichées suivent la locale du produit, pas celle du navigateur', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('le formateur rend jour/mois/année et une heure sur 24 h', () => {
        const rendu = appService.dateHeureFr('2026-09-02T19:38:28+02:00');

        expect(rendu).toMatch(/02\/09\/2026/);
        expect(rendu).toMatch(/19:38/);
        expect(rendu).not.toMatch(/PM|AM/);
    });

    it('une valeur illisible est rendue telle quelle, pas en « Invalid Date »', () => {
        expect(appService.dateHeureFr('pas-une-date')).toBe('pas-une-date');
        expect(appService.dateHeureFr(null)).toBe('—');
    });

    it('le cockpit outbox date sa mesure en français', async () => {
        const axiosMock = {
            get: vi.fn().mockResolvedValue({
                data: {
                    generated_at: '2026-09-02T19:38:28+02:00',
                    pending: { count: 0, rows: [] },
                    terminal_failures: { count: 0, contract_violations: 0 },
                    delivered_24h: { count: 0, latency_p50_ms: null, latency_p95_ms: null, latency_p99_ms: null, samples: 0 },
                    queue_high: { available: true, count: 0, oldest_age_seconds: null },
                    failed_jobs: { available: true, count: 0, rows: [] },
                    health: {
                        queue_work: { status: 'up', last_signal_age_seconds: 1, method: 'h' },
                        websockets_serve: { status: 'up', last_signal_age_seconds: 1, method: 'h' },
                    },
                },
            }),
            post: vi.fn(),
        };
        globalThis.axios = axiosMock;
        window.axios = axiosMock;

        simulerNavigateurAmericain();
        const w = mount(OutboxOverviewComponent, { global: { mocks: { $t: (k) => k } } });
        await flushPromises();

        const date = w.get('[data-testid="outbox-generated-at"]').text();
        expect(date).toMatch(/02\/09\/2026/);
        expect(date).not.toMatch(/PM|AM/);
    });

    it('le widget de clôture Z date en français', async () => {
        simulerNavigateurAmericain();
        const w = mount(LastZReportWidget, {
            global: { mocks: { $t: (k) => k, $store: { getters: {}, dispatch: vi.fn() } } },
        });
        await w.setData({ resolvedReport: { closed_at: '2026-07-16T06:57:02+02:00' } });

        expect(w.vm.formattedClosedAt).toMatch(/16\/07\/2026/);
        expect(w.vm.formattedClosedAt).not.toMatch(/PM|AM/);
    });
});

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · relevé de la campagne navigateur]
 *
 * La capture du tableau de bord montrait, dans le journal d'audit NF525, la ligne
 * `user.device_revoked` en clair — un code technique servi à un lecteur français, parce
 * qu'aucun libellé ne lui correspondait. Relevé sur le journal réel de cette machine :
 * quatre des actions écrites n'avaient aucune traduction, dont celle des bascules
 * d'interrupteurs ajoutée le même jour.
 */
describe('les actions du journal d’audit ont toutes un libellé', () => {
    const fr = require('../../resources/js/languages/fr.json');
    const en = require('../../resources/js/languages/en.json');

    // Actions relevées dans `audit_logs` sur cette base + celle ajoutée par le pilotage.
    const ACTIONS = [
        'cash.movement.recorded', 'cash.session.closed', 'cash.session.opened',
        'cash.session.reconciled', 'delivery.cash_collected_escrow', 'fiscal.orphan_backfilled',
        'order.cancelled', 'order.counter_payment_canceled', 'order.counter_payment_confirmed',
        'order.created.pos', 'order.delivery_boy_assigned', 'order.discount_applied',
        'order.payment_status_change_blocked', 'order.payment_status_changed',
        'order.refund.counter_entry', 'order.rejected', 'order.returned', 'outbox.replay',
        'payment.cash_back_issued', 'pos.receipt.print', 'pos.receipt.reprint',
        'user.device_revoked', 'user.login', 'user.logout', 'z_report.closed',
        'pilotage.interrupteur.bascule',
    ];

    it('chaque action écrite au journal a un libellé français', () => {
        const sansLibelle = ACTIONS.filter((a) => !fr.label[`audit_event_${a.replace(/\./g, '_')}`]);
        expect(sansLibelle, 'actions affichées en code brut à l’écran').toEqual([]);
    });

    it('chaque action a aussi un libellé anglais', () => {
        const sansLibelle = ACTIONS.filter((a) => !en.label[`audit_event_${a.replace(/\./g, '_')}`]);
        expect(sansLibelle).toEqual([]);
    });
});
