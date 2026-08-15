import axios from "axios";
import appService from "../../services/appService";

export const dashboard = {
    namespaced: true,
    state: {
        totalSales: [],
        totalOrders: [],
        totalCustomers: [],
        totalMenuItems: [],
        orderStatistics: [],
        orderSummary: [],
        salesSummary: [],
        customerStates: [],
        featuredItems: [],
        mostPopularItems: [],
        topCustomers: [],
        // Phase 6 - Boss Dashboard
        realtimeReport: {},
        slaAlerts: [],
        channelStatistics: [],
        auditTrail: [],
    },

    getters: {
        totalSales: function (state) {
            return state.totalSales;
        },
        totalOrders: function (state) {
            return state.totalOrders;
        },
        totalCustomers: function (state) {
            return state.totalCustomers;
        },
        totalMenuItems: function (state) {
            return state.totalMenuItems;
        },
        orderStatistics: function (state) {
            return state.orderStatistics;
        },
        orderSummary: function (state) {
            return state.orderSummary;
        },
        salesSummary: function (state) {
            return state.salesSummary;
        },
        customerStates: function (state) {
            return state.customerStates;
        },
        featuredItems: function (state) {
            return state.featuredItems;
        },
        mostPopularItems: function (state) {
            return state.mostPopularItems;
        },
        topCustomers: function (state) {
            return state.topCustomers;
        },
        realtimeReport: function (state) {
            return state.realtimeReport;
        },
        slaAlerts: function (state) {
            return state.slaAlerts;
        },
        channelStatistics: function (state) {
            return state.channelStatistics;
        },
        auditTrail: function (state) {
            return state.auditTrail;
        },
    },

    actions: {
        // [T-5.2 CUMUL-NON-DATE 2026-08-15] period optionnel (défaut absent =
        // comportement historique inchangé côté serveur).
        totalSales: function (context, period) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/total-sales", { params: period ? { period } : {} })
                    .then((res) => {
                        context.commit("totalSales", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        totalOrders: function (context, period) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/total-orders", { params: period ? { period } : {} })
                    .then((res) => {
                        context.commit("totalOrders", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        totalCustomers: function (context) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/total-customers")
                    .then((res) => {
                        context.commit("totalCustomers", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        totalMenuItems: function (context) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/total-menu-items")
                    .then((res) => {
                        context.commit("totalMenuItems", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        orderStatistics: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/dashboard/order-statistics";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("orderStatistics", res.data.data);
                    resolve(res);
                })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        orderSummary: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/dashboard/order-summary";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("orderSummary", res.data.data);
                    resolve(res);
                })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        salesSummary: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/dashboard/sales-summary";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("salesSummary", res.data.data);
                    resolve(res);
                })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        customerStates: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = "admin/dashboard/customer-states";
                if (payload) {
                    url = url + appService.requestHandler(payload);
                }
                axios.get(url).then((res) => {
                    context.commit("customerStates", res.data.data);
                    resolve(res);
                })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        featuredItems: function (context) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/featured-items")
                    .then((res) => {
                        context.commit("featuredItems", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        mostPopularItems: function (context) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/popular-items")
                    .then((res) => {
                        context.commit("mostPopularItems", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        topCustomers: function (context) {
            return new Promise((resolve, reject) => {
                axios
                    .get("admin/dashboard/top-customers")
                    .then((res) => {
                        context.commit("topCustomers", res.data.data);
                        resolve(res);
                    })
                    .catch((err) => {
                        reject(err);
                    });
            });
        },
        realtimeReport: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("admin/dashboard/realtime-report").then((res) => {
                    context.commit("realtimeReport", res.data.data);
                    resolve(res);
                }).catch((err) => { reject(err); });
            });
        },
        slaAlerts: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("admin/dashboard/sla-alerts").then((res) => {
                    context.commit("slaAlerts", res.data.data);
                    resolve(res);
                }).catch((err) => { reject(err); });
            });
        },
        channelStatistics: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("admin/dashboard/channel-statistics").then((res) => {
                    context.commit("channelStatistics", res.data.data);
                    resolve(res);
                }).catch((err) => { reject(err); });
            });
        },
        auditTrail: function (context) {
            return new Promise((resolve, reject) => {
                axios.get("admin/dashboard/audit-trail").then((res) => {
                    context.commit("auditTrail", res.data.data);
                    resolve(res);
                }).catch((err) => { reject(err); });
            });
        },
    },

    mutations: {
        totalSales: function (state, payload) {
            state.totalSales = payload;
        },
        totalOrders: function (state, payload) {
            state.totalOrders = payload;
        },
        totalCustomers: function (state, payload) {
            state.totalCustomers = payload;
        },
        totalMenuItems: function (state, payload) {
            state.totalMenuItems = payload;
        },
        orderStatistics: function (state, payload) {
            state.orderStatistics = payload;
        },
        orderSummary: function (state, payload) {
            state.orderSummary = payload;
        },
        salesSummary: function (state, payload) {
            state.salesSummary = payload;
        },
        customerStates: function (state, payload) {
            state.customerStates = payload;
        },
        featuredItems: function (state, payload) {
            state.featuredItems = payload;
        },
        mostPopularItems: function (state, payload) {
            state.mostPopularItems = payload;
        },
        topCustomers: function (state, payload) {
            state.topCustomers = payload;
        },
        realtimeReport: function (state, payload) {
            state.realtimeReport = payload;
        },
        slaAlerts: function (state, payload) {
            state.slaAlerts = payload;
        },
        channelStatistics: function (state, payload) {
            state.channelStatistics = payload;
        },
        auditTrail: function (state, payload) {
            state.auditTrail = payload;
        },
    },
};
