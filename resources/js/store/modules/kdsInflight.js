/**
 * @FK-ID FK-CV1-KDS-INFLIGHT-OOS-MARKER-001
 * @source RED-R3 F3 + RED-R4 KD11
 *
 * Tracks items that have just been marked unavailable ("86'd") so the KDS
 * surface can flag in-flight tickets (status ACCEPT / PREPARING) that still
 * contain those items — the cuisinier needs a visible warning that a plate
 * being prepared has just become OOS rupture stock.
 *
 * Design contract :
 *  - Per-branch isolation : we only mark an item if `branchId` matches the
 *    KDS branch context (or if the event was global, in which case the
 *    backend already fans out to every active branch via the outbox).
 *  - Lazy TTL purge (10 min) inside getters — no setInterval, immune to
 *    timer leaks across remounts. Avoids false positives on tickets that
 *    have long since been completed.
 *  - Keyed by itemId (string for safety in JSON / Vuex devtools).
 *  - State is plain JS — NOT persisted (volatile, purely runtime warning).
 */

const TTL_MS = 10 * 60 * 1000; // 10 minutes (per RED-R4 KD11 mission spec)

function nowMs() {
    return Date.now();
}

export const kdsInflight = {
    namespaced: true,
    state: () => ({
        /**
         * Map of itemId → { itemId, ts, branchId, reason } for items recently
         * marked unavailable. Read through getters to apply lazy TTL purge.
         */
        recentlyDeavailable: {},
    }),
    getters: {
        /**
         * Returns the live (non-expired) map of recently 86'd items.
         * Lazy purge : entries older than TTL_MS are excluded from the
         * returned object without mutating state (callers may dispatch
         * `purgeExpired` to compact memory if they want).
         */
        liveRecentlyDeavailable: (state) => {
            const now = nowMs();
            const out = {};
            const map = state.recentlyDeavailable || {};
            for (const key of Object.keys(map)) {
                const entry = map[key];
                if (!entry || typeof entry.ts !== 'number') continue;
                if (now - entry.ts < TTL_MS) {
                    out[key] = entry;
                }
            }
            return out;
        },
        /**
         * (orderItem) → boolean : true if `orderItem.item_id` has been
         * marked unavailable within the TTL window. Used by the KDS card
         * template to render the OOS warning badge on in-flight tickets.
         */
        isItemRecentlyDeavailable: (state, getters) => (orderItem) => {
            if (!orderItem) return false;
            const id = orderItem.item_id ?? orderItem.itemId ?? null;
            if (id === null || id === undefined) return false;
            const live = getters.liveRecentlyDeavailable;
            return Object.prototype.hasOwnProperty.call(live, String(id));
        },
        /**
         * (order) → boolean : true if any line of the given order references
         * a recently 86'd item. Used to drive the per-card warning badge.
         */
        orderHasRecentlyDeavailableItem: (state, getters) => (order) => {
            if (!order || !Array.isArray(order.order_items)) return false;
            const isRecent = getters.isItemRecentlyDeavailable;
            return order.order_items.some((line) => isRecent(line));
        },
    },
    mutations: {
        MARK_DEAVAILABLE(state, { itemId, ts, branchId, reason }) {
            const key = String(itemId);
            state.recentlyDeavailable = {
                ...state.recentlyDeavailable,
                [key]: { itemId, ts, branchId: branchId ?? null, reason: reason ?? null },
            };
        },
        UNMARK(state, itemId) {
            const key = String(itemId);
            if (!Object.prototype.hasOwnProperty.call(state.recentlyDeavailable, key)) {
                return;
            }
            const next = { ...state.recentlyDeavailable };
            delete next[key];
            state.recentlyDeavailable = next;
        },
        REPLACE(state, map) {
            state.recentlyDeavailable = { ...(map || {}) };
        },
    },
    actions: {
        /**
         * Mark an item as recently unavailable. No-op if the event is
         * branch-scoped to a different branch than the KDS context.
         *
         * @param {object} payload    - parsed event payload
         * @param {number} payload.itemId
         * @param {?number} payload.branchId
         * @param {?string} payload.reason
         * @param {?number} payload.kdsBranchId - branch context of the KDS surface
         */
        markDeavailable({ commit }, { itemId, branchId = null, reason = null, kdsBranchId = null }) {
            if (itemId === null || itemId === undefined) return;
            // Branch isolation: if event is branch-scoped AND we know the KDS
            // branch context, skip when they don't match.
            if (
                branchId !== null
                && branchId !== undefined
                && kdsBranchId !== null
                && kdsBranchId !== undefined
                && Number(branchId) !== Number(kdsBranchId)
            ) {
                return;
            }
            commit('MARK_DEAVAILABLE', { itemId, ts: nowMs(), branchId, reason });
        },
        /**
         * Compact the map by removing TTL-expired entries. Safe to call from
         * any KDS lifecycle hook; idempotent.
         */
        purgeExpired({ state, commit }) {
            const now = nowMs();
            const map = state.recentlyDeavailable || {};
            const next = {};
            let changed = false;
            for (const key of Object.keys(map)) {
                const entry = map[key];
                if (entry && typeof entry.ts === 'number' && now - entry.ts < TTL_MS) {
                    next[key] = entry;
                } else {
                    changed = true;
                }
            }
            if (changed) {
                commit('REPLACE', next);
            }
        },
        /** Force-clear an item flag (used when an item becomes available again). */
        clearItem({ commit }, itemId) {
            if (itemId === null || itemId === undefined) return;
            commit('UNMARK', itemId);
        },
        /** Test hook : reset the entire map. */
        __reset({ commit }) {
            commit('REPLACE', {});
        },
    },
};

export const KDS_INFLIGHT_TTL_MS = TTL_MS;
