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
 *            note: variance_reason n'est PAS validé par le controller actuel
 *                  → conservé côté UI uniquement (Sprint 1B doit l'ajouter)
 *   - POST   /admin/pos/cash-drawer/sessions/{id}/reconcile
 *            body: {} (calcule expected + variance server-side)
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
 * @returns {Promise<Object|null>} session payload or null when no OPEN session.
 */
export async function currentSession() {
    const res = await client().get('admin/pos/cash-drawer/sessions/current');
    return unwrap(res) || null;
}

/**
 * POST open a new cash drawer session.
 *
 * @param {number} openingAmount  Float >= 0 — fond de caisse initial.
 * @returns {Promise<Object>} the created session.
 */
export async function openSession(openingAmount) {
    const config = {
        headers: { 'X-Idempotency-Key': freshIdempotencyKey('cash-open') },
    };
    const body = { opening_amount: Number(openingAmount) };
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
 * Variance reason (when |variance| > 0) is captured by the UI but currently
 * not persisted by the backend close() validator — Sprint 1B follow-up to
 * whitelist it. We surface it on the returned payload for the local UX flow.
 *
 * @param {number} sessionId
 * @param {number} closingAmount  Float >= 0 — montant physiquement compté.
 * @param {string|null} varianceReason  UI-only for now.
 * @returns {Promise<Object>} { ...session, expected, variance, variance_reason_local }
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
    const reconcileRes = await client().post(
        `admin/pos/cash-drawer/sessions/${sessionId}/reconcile`,
        {},
        reconcileConfig
    );

    const payload = unwrap(reconcileRes) || {};
    return {
        ...payload,
        variance_reason_local: varianceReason || null,
    };
}

/**
 * POST reconcile only (idempotent on backend — safe to retry).
 *
 * @param {number} sessionId
 */
export async function reconcile(sessionId) {
    const config = {
        headers: { 'X-Idempotency-Key': freshIdempotencyKey('cash-reconcile') },
    };
    const res = await client().post(
        `admin/pos/cash-drawer/sessions/${sessionId}/reconcile`,
        {},
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
