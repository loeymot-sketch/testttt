import {
    listIngredients,
    toggleIngredientAvailability,
} from '../../services/ingredientService';

function extractList(response) {
    return Array.isArray(response?.data?.data) ? response.data.data : [];
}

function extractError(error) {
    return error?.response?.data?.message || error?.message || 'Unable to load ingredients.';
}

export const ingredients = {
    namespaced: true,
    state: {
        list: [],
        loading: false,
        error: null,
        lastTogglingGlobalId: null,
    },
    getters: {
        byType: (state) => (type) => state.list.filter((ingredient) => ingredient.type === type),
        usedByCount: (state) => (globalId) => {
            const found = state.list.find((ingredient) => ingredient.global_id === globalId);
            return found ? Number(found.used_by_count || 0) : 0;
        },
        byGlobalId: (state) => (globalId) => state.list.find((ingredient) => ingredient.global_id === globalId) || null,
    },
    actions: {
        async fetch({ commit }, params = {}) {
            commit('setLoading', true);
            commit('setError', null);

            try {
                const response = await listIngredients(params);
                commit('setList', extractList(response));
                return response;
            } catch (error) {
                commit('setError', extractError(error));
                throw error;
            } finally {
                commit('setLoading', false);
            }
        },
        async toggleAvailability({ commit, state }, { globalId, isAvailable, reason = null }) {
            const previous = state.list.find((ingredient) => ingredient.global_id === globalId);
            const snapshot = previous ? { ...previous } : null;

            commit('setError', null);
            commit('setLastTogglingGlobalId', globalId);
            commit('mutateLocalAvailability', { globalId, isAvailable, reason });

            try {
                const response = await toggleIngredientAvailability(globalId, isAvailable, reason);
                if (response?.data?.data) {
                    commit('replaceOne', response.data.data);
                }
                return response;
            } catch (error) {
                if (snapshot) {
                    commit('replaceOne', snapshot);
                }
                commit('setError', extractError(error));
                throw error;
            } finally {
                commit('setLastTogglingGlobalId', null);
            }
        },
    },
    mutations: {
        setList(state, payload) {
            state.list = Array.isArray(payload) ? payload : [];
        },
        setLoading(state, payload) {
            state.loading = Boolean(payload);
        },
        setError(state, payload) {
            state.error = payload;
        },
        setLastTogglingGlobalId(state, payload) {
            state.lastTogglingGlobalId = payload;
        },
        mutateLocalAvailability(state, { globalId, isAvailable, reason = null }) {
            state.list = state.list.map((ingredient) => {
                if (ingredient.global_id !== globalId) return ingredient;

                return {
                    ...ingredient,
                    is_available: Boolean(isAvailable),
                    unavailable_reason: isAvailable ? null : reason,
                };
            });
        },
        replaceOne(state, payload) {
            if (!payload?.global_id) return;

            state.list = state.list.map((ingredient) => (
                ingredient.global_id === payload.global_id ? payload : ingredient
            ));
        },
    },
};
