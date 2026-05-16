import axios from "axios";
import appService from "../../services/appService";

/**
 * [Wave F F-2 / Sprint 1C] Payment terminal Vuex store.
 *
 * CRUD axé sur /admin/payment-terminals (REST).
 * Suit le pattern diningTable / paymentGateway (namespaced, lists/show/temp).
 */
export const paymentTerminal = {
    namespaced: true,
    state: {
        lists: [],
        page: {},
        pagination: [],
        show: {},
        temp: {
            temp_id: null,
            isEditing: false,
        },
    },

    getters: {
        lists: (state) => state.lists,
        pagination: (state) => state.pagination,
        page: (state) => state.page,
        show: (state) => state.show,
        temp: (state) => state.temp,
    },

    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/payment-terminals";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit("lists", res.data.data);
                        context.commit("page", res.data.meta);
                        context.commit("pagination", res.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        save: function (context, payload) {
            return new Promise((resolve, reject) => {
                let method = axios.post;
                let url = "/admin/payment-terminals";
                if (this.state.paymentTerminal.temp.isEditing) {
                    method = axios.put;
                    url = `/admin/payment-terminals/${this.state.paymentTerminal.temp.temp_id}`;
                }
                method(url, payload.form).then((res) => {
                    context.dispatch("lists", payload.search).then().catch();
                    context.commit("reset");
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        edit: function (context, payload) {
            context.commit("temp", payload);
        },
        destroy: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.delete(`admin/payment-terminals/${payload.id}`).then((res) => {
                    context.dispatch("lists", payload.search).then().catch();
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`admin/payment-terminals/${payload}`).then((res) => {
                    context.commit("show", res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        reset: function (context) {
            context.commit("reset");
        },
    },

    mutations: {
        lists: function (state, payload) {
            state.lists = payload;
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        page: function (state, payload) {
            if (typeof payload !== "undefined" && payload !== null) {
                state.page = {
                    from: payload.from,
                    to: payload.to,
                    total: payload.total,
                };
            }
        },
        show: function (state, payload) {
            state.show = payload;
        },
        temp: function (state, payload) {
            state.temp.temp_id = payload;
            state.temp.isEditing = true;
        },
        reset: function (state) {
            state.temp.temp_id = null;
            state.temp.isEditing = false;
            state.show = {};
        },
    },
};
