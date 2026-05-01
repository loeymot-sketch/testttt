const STORAGE_BUMPED = 'kds.bumped_items_v1';

export function kdsStatusPayload(order, status) {
    return {
        id: order.id,
        status,
        expected_status: order.status,
    };
}

function loadMap() {
    if (typeof localStorage === 'undefined') {
        return {};
    }
    try {
        return JSON.parse(localStorage.getItem(STORAGE_BUMPED) || '{}');
    } catch {
        return {};
    }
}

function persistMap(map) {
    if (typeof localStorage === 'undefined') {
        return;
    }
    localStorage.setItem(STORAGE_BUMPED, JSON.stringify(map));
}

export const kds = {
    namespaced: true,
    state: {
        bumpedByOrder: loadMap(),
    },
    getters: {
        bumpedItems: (state) => (orderId) => state.bumpedByOrder[orderId] || {},
        isReadyOrder: (state, getters) => (order) => {
            const items = order.order_items || [];
            if (items.length === 0) {
                return false;
            }
            const b = getters.bumpedItems(order.id);
            return items.every((line) => b[line.id] != null);
        },
        bumpTimestamp: (state, getters) => (orderId, itemId) => {
            const b = getters.bumpedItems(orderId);
            return b[itemId] ?? null;
        },
    },
    mutations: {
        REPLACE_BUMPED(state, map) {
            state.bumpedByOrder = { ...map };
        },
    },
    actions: {
        bumpItem({ commit, state }, { orderId, itemId }) {
            const next = { ...state.bumpedByOrder };
            const cur = { ...(next[orderId] || {}) };
            cur[itemId] = Date.now();
            next[orderId] = cur;
            commit('REPLACE_BUMPED', next);
            persistMap(next);
        },
        /**
         * @returns {{ ok: boolean, reason?: string }}
         */
        recallItem({ commit, state }, { orderId, itemId, now = Date.now() }) {
            const b = state.bumpedByOrder[orderId];
            if (!b || b[itemId] == null) {
                return { ok: false, reason: 'not_bumped' };
            }
            if (now - b[itemId] >= 60000) {
                return { ok: false, reason: 'grace_expired' };
            }
            const next = { ...state.bumpedByOrder };
            const cur = { ...next[orderId] };
            delete cur[itemId];
            if (Object.keys(cur).length === 0) {
                delete next[orderId];
            } else {
                next[orderId] = cur;
            }
            commit('REPLACE_BUMPED', next);
            persistMap(next);
            return { ok: true };
        },
    },
};
