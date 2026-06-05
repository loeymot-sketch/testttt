import axios from 'axios';

function generateIdempotencyToken() {
    if (globalThis.crypto && typeof globalThis.crypto.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    return `park-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function buildParkedOrderSnapshot(rootGetters, options = {}) {
    const lists = rootGetters['posCart/lists'] || [];
    const subtotal = Number(rootGetters['posCart/subtotal'] || 0);
    const discount = Number(rootGetters['posCart/discount'] || 0);
    const deliveryCharge = Number(options.delivery_charge || 0);

    return {
        lists,
        subtotal,
        discount,
        total: subtotal + deliveryCharge - discount,
        checkout_form: options.checkout_form || null,
        selected_address: options.selected_address || null,
        delivery_inline: options.delivery_inline || null,
        parked_at: new Date().toISOString(),
    };
}

function sortParkedOrders(list) {
    return [...(Array.isArray(list) ? list : [])].sort((a, b) => {
        const left = new Date(b?.created_at || 0).getTime();
        const right = new Date(a?.created_at || 0).getTime();

        return left - right;
    });
}

export const posParked = {
    namespaced: true,
    state: {
        list: [],
        lastRestoredPayload: null,
    },
    getters: {
        list(state) {
            return state.list;
        },
        count(state) {
            return Array.isArray(state.list) ? state.list.length : 0;
        },
        lastRestoredPayload(state) {
            return state.lastRestoredPayload;
        },
    },
    actions: {
        fetchList(context) {
            return axios.get('admin/pos/parked-orders').then((response) => {
                context.commit('SET_LIST', response.data?.data || []);

                return response;
            });
        },
        park(context, payload = {}) {
            const snapshot = payload.snapshot || buildParkedOrderSnapshot(context.rootGetters, payload);
            const idempotencyToken = payload.idempotencyToken || generateIdempotencyToken();

            return axios.post('admin/pos/parked-orders', {
                payload: snapshot,
                label: payload.label || null,
                idempotency_token: idempotencyToken,
            }).then((response) => {
                context.commit('UPSERT_PARKED', response.data?.data);

                return {
                    ...response,
                    idempotencyToken,
                };
            });
        },
        async recall(context, id) {
            const response = await axios.get(`admin/pos/parked-orders/${id}`);
            const parkedOrder = response.data?.data || {};
            const restoredPayload = parkedOrder.payload_json || {};

            await context.dispatch('posCart/resetCart', null, { root: true });

            const restoredLines = Array.isArray(restoredPayload.lists) ? restoredPayload.lists : [];

            if (restoredLines.length > 0) {
                await context.dispatch('posCart/lists', restoredLines, { root: true });
            }

            await context.dispatch('posCart/discount', restoredPayload.discount || 0, { root: true });

            // [V14 C-α / FINDING C-1 P1] After restore, prune lines whose item is now
            // unavailable in the live catalog (item became 86'd while parked).
            // Without this, recall could silently restore a "polluted" cart and
            // the operator only discovers it at checkout (422 on submit).
            try {
                const catalog = (context.rootGetters['item/lists'] || context.rootState?.item?.lists || []);
                if (Array.isArray(catalog) && catalog.length > 0) {
                    const availableIds = new Set();
                    const unknownTreatedAsAvailable = true; // backward-safe : keep lines for items we can't find in catalog
                    catalog.forEach((it) => {
                        if (it && it.id != null && it.is_available !== false && it.is_available !== 0) {
                            availableIds.add(parseInt(it.id, 10));
                        }
                    });
                    const seenIds = new Set();
                    catalog.forEach((it) => { if (it && it.id != null) seenIds.add(parseInt(it.id, 10)); });
                    const purgedItemIds = [];
                    restoredLines.forEach((line) => {
                        const id = parseInt(line && line.item_id, 10);
                        if (!id) return;
                        const knownInCatalog = seenIds.has(id);
                        const stillAvailable = availableIds.has(id);
                        if (knownInCatalog && !stillAvailable) {
                            purgedItemIds.push(id);
                        }
                        // unknownTreatedAsAvailable=true → if !knownInCatalog, keep the line
                        // (catalog may be paginated / not yet loaded)
                    });
                    purgedItemIds.forEach((itemId) => {
                        context.dispatch('posCart/pruneUnavailable', itemId, { root: true });
                    });
                    restoredPayload._recall_purged_item_ids = purgedItemIds;
                }
            } catch (_e) { /* defensive: never block recall on catalog inspection */ }

            context.commit('REMOVE_PARKED', parkedOrder.id || id);
            context.commit('SET_LAST_RESTORED_PAYLOAD', restoredPayload);

            // [M7-02] Carry the backend's unavailable-variation warnings (built by
            // PosParkedOrderService) so the caller can surface them — the cashier MUST
            // know the restored cart differs from what was parked (silent drops were
            // discovered only at checkout). Combined with the client-side
            // _recall_purged_item_ids (item-level 86'd lines) computed above.
            restoredPayload._recall_warnings = parkedOrder.warnings || null;

            return restoredPayload;
        },
        discard(context, id) {
            return axios.delete(`admin/pos/parked-orders/${id}`).then((response) => {
                context.commit('REMOVE_PARKED', id);

                return response;
            });
        },
    },
    mutations: {
        SET_LIST(state, payload) {
            state.list = sortParkedOrders(payload);
        },
        UPSERT_PARKED(state, payload) {
            if (!payload || payload.id == null) {
                return;
            }

            const list = state.list.filter((entry) => entry.id !== payload.id);
            list.unshift(payload);
            state.list = sortParkedOrders(list);
        },
        REMOVE_PARKED(state, id) {
            state.list = state.list.filter((entry) => entry.id !== id);
        },
        SET_LAST_RESTORED_PAYLOAD(state, payload) {
            state.lastRestoredPayload = payload || null;
        },
    },
};
