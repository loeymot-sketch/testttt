import axios from 'axios';

export const composer = {
    namespaced: true,
    state: {
        profile: null,
        loading: false,
    },
    getters: {
        profile: (state) => state.profile,
        loading: (state) => state.loading,
    },
    actions: {
        show(context, { itemId, branchIdScope = null }) {
            context.commit('loading', true);
            const query = branchIdScope ? `?branch_id_scope=${branchIdScope}` : '';
            return axios.get(`admin/composer/items/${itemId}/profile${query}`)
                .then((res) => {
                    context.commit('profile', res.data.data);
                    return res;
                })
                .finally(() => context.commit('loading', false));
        },
        save(context, { itemId, payload }) {
            context.commit('loading', true);
            return axios.post(`admin/composer/items/${itemId}/profile`, payload)
                .then((res) => {
                    context.commit('profile', res.data.data);
                    return res;
                })
                .finally(() => context.commit('loading', false));
        },
        publish(context, profileId) {
            return axios.post(`admin/composer/profiles/${profileId}/publish`)
                .then((res) => {
                    context.commit('profile', res.data.data);
                    return res;
                });
        },
        unpublish(context, profileId) {
            return axios.post(`admin/composer/profiles/${profileId}/unpublish`)
                .then((res) => {
                    context.commit('profile', res.data.data);
                    return res;
                });
        },
    },
    mutations: {
        profile(state, profile) {
            state.profile = profile;
        },
        loading(state, loading) {
            state.loading = loading;
        },
    },
};
