import axios from 'axios'
import appService from "../../services/appService";
import { buildIdempotencyHeaders } from "../../helpers/idempotencyHeaders";


export const kitchenDisplaySystemOrder = {
    namespaced: true,
    state: {
        lists: [],
        orderItems: [],
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        orderItems: function (state) {
            return state.orderItems;
        },
    },
    actions: {
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/kds-order';
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    if (typeof payload.vuex === "undefined" || payload.vuex === true) {
                        context.commit('lists', res.data.data);
                    }
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        changeStatus: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.post(`admin/kds-order/change-status/${payload.id}`, payload, {
                    headers: buildIdempotencyHeaders(payload),
                }).then((res) => {
                    // [SEC-FALSIFY-2026-06-08 KDS-6-02] Refresh with a CLEAN board
                    // payload, not the mutation payload — reusing `payload`
                    // (id/status/expected_status) made the board GET status-filtered
                    // (admin/kds-order?status=7), transiently collapsing the board to
                    // only the new status until the debounced backstop landed.
                    // `{ paginate: 0 }` matches the component's own clean load (search default).
                    context.dispatch("lists", { paginate: 0 }).then().catch();
                    resolve(res);
                }).catch((err) => {
                    if (err.response && err.response.status === 409) {
                        context.dispatch("lists", { paginate: 0 }).catch(() => {});
                        context.dispatch("orderItems").catch(() => {});
                    }
                    reject(err);
                });
            });
        },
        orderItems: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/kds-order/items';
                axios.get(url).then((res) => {
                    context.commit('orderItems', res.data.data);
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
        orderItems: function (state, payload) {
            state.orderItems = payload
        },
    },
}
