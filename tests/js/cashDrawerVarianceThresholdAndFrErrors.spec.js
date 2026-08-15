import { describe, it, expect, beforeEach, afterEach } from 'vitest';

/**
 * [T-4.3 SEUIL-INCOHERENT + ERREUR-ANGLAISE 2026-08-15 · GOAL_CONFORT_MAX]
 *
 * Deux défauts confirmés par lecture de code dans PosCashDrawerSessionDialog.vue :
 *
 *  1. `varianceRequiresReason` (computed) comparait `Math.abs(liveVariance) > 0.005`
 *     — un seuil quasi nul — alors que le SERVEUR (CashDrawerService::closeSession,
 *     config/cash.php:31) n'exige un motif qu'au-delà de 2,00€ par défaut. Le
 *     caissier était bloqué pour de simples centimes d'arrondi que le serveur
 *     aurait acceptés sans discussion.
 *
 *  2. `_extractError()` lisait `err.response.data.message` en premier — or
 *     `CashDrawerService::closeSession()` (app/Services/Cash/CashDrawerService.php:279-
 *     306) lève `CashVarianceRequiresApprovalException` avec un message CODÉ EN DUR
 *     EN ANGLAIS ("Cash variance X€ exceeds threshold Y€ — ..."). Un caissier FR
 *     (ADR-007) voyait cet anglais brut au lieu d'un message traduit.
 *
 * Convention : tester la logique (computed/methods) directement, sans monter le
 * composant complet (cf. encaissementClientReceiptGate.spec.js pour ce précédent).
 */
import PosCashDrawerSessionDialog from '../../resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue';

const varianceThresholdEur = PosCashDrawerSessionDialog.computed.varianceThresholdEur;
const varianceRequiresReason = PosCashDrawerSessionDialog.computed.varianceRequiresReason;
const extractError = PosCashDrawerSessionDialog.methods._extractError;

describe('PosCashDrawerSessionDialog — seuil écart aligné serveur (T-4.3)', () => {
    const originalConfig = window.foodkingConfig;
    afterEach(() => { window.foodkingConfig = originalConfig; });

    it('sans config exposée : retombe sur 2,00€ (le défaut serveur config/cash.php:31)', () => {
        window.foodkingConfig = {};
        expect(varianceThresholdEur.call({})).toBe(2.00);
    });

    it('lit window.foodkingConfig.cash.varianceThresholdEur quand présent', () => {
        window.foodkingConfig = { cash: { varianceThresholdEur: 5.5 } };
        expect(varianceThresholdEur.call({})).toBe(5.5);
    });

    it('un écart de 0,50€ (sous le seuil 2,00€) ne doit PLUS exiger de motif', () => {
        window.foodkingConfig = { cash: { varianceThresholdEur: 2.00 } };
        const ctx = { mode: 'close', liveVariance: 0.50, varianceThresholdEur: varianceThresholdEur.call({}) };
        expect(varianceRequiresReason.call(ctx)).toBe(false);
    });

    it('un écart de 2,50€ (au-dessus du seuil 2,00€) exige toujours un motif', () => {
        window.foodkingConfig = { cash: { varianceThresholdEur: 2.00 } };
        const ctx = { mode: 'close', liveVariance: 2.50, varianceThresholdEur: varianceThresholdEur.call({}) };
        expect(varianceRequiresReason.call(ctx)).toBe(true);
    });
});

describe('PosCashDrawerSessionDialog._extractError — jamais d\'anglais brut sur écran FR (T-4.3)', () => {
    function ctx() {
        return {
            $t: (key, params) => (params ? `${key}:${JSON.stringify(params)}` : key),
            formatMoney: (v) => `${Number(v).toFixed(2)} €`,
        };
    }

    it('code CASH_VARIANCE_REASON_REQUIRED → message FR traduit, jamais le texte anglais serveur', () => {
        const err = {
            response: {
                data: {
                    code: 'CASH_VARIANCE_REASON_REQUIRED',
                    message: 'Cash variance 3.50€ exceeds threshold 2.00€ — variance_reason required',
                    threshold: 2.00,
                },
            },
        };
        const result = extractError.call(ctx(), err);
        expect(result).not.toMatch(/exceeds threshold|variance_reason required/i);
        expect(result).toContain('message.cash_variance_reason_required');
    });

    it('code CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED → message FR traduit, jamais l\'anglais serveur', () => {
        const err = {
            response: {
                data: {
                    code: 'CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED',
                    message: 'Cash variance 9.00€ exceeds threshold 2.00€ — manager approval required (permission cash.reconcile.variance.override)',
                    threshold: 2.00,
                },
            },
        };
        const result = extractError.call(ctx(), err);
        expect(result).not.toMatch(/exceeds threshold|manager approval required/i);
        expect(result).toContain('message.cash_variance_manager_required');
    });

    it('code inconnu : retombe sur le message serveur brut (mieux que rien)', () => {
        const err = { response: { data: { code: 'SOME_OTHER_ERROR', message: 'Something else went wrong' } } };
        expect(extractError.call(ctx(), err)).toBe('Something else went wrong');
    });

    it('aucune réponse serveur : retombe sur err.message puis le fallback traduit', () => {
        expect(extractError.call(ctx(), { message: 'Network Error' })).toBe('Network Error');
        expect(extractError.call(ctx(), {})).toBe('label.cash_session_failure');
    });
});
