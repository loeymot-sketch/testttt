/**
 * CashDrawerService.js — Sprint 1A
 *
 * Wrapper axios pour les endpoints `/api/admin/pos/cash-drawer/sessions/...`
 * exposés par `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php`.
 *
 * Backend contract (verifié 2026-05-16 contre le controller) :
 *   - GET    /admin/pos/cash-drawer/sessions/current
 *            → renvoie la session OPEN du caissier courant (ou data:null)
 *   - POST   /admin/pos/cash-drawer/sessions/open
 *            body: { opening_amount }
 *            note: branch_id dérivé de auth()->user()->branch_id, PAS du body
 *   - POST   /admin/pos/cash-drawer/sessions/{id}/close
 *            body: { closing_amount }
 *   - POST   /admin/pos/cash-drawer/sessions/{id}/reconcile
 *            body: { variance_reason? } (calcule expected + variance server-side ;
 *            variance_reason est REQUIS par le backend dès que |variance| dépasse
 *            `cash.variance_threshold_eur` — voir CashDrawerService::reconcileSession I6)
 *   - GET    /admin/pos/cash-drawer/sessions/{id}/movements
 *
 * Idempotence :
 *   Tous les POST mutating reçoivent un header X-Idempotency-Key (RFC + nos
 *   middlewares Laravel). Voir resources/js/store/modules/posOrder.js pour
 *   le pattern de base.
 */

import axios from 'axios';

function client() {
    if (typeof window !== 'undefined' && window.axios) {
        return window.axios;
    }
    return axios;
}

function freshIdempotencyKey(prefix) {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return `${prefix}-${crypto.randomUUID()}`;
    }
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

function unwrap(response) {
    return response && response.data && response.data.data !== undefined
        ? response.data.data
        : response && response.data;
}

/**
 * GET current session for the calling user.
 *
 * [Wave O / O-1 2026-05-20] Admin branch context heal — accepts optional
 * branchId so the global Admin / Tenant Admin (auth branch_id=0) can poll
 * the drawer of the dropdown-selected filiale. Branch-bound staff can omit
 * the arg; backend silently ignores any mismatched value for staff.
 *
 * @param {number|null} branchId  Optional target branch (admin must supply).
 * @returns {Promise<Object|null>} session payload or null when no OPEN session.
 */
export async function currentSession(branchId = null) {
    const config = {};
    const branchInt = Number.parseInt(branchId, 10);
    if (Number.isFinite(branchInt) && branchInt > 0) {
        config.params = { branch_id: branchInt };
    }
    const res = await client().get('admin/pos/cash-drawer/sessions/current', config);
    return unwrap(res) || null;
}

/**
 * POST open a new cash drawer session.
 *
 * [Wave O / O-1 2026-05-20] Admin branch context heal — body now carries
 * `branch_id` for global Admin / Tenant Admin (auth branch_id=0). Backend
 * resolution rules (see CashDrawerSessionController::resolveBranchId) :
 *   - Admin must supply branch_id (otherwise 422 with the historical message).
 *   - Staff: optional ; if present must equal their auth.branch_id (else 403).
 *
 * @param {number} openingAmount  Float >= 0 — fond de caisse initial.
 * @param {number|null} branchId  Optional target branch (admin must supply).
 * @returns {Promise<Object>} the created session.
 */
export async function openSession(openingAmount, branchId = null) {
    const config = {
        headers: { 'X-Idempotency-Key': freshIdempotencyKey('cash-open') },
    };
    const body = { opening_amount: Number(openingAmount) };
    const branchInt = Number.parseInt(branchId, 10);
    if (Number.isFinite(branchInt) && branchInt > 0) {
        body.branch_id = branchInt;
    }
    const res = await client().post(
        'admin/pos/cash-drawer/sessions/open',
        body,
        config
    );
    return unwrap(res);
}

/**
 * POST close + auto-reconcile chain.
 *
 * Two-step server flow:
 *   1. POST /{id}/close  → freezes closing_amount
 *   2. POST /{id}/reconcile → computes expected_closing_amount + variance
 *
 * [CAISSE 2026-08-14 · GOAL_CAYENNE_FINITION §1.1] LE BUG QUI EMPÊCHAIT TOUTE CLÔTURE RÉELLE.
 *
 * `varianceReason` était saisie dans le dialog (mode "close", champ obligatoire dès que l'écart
 * dépasse le seuil), mais cette fonction ne la transmettait JAMAIS au POST /reconcile — le corps
 * envoyé était `{}`, littéralement vide. Le backend (`CashDrawerService::reconcileSession`, garde
 * I6) EXIGE un `variance_reason` non-vide dès que |variance| > `cash.variance_threshold_eur`
 * (2,00 € par défaut) : sans lui, il répond 422 `CASH_VARIANCE_REASON_REQUIRED` — même si le
 * caissier avait bien tapé une raison à l'écran, elle se perdait entre le Vuex store et l'appel
 * réseau. Mesuré en production : deux sessions ouvertes depuis 36 et 49 jours pour 3 818,30 € de
 * mouvements — un écart de plusieurs semaines de caisse dépasse presque toujours 2 €, donc TOUTE
 * tentative de clôture réelle échouait silencieusement à cette 2e étape, laissant la session OPEN.
 *
 * Le champ était réellement conçu pour le controller `reconcile()` (pas `close()`, qui n'a jamais
 * eu besoin d'une raison) — cette fonction le transmet maintenant à la bonne étape.
 *
 * @param {number} sessionId
 * @param {number} closingAmount  Float >= 0 — montant physiquement compté.
 * @param {string|null} varianceReason  Transmis au POST /reconcile.
 * @returns {Promise<Object>} { ...session, expected, variance }
 */
export async function closeSession(sessionId, closingAmount, varianceReason = null) {
    const closeBody = { closing_amount: Number(closingAmount) };
    const closeConfig = {
        headers: { 'X-Idempotency-Key': freshIdempotencyKey('cash-close') },
    };
    await client().post(
        `admin/pos/cash-drawer/sessions/${sessionId}/close`,
        closeBody,
        closeConfig
    );

    const reconcileConfig = {
        headers: { 'X-Idempotency-Key': freshIdempotencyKey('cash-reconcile') },
    };
    const reconcileBody = varianceReason ? { variance_reason: varianceReason } : {};
    const reconcileRes = await client().post(
        `admin/pos/cash-drawer/sessions/${sessionId}/reconcile`,
        reconcileBody,
        reconcileConfig
    );

    return unwrap(reconcileRes) || {};
}

/**
 * POST reconcile only (idempotent on backend — safe to retry).
 *
 * [P0 CLÔTURE-BLOQUÉE 2026-08-15 · GOAL_CONFORT_MAX] Cette fonction existait déjà mais
 * n'était appelée par AUCUN écran (grep composants = 0 résultat) : une session bloquée
 * en CLOSED-non-réconciliée (2e appel de closeSession échoué — écart > seuil, permission
 * manquante) n'avait donc AUCUN chemin pour être terminée, par personne. Câblée depuis
 * CashSessionReportListComponent.vue. `varianceReason` ajouté ici (absent avant, jamais
 * nécessaire tant que rien n'appelait cette fonction) pour permettre la reprise avec
 * justification, exactement comme le premier appel dans closeSession() ci-dessus.
 *
 * @param {number} sessionId
 * @param {string|null} varianceReason
 */
export async function reconcile(sessionId, varianceReason = null) {
    const config = {
        headers: { 'X-Idempotency-Key': freshIdempotencyKey('cash-reconcile') },
    };
    const body = varianceReason ? { variance_reason: varianceReason } : {};
    const res = await client().post(
        `admin/pos/cash-drawer/sessions/${sessionId}/reconcile`,
        body,
        config
    );
    return unwrap(res);
}

/**
 * GET cash movements for a session.
 *
 * @param {number} sessionId
 * @returns {Promise<Array>}
 */
export async function movements(sessionId) {
    const res = await client().get(
        `admin/pos/cash-drawer/sessions/${sessionId}/movements`
    );
    const data = unwrap(res);
    return Array.isArray(data) ? data : [];
}

/**
 * Compute expected closing amount locally from opening + movements.
 * Mirrors backend `CashDrawerService::reconcileSession` math. Used by the
 * UI to display a live "expected" total before the cashier closes.
 *
 * @param {number} openingAmount
 * @param {Array<{amount:number, direction:string}>} movementsList
 */
export function computeExpected(openingAmount, movementsList) {
    const base = Number(openingAmount) || 0;
    if (!Array.isArray(movementsList)) return base;
    const signedSum = movementsList.reduce((acc, m) => {
        const amt = Number(m && m.amount) || 0;
        const sign = m && m.direction === 'in' ? 1 : -1;
        return acc + (sign * amt);
    }, 0);
    return Math.round((base + signedSum) * 100) / 100;
}

export default {
    currentSession,
    openSession,
    closeSession,
    reconcile,
    movements,
    computeExpected,
};
