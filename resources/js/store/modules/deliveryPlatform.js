import axios from "axios";
import appService from "../../services/appService";

/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * Vuex module for the admin "Delivery Platforms" page.
 * Mirrors the kioskMachine module to stay aligned with the rest
 * of the admin codebase, even though the page mostly uses axios
 * directly for non-list mutations (toggle / save).
 */
export const deliveryPlatform = {
    namespaced: true,
    state: {
        lists: [],
        show: {},
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        show: function (state) {
            return state.show;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/delivery-platforms";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios
                    .get(url)
                    .then((res) => {
                        if (
                            typeof payload === "undefined" ||
                            typeof payload.vuex === "undefined" ||
                            payload.vuex === true
                        ) {
                            context.commit("lists", res.data.data || []);
                        }
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .get(`admin/delivery-platforms/${payload.id}`)
                    .then((res) => {
                        context.commit("show", res.data.data || {});
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        update: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .put(`admin/delivery-platforms/${payload.id}`, payload.form)
                    .then((res) => {
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        toggle: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios
                    .post(`admin/delivery-platforms/${payload.id}/toggle`)
                    .then((res) => {
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload || [];
        },
        show: function (state, payload) {
            state.show = payload || {};
        },
    },
};
