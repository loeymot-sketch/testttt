import axios from "axios";

/**
 * [F-016b minimal]
 *
 * Vuex module backing the StockManagerMinimalPage. Wraps the three
 * `/api/admin/stock/*` endpoints with simple promise actions; mirrors
 * the deliveryPlatform module pattern (lists state + axios actions).
 *
 * State shape stays close to the JSON returned by the backend so the
 * page can render without further normalisation:
 *
 *   {
 *     branchId: number|null,
 *     items: [{ id, name, price, is_available, unavailable_reason }],
 *     extras: [{ id, item_id, item_name, name, price, is_available, unavailable_reason }],
 *     variations: [{ id, item_id, item_name, attribute_id, attribute_name, name, ... }],
 *     extrasToggleSupported: bool,
 *     variationsToggleSupported: bool,
 *     audit: [{ id, user_id, branch_id, action, resource, details, created_at }],
 *     generatedAt: ISO string|null,
 *   }
 */
export const stockManager = {
    namespaced: true,
    state: {
        branchId: null,
        items: [],
        extras: [],
        variations: [],
        extrasToggleSupported: false,
        variationsToggleSupported: false,
        audit: [],
        generatedAt: null,
    },
    getters: {
        items: (state) => state.items,
        extras: (state) => state.extras,
        variations: (state) => state.variations,
        audit: (state) => state.audit,
        branchId: (state) => state.branchId,
        generatedAt: (state) => state.generatedAt,
        extrasToggleSupported: (state) => state.extrasToggleSupported,
        variationsToggleSupported: (state) => state.variationsToggleSupported,
    },
    actions: {
        load({ commit }, payload = {}) {
            return new Promise((resolve, reject) => {
                const params = {};
                if (payload.branchId) {
                    params.branch_id = payload.branchId;
                }
                axios
                    .get("admin/stock", { params })
                    .then((res) => {
                        commit("hydrate", res.data || {});
                        resolve(res);
                    })
                    .catch((err) => reject(err));
            });
        },
        toggle(_context, payload) {
            // payload = { entityType, entityId, branchId, isAvailable, reason? }
            return new Promise((resolve, reject) => {
                axios
                    .post("admin/stock/toggle", {
                        entity_type: payload.entityType,
                        entity_id: payload.entityId,
                        branch_id: payload.branchId,
                        is_available: payload.isAvailable,
                        reason: payload.isAvailable ? null : payload.reason || null,
                    })
                    .then((res) => resolve(res))
                    .catch((err) => reject(err));
            });
        },
        loadAudit({ commit }, payload = {}) {
            return new Promise((resolve, reject) => {
                const params = {};
                if (payload.branchId) {
                    params.branch_id = payload.branchId;
                }
                if (payload.limit) {
                    params.limit = payload.limit;
                }
                axios
                    .get("admin/stock/audit", { params })
                    .then((res) => {
                        commit("audit", res.data?.logs || []);
                        resolve(res);
                    })
                    .catch((err) => reject(err));
            });
        },
        // Optimistic in-place update so the UI can flip the switch
        // immediately and roll back if the POST fails.
        applyLocalToggle({ commit }, payload) {
            commit("applyLocalToggle", payload);
        },
    },
    mutations: {
        hydrate(state, payload) {
            state.branchId = payload.branch_id ?? null;
            state.items = Array.isArray(payload.items) ? payload.items : [];
            state.extras = Array.isArray(payload.extras) ? payload.extras : [];
            state.variations = Array.isArray(payload.variations) ? payload.variations : [];
            state.extrasToggleSupported = !!payload.extras_toggle_supported;
            state.variationsToggleSupported = !!payload.variations_toggle_supported;
            state.generatedAt = payload.generated_at ?? null;
        },
        audit(state, logs) {
            state.audit = Array.isArray(logs) ? logs : [];
        },
        applyLocalToggle(state, payload) {
            const collection = pickCollection(state, payload.entityType);
            if (!collection) return;
            const idx = collection.findIndex((row) => row.id === payload.entityId);
            if (idx === -1) return;
            collection[idx] = {
                ...collection[idx],
                is_available: !!payload.isAvailable,
                unavailable_reason: payload.isAvailable ? null : payload.reason || null,
            };
        },
    },
};

function pickCollection(state, entityType) {
    if (entityType === "item") return state.items;
    if (entityType === "extra") return state.extras;
    if (entityType === "variation") return state.variations;
    return null;
}
