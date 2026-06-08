// [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] Store module for the unified
// /admin/historique page. Read-only list + detail over the new
// `admin/order-history` endpoint (OrderHistoryController -> OrderService::list).
// Mirrors the posOrder module shape so the list component reuses the same
// getters (lists / pagination / page / show). No source filter is forced
// here — the page passes optional filters (origin/status/payment/date).
import appService from "../../services/appService";

export const orderHistory = {
    namespaced: true,
    state: {
        lists: [],
        page: {},
        pagination: [],
        show: {},
        orderItems: {},
        orderBranch: {},
        orderUser: {},
        orderAddress: {},
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        pagination: function (state) {
            return state.pagination;
        },
        page: function (state) {
            return state.page;
        },
        show: function (state) {
            return state.show;
        },
        orderItems: function (state) {
            return state.orderItems;
        },
        orderBranch: function (state) {
            return state.orderBranch;
        },
        orderUser: function (state) {
            return state.orderUser;
        },
        orderAddress: function (state) {
            return state.orderAddress;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/order-history';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit('lists', res.data.data);
                        context.commit('page', res.data.meta);
                        context.commit('pagination', res.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        // [W4.4 2026-06-08] Read-only XLSX export of the unified history,
        // honoring the current page filters. Mirrors posOrder/export (blob).
        export: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/order-history/export';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url, { responseType: 'blob' }).then((res) => {
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`admin/order-history/show/${payload}`).then((res) => {
                    context.commit('show', res.data.data);
                    context.commit("orderItems", res.data.data.order_items);
                    context.commit("orderBranch", res.data.data.branch);
                    context.commit("orderUser", res.data.data.user);
                    context.commit("orderAddress", res.data.data.order_address);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
    },
    mutations: {
        lists: function (state, lists) {
            state.lists = lists;
        },
        page: function (state, page) {
            state.page = page;
        },
        pagination: function (state, pagination) {
            state.pagination = pagination;
        },
        show: function (state, show) {
            state.show = show;
        },
        orderItems: function (state, orderItems) {
            state.orderItems = orderItems;
        },
        orderBranch: function (state, orderBranch) {
            state.orderBranch = orderBranch;
        },
        orderUser: function (state, orderUser) {
            state.orderUser = orderUser;
        },
        orderAddress: function (state, orderAddress) {
            state.orderAddress = orderAddress;
        },
    },
};
