import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * [CAISSE 2026-08-14 · GOAL_CAYENNE_FINITION §1.1] LE BUG QUI EMPÊCHAIT TOUTE CLÔTURE RÉELLE.
 *
 * `CashDrawerService.closeSession()` appelle POST /close PUIS POST /reconcile. La raison d'écart
 * saisie par le caissier (`varianceReason`) devait être transmise à la 2e étape — le backend
 * (`CashDrawerService::reconcileSession`, garde I6) l'EXIGE dès que |variance| dépasse
 * `cash.variance_threshold_eur` (2,00 € par défaut), sinon 422 CASH_VARIANCE_REASON_REQUIRED.
 *
 * Avant correctif : le corps du POST /reconcile était systématiquement `{}` — la raison tapée à
 * l'écran ne quittait jamais le navigateur. Deux sessions caisse en production sont restées
 * ouvertes 36 et 49 jours (3 818,30 € de mouvements, zéro clôture réussie) : un écart accumulé sur
 * des semaines dépasse presque toujours 2 €, donc CETTE étape échouait systématiquement.
 *
 * Ce test verrouille le contrat réseau : `variance_reason` DOIT apparaître dans le corps du POST
 * /reconcile quand une raison est fournie.
 */

const postMock = vi.fn();

vi.mock('axios', () => ({
    default: {
        post: (...args) => postMock(...args),
        get: vi.fn().mockResolvedValue({ data: { data: null } }),
    },
}));

import CashDrawerService from '../../resources/js/services/CashDrawerService';

describe('[CAISSE 2026-08-14] CashDrawerService.closeSession forwards variance_reason to /reconcile', () => {
    beforeEach(() => {
        postMock.mockReset();
        postMock.mockResolvedValue({ data: { data: { id: 42, status: 'reconciled', expected: 100, variance: 12.5 } } });
    });

    it('includes variance_reason in the /reconcile POST body when a reason is provided', async () => {
        await CashDrawerService.closeSession(42, 112.5, 'Erreur de comptage — pièces de 2€ mal triées');

        expect(postMock).toHaveBeenCalledTimes(2);

        const [closeUrl, closeBody] = postMock.mock.calls[0];
        expect(closeUrl).toBe('admin/pos/cash-drawer/sessions/42/close');
        expect(closeBody).toEqual({ closing_amount: 112.5 });

        const [reconcileUrl, reconcileBody] = postMock.mock.calls[1];
        expect(reconcileUrl).toBe('admin/pos/cash-drawer/sessions/42/reconcile');
        expect(reconcileBody).toEqual({ variance_reason: 'Erreur de comptage — pièces de 2€ mal triées' });
    });

    it('sends an empty body to /reconcile when no reason is provided (variance under threshold)', async () => {
        await CashDrawerService.closeSession(42, 100, null);

        const [, reconcileBody] = postMock.mock.calls[1];
        expect(reconcileBody).toEqual({});
    });
});
