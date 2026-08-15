/**
 * cashSessionReportReconcile.spec.js
 *
 * [P0 CLÔTURE-BLOQUÉE 2026-08-15 · GOAL_CONFORT_MAX] Une session CLOSED-non-
 * réconciliée (2e appel /reconcile échoué — écart > seuil, permission manquante)
 * n'avait AUCUN chemin de reprise : `reconcile()` existait côté JS mais 0 écran
 * ne l'appelait (grep composants = 0 résultat avant ce fix). L'écran de caisse ne
 * relit QUE status=OPEN → session bloquée, invisible, à vie. Famille du sinistre
 * « Z bloqué 17 jours ».
 *
 * Ce test verrouille que CashSessionReportListComponent.vue — qui liste déjà
 * TOUTES les sessions (dont `status=closed`, sans filtre de date par défaut) —
 * offre désormais un chemin de reprise réel : bouton visible sur une session
 * bloquée, tentative sans motif d'abord (idempotent), révélation du champ motif
 * si le backend l'exige, message clair (FR, ADR-007) si seul un manager peut
 * valider.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('axios', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } } }),
    },
}));

vi.mock('../../resources/js/services/CashDrawerService', () => ({
    reconcile: vi.fn(),
}));
vi.mock('../../resources/js/services/alertService', () => ({
    default: { success: vi.fn(), error: vi.fn() },
}));

import CashSessionReportListComponent from '../../resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue';
import { reconcile } from '../../resources/js/services/CashDrawerService';

// [PIÈGE ATTRAPÉ 2026-08-15] `attemptReconcile()` mute `session.status` EN PLACE
// (affichage immédiat sans reload — c'est le comportement voulu du composant).
// Un objet const PARTAGÉ entre tests se fait donc polluer par le premier test qui
// réconcilie avec succès : les suivants héritent silencieusement de son état
// mutée. Fabrique = un objet FRAIS par test, jamais de référence partagée.
function makeClosedSession(overrides = {}) {
    return {
        id: 501,
        branch_id: 1,
        business_date: '2026-08-15',
        opened_at: '2026-08-15T08:00:00Z',
        closed_at: '2026-08-15T20:00:00Z',
        opened_by_name: 'Caissier A',
        opening_amount: 100,
        closing_amount: 250,
        expected_closing_amount: null,
        variance: null,
        variance_reason: null,
        status: 'closed',
        transactions_count: 12,
        ...overrides,
    };
}

async function mountWithSessions(sessions) {
    const wrapper = mount(CashSessionReportListComponent, {
        global: { mocks: { $t: (k, params) => (params ? `${k}:${JSON.stringify(params)}` : k), $i18n: { locale: 'fr-FR' } } },
    });
    // `mounted()` déclenche loadSessions() (async, mockée → []) : il faut laisser
    // CETTE résolution se terminer avant d'injecter les données de test, sinon
    // elle écrase silencieusement wrapper.vm.sessions APRÈS coup (flushPromises
    // suivant la déclenche).
    await flushPromises();
    wrapper.vm.sessions = sessions;
    await wrapper.vm.$nextTick();
    return wrapper;
}

describe('CashSessionReportListComponent — reprise de réconciliation (P0)', () => {
    beforeEach(() => {
        reconcile.mockReset();
    });

    it('affiche un bouton "réconcilier" UNIQUEMENT sur une session status=closed', async () => {
        const wrapper = await mountWithSessions([
            makeClosedSession({ id: 1, status: 'open' }),
            makeClosedSession({ id: 2, status: 'closed' }),
            makeClosedSession({ id: 3, status: 'reconciled' }),
        ]);
        await flushPromises();
        const buttons = wrapper.findAll('button').filter((b) => b.text().includes('button.cash_reconcile_now'));
        expect(buttons).toHaveLength(1);
    });

    it('tente reconcile() SANS motif au premier clic (chemin idempotent, écart sous le seuil)', async () => {
        reconcile.mockResolvedValue({ data: { status: 'reconciled', variance: 1.2, expected: 248.8 } });
        const wrapper = await mountWithSessions([makeClosedSession()]);
        await flushPromises();

        await wrapper.get('[data-test="cash-reconcile-start"]').trigger('click');
        await flushPromises();

        expect(reconcile).toHaveBeenCalledWith(501, null);
        // La session est mise à jour EN PLACE — visible et terminée, sans reload.
        expect(wrapper.vm.sessions[0].status).toBe('reconciled');
    });

    it('révèle le champ motif si le backend répond CASH_VARIANCE_REASON_REQUIRED, puis renvoie AVEC le motif', async () => {
        reconcile
            .mockRejectedValueOnce({ response: { data: { code: 'CASH_VARIANCE_REASON_REQUIRED', threshold: 2 } } })
            .mockResolvedValueOnce({ data: { status: 'reconciled', variance: 15, expected: 235 } });

        const wrapper = await mountWithSessions([makeClosedSession()]);
        await flushPromises();

        await wrapper.get('[data-test="cash-reconcile-start"]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.reconcileNeedsReason).toBe(true);
        const textarea = wrapper.find('[data-test="cash-reconcile-reason"]');
        expect(textarea.exists()).toBe(true);

        await textarea.setValue('Erreur de comptage');
        await wrapper.get('[data-test="cash-reconcile-confirm"]').trigger('click');
        await flushPromises();

        expect(reconcile).toHaveBeenLastCalledWith(501, 'Erreur de comptage');
        expect(wrapper.vm.sessions[0].status).toBe('reconciled');
    });

    it('affiche un message clair (pas de champ motif) si seul un manager peut valider', async () => {
        reconcile.mockRejectedValueOnce({
            response: { data: { code: 'CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED', threshold: 2 } },
        });
        const wrapper = await mountWithSessions([makeClosedSession()]);
        await flushPromises();

        await wrapper.get('[data-test="cash-reconcile-start"]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.reconcileNeedsReason).toBe(false);
        expect(wrapper.vm.reconcileError).toContain('message.cash_variance_manager_required');
        // Aucun champ de saisie inutile pour un caissier qui n'a de toute façon pas
        // le pouvoir d'approuver — pas de fausse action.
        expect(wrapper.find('[data-test="cash-reconcile-reason"]').exists()).toBe(false);
    });

    it('le bouton "annuler" referme le panneau de reprise sans appeler reconcile de nouveau', async () => {
        reconcile.mockRejectedValueOnce({ response: { data: { code: 'CASH_VARIANCE_REASON_REQUIRED', threshold: 2 } } });
        const wrapper = await mountWithSessions([makeClosedSession()]);
        await flushPromises();
        await wrapper.get('[data-test="cash-reconcile-start"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="cash-reconcile-reason"]').exists()).toBe(true);
        await wrapper.get('[data-test="cash-reconcile-cancel"]').trigger('click');
        await flushPromises();

        expect(wrapper.vm.reconcileTarget).toBeNull();
        expect(wrapper.find('[data-test="cash-reconcile-reason"]').exists()).toBe(false);
        expect(reconcile).toHaveBeenCalledTimes(1);
    });
});
