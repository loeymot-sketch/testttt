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
    },
};
