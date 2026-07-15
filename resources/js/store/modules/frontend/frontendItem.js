import axios from "axios";
import appService from "../../../services/appService";

export const frontendItem = {
    namespaced: true,
    state: {
        lists: [],
        featured: [],
        popular: {},
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        featured: function (state) {
            return state.featured;
        },
        popular: function (state) {
            return state.popular;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'frontend/item';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if(typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit('lists', res.data.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        featured: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "frontend/item/featured-items";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("featured", res.data.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        popular: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "frontend/item/popular-items";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("popular", res.data.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        details: function (context, payload) {
            return new Promise((resolve, reject) => {
                // Accept either a plain ID (legacy) or { id, surface } object
                const id = (typeof payload === 'object' && payload !== null) ? payload.id : payload;
                const surface = (typeof payload === 'object' && payload !== null) ? payload.surface : null;
                let url = `frontend/item/details/${id}`;
                const params = surface ? { surface } : {};
                // [BRAIN-SUPERVISOR 2026-07-15 / P2] Le backend n'active la dispo
                // PAR BRANCHE (rupture 86) que si branch_id est envoyé
                // (FrontendItemController::itemDetails). Le wizard borne (frozen,
                // non modifiable) n'envoyait rien → cécité mid-wizard à la rupture.
                // Injection ici, côté store non-frozen : branch de la borne
                // (kioskCart.branchId) quand la surface est kiosk.
                const branchId = (typeof payload === 'object' && payload !== null && payload.branchId)
                    ? payload.branchId
                    : (surface === 'kiosk' ? (context.rootState?.kioskCart?.branchId ?? null) : null);
                if (branchId) params.branch_id = branchId;
                axios.get(url, { params }).then((res) => {
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
        featured: function (state, payload) {
            state.featured = payload;
        },
        popular: function (state, payload) {
            state.popular = payload;
        }
    },
};
