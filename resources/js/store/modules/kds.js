import axios from 'axios';

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
        // [KDS-ITEM-READY-SYNC 2026-09-23] Audit finding #4 : cette pastille ne
        // vivait qu'en localStorage — un second écran/appareil pour la même
        // commande en cours affichait un état différent. La mise à jour locale
        // reste immédiate (retour tactile instantané en cuisine), et un appel
        // serveur best-effort persiste la vérité partagée (idempotent : un
        // rebump ne fait rien côté serveur). Si le réseau échoue momentanément,
        // l'état local optimiste est conservé — le prochain hydrateFromOrders
        // (chaque poll/WS refresh du board) resynchronise depuis le serveur.
        async bumpItem({ commit, state }, { orderId, itemId }) {
            const next = { ...state.bumpedByOrder };
            const cur = { ...(next[orderId] || {}) };
            const alreadyBumpedLocally = cur[itemId] != null;
            if (alreadyBumpedLocally) {
                // Déjà bumpé sur CET écran — pas besoin de re-solliciter le réseau
                // (le serveur est idempotent de toute façon, mais autant ne pas
                // spammer une requête à chaque double-tap accidentel).
                return;
            }
            cur[itemId] = Date.now();
            next[orderId] = cur;
            commit('REPLACE_BUMPED', next);
            persistMap(next);
            try {
                await axios.post(`admin/kds-order/items/${itemId}/bump`, {}, {
                    headers: { 'X-Idempotency-Key': `kds-bump-${itemId}-${Math.floor(Date.now() / 1000)}` },
                });
            } catch {
                // best-effort — voir commentaire ci-dessus.
            }
        },
        /**
         * @returns {{ ok: boolean, reason?: string }}
         */
        async recallItem({ commit, state }, { orderId, itemId, now = Date.now() }) {
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
            try {
                // [robustesse 2026-09-23] Le serveur revérifie la même fenêtre de
                // 60s (KitchenDisplaySystemOrderService::recallItem) — un appel
                // API direct ne peut pas la contourner même si ce client-ci a
                // déjà accepté localement.
                await axios.post(`admin/kds-order/items/${itemId}/recall`, {}, {
                    headers: { 'X-Idempotency-Key': `kds-recall-${itemId}-${Math.floor(Date.now() / 1000)}` },
                });
            } catch {
                // best-effort — voir bumpItem.
            }
            return { ok: true };
        },
        /**
         * [KDS-ITEM-READY-SYNC 2026-09-23] Reconstruit l'état bumpé depuis la
         * vérité serveur (order_items[].kitchen_bumped_at) à chaque
         * rafraîchissement du board (poll/WS) — c'est CE mécanisme, pas
         * seulement l'écriture, qui rend un second écran cohérent avec le
         * premier pour une commande en cours de préparation.
         */
        hydrateFromOrders({ commit, state }, orders) {
            const next = { ...state.bumpedByOrder };
            let changed = false;
            for (const order of orders || []) {
                const items = order.order_items || [];
                const cur = { ...(next[order.id] || {}) };
                let orderChanged = false;
                for (const line of items) {
                    if (line.kitchen_bumped_at) {
                        const ts = new Date(line.kitchen_bumped_at).getTime();
                        if (cur[line.id] !== ts) {
                            cur[line.id] = ts;
                            orderChanged = true;
                        }
                    } else if (cur[line.id] != null) {
                        delete cur[line.id];
                        orderChanged = true;
                    }
                }
                if (orderChanged) {
                    next[order.id] = cur;
                    changed = true;
                }
            }
            if (changed) {
                commit('REPLACE_BUMPED', next);
                persistMap(next);
            }
        },
    },
};
