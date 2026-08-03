import axios from 'axios'


export const orderStatusScreenOrder = {
    namespaced: true,
    state: {
        lists: [],
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        mostPopularItems: function (state) {
            return state.mostPopularItems;
        },
    },
    actions: {
        // [iter15-mega-fix C-016 2026-05-10] Branch on auth state.
        // - Authenticated admin / POS supervisor: hit `admin/oss-order`
        //   (existing behavior — unfiltered for global Admin, branch-scoped
        //   for branch staff, full permission gate).
        // - Unauthenticated customer wall display
        //   (`/admin/order-status-screen` opened in a fresh public context):
        //   hit the public sibling `frontend/oss-order` so the screen no
        //   longer 401s and renders empty columns.
        // The 4 callers (PreparingAndReadyComponent, PopularItemComponent,
        // OssSyncService, PosComponent dashboard widget) all flow through
        // this single action — branching here keeps each caller agnostic.
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                const isAuthed = !!(context.rootGetters && context.rootGetters['authStatus']);
                const url = isAuthed ? 'admin/oss-order' : 'frontend/oss-order';
                axios.get(url).then((res) => {
                    context.commit('lists', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // [iter15-mega-fix C-016 2026-05-10] Same auth-state branching as
        // `lists` above. The OSS popular-items panel is rendered on the same
        // public wall display, so an unauth caller must hit the public route
        // (`frontend/oss-order/popular-items`) instead of the auth-gated
        // `admin/oss-order/popular-items` (which 401s and leaves the panel
        // empty / spinner-stuck).
        mostPopularItems: function (context, payload) {
            return new Promise((resolve, reject) => {
                const isAuthed = !!(context.rootGetters && context.rootGetters['authStatus']);
                const url = isAuthed
                    ? 'admin/oss-order/popular-items'
                    : 'frontend/oss-order/popular-items';
                axios.get(url).then((res) => {
                    context.commit('mostPopularItems', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload
        },
        mostPopularItems: function (state, payload) {
            state.mostPopularItems = payload
        },
    },
}
