import axios from 'axios'
import appService from "../../services/appService";
import { buildIdempotencyHeaders } from "../../helpers/idempotencyHeaders";


export const kitchenDisplaySystemOrder = {
    namespaced: true,
    state: {
        lists: [],
        orderItems: [],
        // [E6 KDS-SCHEDULED 2026-07-20] Commandes programmées à venir —
        // meta.scheduled_upcoming de GET admin/kds-order (lane backend
        // parallèle, peut ne pas encore exister). Toujours un tableau :
        // meta absente/vide ⇒ [] (bandeau masqué, zéro erreur).
        scheduledUpcoming: [],
        // [SIGNAL ANNULATION CUISINE 2026-08-19] Commandes annulées ALORS QU'ELLES ÉTAIENT
        // sur le board — meta.recently_canceled de GET admin/kds-order. Sans ce canal, la
        // carte disparaissait au sondage suivant sans un mot et le plat restait sur le passe.
        // Toujours un tableau : meta absente/inattendue ⇒ [] (bandeau masqué, zéro erreur).
        recentlyCanceled: [],
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },
        orderItems: function (state) {
            return state.orderItems;
        },
        scheduledUpcoming: function (state) {
            return state.scheduledUpcoming;
        },
        recentlyCanceled: function (state) {
            return state.recentlyCanceled;
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
                        // [E6 KDS-SCHEDULED 2026-07-20] Bandeau « programmées à
                        // venir » — commit défensif : tant que la lane backend
                        // n'expose pas meta.scheduled_upcoming (ou forme
                        // inattendue), on committe [] → bandeau masqué, 0 erreur.
                        const meta = res.data && res.data.meta;
                        context.commit(
                            'scheduledUpcoming',
                            meta && Array.isArray(meta.scheduled_upcoming) ? meta.scheduled_upcoming : []
                        );
                        // [SIGNAL ANNULATION CUISINE 2026-08-19] Même commit défensif : un
                        // serveur plus ancien (ou une réponse tronquée) ⇒ [] ⇒ bandeau masqué.
                        context.commit(
                            'recentlyCanceled',
                            meta && Array.isArray(meta.recently_canceled) ? meta.recently_canceled : []
                        );
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
                    context.dispatch("lists", payload).then().catch();
                    resolve(res);
                }).catch((err) => {
                    if (err.response && err.response.status === 409) {
                        context.dispatch("lists", payload).catch(() => {});
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
        scheduledUpcoming: function (state, payload) {
            state.scheduledUpcoming = Array.isArray(payload) ? payload : []
        },
        recentlyCanceled: function (state, payload) {
            state.recentlyCanceled = Array.isArray(payload) ? payload : []
        },
    },
}
