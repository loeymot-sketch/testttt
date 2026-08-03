import axios from 'axios';

export const itemAvailability = {
    namespaced: true,
    state: {
        pending: {},
        lastError: null,
    },
    mutations: {
        SET_PENDING(state, { itemId, value }) {
            state.pending = { ...state.pending, [itemId]: value };
        },
        SET_ERROR(state, msg) {
            state.lastError = msg;
        },
    },
    actions: {
        async toggle({ commit }, { itemId, branchId, isAvailable, unavailableReason }) {
            commit('SET_PENDING', { itemId, value: true });
            commit('SET_ERROR', null);
            try {
                const res = await axios.post('admin/menu/availability/toggle', {
                    item_id: itemId,
                    branch_id: branchId,
                    is_available: isAvailable,
                    unavailable_reason: unavailableReason ?? null,
                });
                return res.data;
            } catch (err) {
                commit('SET_ERROR', err?.response?.data?.message || err.message);
                throw err;
            } finally {
                commit('SET_PENDING', { itemId, value: false });
            }
        },
        // [GOAL PARITE-SYNC 2026-07-18 / chantier 2 D1] Rupture (86) ciblée d'un
        // EXTRA (sauce/supplément épuisé) depuis le panneau partagé caisse+cuisine.
        // Réutilise l'endpoint backend existant (routes/api.php:287) — aucune
        // logique dupliquée : AvailabilityService::toggleExtra owns lock/idempotence
        // + dispatch ItemExtraAvailabilityChanged (outbox → borne/caisse temps réel).
        // `reason` n'est requis qu'à la mise en rupture (whitelist
        // StockLevel::MANUAL_UNAVAILABLE_REASONS), jamais à la réactivation.
        async toggleExtra({ commit }, { extraId, branchId, isAvailable, reason }) {
            const pendingKey = 'extra-' + extraId;
            commit('SET_PENDING', { itemId: pendingKey, value: true });
            commit('SET_ERROR', null);
            try {
                const payload = {
                    extra_id: extraId,
                    branch_id: branchId,
                    is_available: isAvailable,
                };
                if (!isAvailable) payload.reason = reason || 'out_of_stock_manual';
                const res = await axios.post('admin/menu/availability/extra/toggle', payload);
                return res.data;
            } catch (err) {
                commit('SET_ERROR', err?.response?.data?.message || err.message);
                throw err;
            } finally {
                commit('SET_PENDING', { itemId: pendingKey, value: false });
            }
        },
        // [GOAL PARITE-SYNC 2026-07-18 / chantier 2 D1] Idem pour une VARIATION
        // (ex. une taille/parfum épuisé). Endpoint existant routes/api.php:289.
        async toggleVariation({ commit }, { variationId, branchId, isAvailable, reason }) {
            const pendingKey = 'variation-' + variationId;
            commit('SET_PENDING', { itemId: pendingKey, value: true });
            commit('SET_ERROR', null);
            try {
                const payload = {
                    variation_id: variationId,
                    branch_id: branchId,
                    is_available: isAvailable,
                };
                if (!isAvailable) payload.reason = reason || 'out_of_stock_manual';
                const res = await axios.post('admin/menu/availability/variation/toggle', payload);
                return res.data;
            } catch (err) {
                commit('SET_ERROR', err?.response?.data?.message || err.message);
                throw err;
            } finally {
                commit('SET_PENDING', { itemId: pendingKey, value: false });
            }
        },
    },
};
