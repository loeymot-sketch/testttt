/**
 * cashDrawer.js — Sprint 1A Vuex module
 *
 * State central de la session caisse courante du caissier. Le composant
 * `PosCashDrawerSessionDialog.vue` lit ce store et déclenche les actions ;
 * `PosComponent.vue` `mounted()` appelle `loadCurrentSession` après que le
 * `branch_id` soit résolu via `defaultAccess/show`.
 *
 * Backend SSOT: CashDrawerSessionController + CashDrawerService.
 * Service wrapper: resources/js/services/CashDrawerService.js
 */

import CashDrawerService from '../../services/CashDrawerService';

export const cashDrawer = {
    namespaced: true,
    state: () => ({
        session: null,            // current OPEN session payload (or null)
        movements: [],            // movements for the active session
        loading: false,
        error: null,              // last error message (cleared on success)
        lastLoadedAt: null,       // ts for debug / freshness
    }),
    getters: {
        session: (state) => state.session,
        isOpen: (state) => !!(state.session && state.session.status === 'open'),
        movements: (state) => state.movements,
        loading: (state) => state.loading,
        error: (state) => state.error,
        openingAmount: (state) => (state.session ? Number(state.session.opening_amount) || 0 : 0),
        sessionId: (state) => (state.session ? state.session.id : null),

        /* [CAISSE 2026-08-13] L'ANCIENNETÉ DE LA SESSION, LUE DEPUIS LE SERVEUR.
           Mesuré en production : deux sessions ouvertes depuis 49 et 36 jours, jamais clôturées,
           3 818,30 € de mouvements dessous — et rien ne le disait à personne.
           Le serveur calcule `stale` et `open_since_hours` ; l'écran se contente de les montrer,
           pour que le seuil reste réglable à un seul endroit. */
        isStale: (state) => !!(state.session && state.session.stale),
        openSinceHours: (state) => (state.session ? Number(state.session.open_since_hours) || 0 : 0),
    },
    mutations: {
        setCashDrawerSession(state, session) {
            state.session = session || null;
            state.lastLoadedAt = Date.now();
        },
        setMovements(state, list) {
            state.movements = Array.isArray(list) ? list.slice() : [];
        },
        setLoading(state, value) {
            state.loading = !!value;
        },
        setError(state, message) {
            state.error = message || null;
        },
        reset(state) {
            state.session = null;
            state.movements = [];
            state.loading = false;
            state.error = null;
        },
    },
    actions: {
        /**
         * Fetch current OPEN session for the calling user (if any).
         * Returns null when no session is open — UI uses that to prompt
         * "Ouvrir la caisse".
         *
         * [Wave O / O-1 2026-05-20] Accepts optional `branchId` so the
         * global Admin can target the dropdown-selected filiale.
         * Back-compat: payload may be `null`, a plain integer, or `{ branchId }`.
         */
        async loadCurrentSession({ commit }, payload = null) {
            const branchId = typeof payload === 'object' && payload !== null
                ? payload.branchId
                : payload;
            commit('setLoading', true);
            commit('setError', null);
            try {
                const session = await CashDrawerService.currentSession(branchId);
                commit('setCashDrawerSession', session);
                return session;
            } catch (err) {
                const msg = err && err.response && err.response.data && err.response.data.message
                    ? err.response.data.message
                    : (err && err.message) || 'cash_drawer_load_failed';
                commit('setError', msg);
                throw err;
            } finally {
                commit('setLoading', false);
            }
        },
        /**
         * Open a session with the given opening_amount (€).
         *
         * [Wave O / O-1 2026-05-20] Accepts optional `branchId` so the global
         * Admin can target the dropdown-selected filiale (required by the
         * backend for branch_id=0 callers).
         * Back-compat: payload may be a plain Number (legacy callers) or
         * `{ openingAmount, branchId }`.
         */
        async openSession({ commit }, payload) {
            const openingAmount = typeof payload === 'object' && payload !== null
                ? payload.openingAmount
                : payload;
            const branchId = typeof payload === 'object' && payload !== null
                ? payload.branchId
                : null;
            commit('setLoading', true);
            commit('setError', null);
            try {
                const session = await CashDrawerService.openSession(openingAmount, branchId);
                commit('setCashDrawerSession', session);
                commit('setMovements', []);
                return session;
            } catch (err) {
                const msg = err && err.response && err.response.data && err.response.data.message
                    ? err.response.data.message
                    : (err && err.message) || 'cash_drawer_open_failed';
                commit('setError', msg);
                throw err;
            } finally {
                commit('setLoading', false);
            }
        },
        /**
         * Close + auto-reconcile in one shot. Returns the reconciled payload
         * with `expected` and `variance` so the dialog can display them.
         */
        async closeSession({ state, commit }, { closingAmount, varianceReason }) {
            const sessionId = state.session && state.session.id;
            if (!sessionId) {
                commit('setError', 'no_open_session');
                throw new Error('no_open_session');
            }
            commit('setLoading', true);
            commit('setError', null);
            try {
                const result = await CashDrawerService.closeSession(
                    sessionId,
                    closingAmount,
                    varianceReason || null
                );
                // backend returns reconciled session (status=reconciled) — clear
                // local OPEN reference so UI returns to "Ouvrir la caisse" state.
                commit('setCashDrawerSession', null);
                commit('setMovements', []);
                return result;
            } catch (err) {
                const msg = err && err.response && err.response.data && err.response.data.message
                    ? err.response.data.message
                    : (err && err.message) || 'cash_drawer_close_failed';
                commit('setError', msg);
                throw err;
            } finally {
                commit('setLoading', false);
            }
        },
        /**
         * Fetch movements for the current session.
         */
        async loadMovements({ state, commit }) {
            const sessionId = state.session && state.session.id;
            if (!sessionId) {
                commit('setMovements', []);
                return [];
            }
            commit('setLoading', true);
            try {
                const list = await CashDrawerService.movements(sessionId);
                commit('setMovements', list);
                return list;
            } catch (err) {
                const msg = err && err.response && err.response.data && err.response.data.message
                    ? err.response.data.message
                    : (err && err.message) || 'cash_drawer_movements_failed';
                commit('setError', msg);
                throw err;
            } finally {
                commit('setLoading', false);
            }
        },
        reset({ commit }) {
            commit('reset');
        },
    },
};

export default cashDrawer;
