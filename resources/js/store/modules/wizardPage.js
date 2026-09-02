import axios from 'axios';

/**
 * Bibliothèque de pages de wizard : les listes de choix (avec prix) que les catégories réutilisent.
 * Une page « de bibliothèque » est partagée ; une page « personnalisée » appartient à une catégorie.
 */
export const wizardPage = {
    namespaced: true,
    state: {
        lists: [],
        show: null,
        loading: false,
        error: '',
    },
    getters: {
        lists: (state) => state.lists,
        show: (state) => state.show,
        loading: (state) => state.loading,
        error: (state) => state.error,
        library: (state) => state.lists.filter((page) => page.is_library),
    },
    actions: {
        /**
         * [2026-09-02] L'échec était avalé : sur 403 / 500 / réseau coupé, l'état restait vide et la
         * modale affichait « Aucune page enregistrée » alors que la bibliothèque en contenait vingt.
         * On mémorise l'erreur pour que l'écran puisse la dire, et on la relance à l'appelant.
         */
        lists(context, payload = {}) {
            context.commit('loading', true);
            context.commit('error', '');
            const query = payload.category_id ? `?category_id=${payload.category_id}` : '';
            return axios.get(`admin/composer/wizard-pages${query}`)
                .then((res) => {
                    context.commit('lists', res.data.data || []);
                    return res;
                })
                .catch((err) => {
                    context.commit('error', err?.response?.data?.message
                        || "Impossible de charger les pages de wizard. Vérifiez votre connexion, puis réessayez.");
                    throw err;
                })
                .finally(() => context.commit('loading', false));
        },
        show(context, id) {
            return axios.get(`admin/composer/wizard-pages/${id}`).then((res) => {
                context.commit('show', res.data.data);
                return res;
            });
        },
        save(context, { id = null, form }) {
            const request = id
                ? axios.put(`admin/composer/wizard-pages/${id}`, form)
                : axios.post('admin/composer/wizard-pages', form);
            return request.then((res) => {
                context.commit('show', res.data.data);
                return res;
            });
        },
        destroy(context, id) {
            return axios.delete(`admin/composer/wizard-pages/${id}`);
        },
        duplicateForCategory(context, { id, categoryId }) {
            return axios.post(`admin/composer/wizard-pages/${id}/duplicate-for-category/${categoryId}`)
                .then((res) => res.data.data);
        },
    },
    mutations: {
        lists(state, lists) {
            state.lists = Array.isArray(lists) ? lists : [];
        },
        show(state, page) {
            state.show = page;
        },
        loading(state, loading) {
            state.loading = loading;
        },
        error(state, message) {
            state.error = message || '';
        },
    },
};
