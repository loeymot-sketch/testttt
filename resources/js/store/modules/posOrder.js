import axios from 'axios'
import appService from "../../services/appService";
import { buildIdempotencyHeaders } from "../../helpers/idempotencyHeaders";

const DEFAULT_REALTIME_POLLING_INTERVAL_MS = 30000;
const POS_SELF_ORDER_TYPES = [15, 20];

function normalizeBoolean(value, defaultValue = false) {
    if (typeof value === 'boolean') {
        return value;
    }
    if (value === 1 || value === '1') {
        return true;
    }
    if (value === 0 || value === '0') {
        return false;
    }
    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();
        if (['true', 'yes', 'on'].includes(normalized)) {
            return true;
        }
        if (['false', 'no', 'off', 'null', 'none'].includes(normalized)) {
            return false;
        }
    }
    return defaultValue;
}

function normalizePositiveInteger(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? Math.round(number) : fallback;
}

function readProcessEnv(key) {
    return typeof process !== 'undefined' && process?.env ? process.env[key] : undefined;
}

export function resolveRealtimeFallbackConfig(runtime = {}) {
    const windowConfig = typeof window !== 'undefined' ? (window.foodkingConfig || {}) : {};
    const realtimeConfig = windowConfig.realtime || {};
    const broadcastDriver = String(
        runtime.broadcastDriver
        ?? runtime.driver
        ?? realtimeConfig.broadcastDriver
        ?? readProcessEnv('MIX_BROADCAST_DRIVER')
        ?? readProcessEnv('BROADCAST_DRIVER')
        ?? ''
    ).trim().toLowerCase();
    const normalizedDriver = broadcastDriver || 'null';
    const pusherKey = runtime.pusherKey
        ?? realtimeConfig.pusherAppKey
        ?? readProcessEnv('MIX_PUSHER_APP_KEY')
        ?? '';
    const hintWhenOff = normalizeBoolean(
        runtime.hintWhenOff ?? realtimeConfig.hintWhenOff,
        true
    );
    // [HEAL B.3 2026-05-19] POS polling cadence reads MIX_BROADCAST_POLLING_FALLBACK_MS
    // (webpack build-time env, baked at `npm run prod`) — NOT the PHP config
    // (broadcasting.polling_fallback.interval_ms was deleted as dead-weight).
    // Per-surface SoT: this constant is the POS-specific value; KDS and Kiosk
    // own their own constants (see config/broadcasting.php note). RED-Z3 §B-6 closed.
    const pollingIntervalMs = normalizePositiveInteger(
        runtime.pollingIntervalMs
        ?? realtimeConfig.pollingFallbackMs
        ?? readProcessEnv('MIX_BROADCAST_POLLING_FALLBACK_MS'),
        DEFAULT_REALTIME_POLLING_INTERVAL_MS
    );
    const websocketEnabled = normalizedDriver === 'pusher' && String(pusherKey || '').length > 0;
    const pollingOnly = !websocketEnabled;

    return {
        driver: normalizedDriver,
        websocketEnabled,
        pollingOnly,
        pollingIntervalMs,
        hint: pollingOnly && hintWhenOff
            ? `Temps reel indisponible: rafraichissement automatique toutes les ${Math.round(pollingIntervalMs / 1000)}s.`
            : null,
    };
}

export function normalizeRealtimeOrderEvent(event = {}) {
    const payload = event && event.payload ? event.payload : event || {};

    return {
        orderId: payload.order_id || payload.id || null,
        queueNumber: payload.queue_number || null,
        origin: payload._origin || payload.source_surface || null,
        paymentMethod: payload.payment_method ?? payload.pos_payment_method ?? null,
        orderType: payload.order_type ?? null,
        payload,
    };
}

// [PS-2 audit 2026-05-18 / PK-2 propagation 2026-05-18]
// Idempotency-Key helper extracted to resources/js/helpers/idempotencyHeaders.js
// so all 6+ Vue stores (POS + KDS + online + table + frontend) share the same
// generator. See that file for behaviour rationale.

export function shouldNotifyPosRealtimeOrder(event = {}) {
    const normalized = normalizeRealtimeOrderEvent(event);
    const origin = String(normalized.origin || '').trim().toLowerCase();

    if (origin === 'pos') {
        return false;
    }

    const orderType = parseInt(normalized.orderType, 10);
    if (!Number.isNaN(orderType) && POS_SELF_ORDER_TYPES.includes(orderType)) {
        return false;
    }

    return true;
}

export const posOrder = {
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
        temp: {
            temp_id: null,
            isEditing: false,
        },
        realtimeFallback: resolveRealtimeFallbackConfig(),
    },
    getters: {
        lists: function (state) {
            return state.lists;
        },

        pagination: function (state) {
            return state.pagination
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
        temp: function (state) {
            return state.temp;
        },
        realtimeFallback: function (state) {
            return state.realtimeFallback;
        },
        realtimeFallbackHint: function (state) {
            return state.realtimeFallback?.hint || null;
        }
    },
    actions: {
        refreshRealtimeFallback: function (context, payload = {}) {
            const config = resolveRealtimeFallbackConfig(payload);
            context.commit('realtimeFallback', config);
            return config;
        },
        lists: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/pos-order';
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
        save: function (context, payload) {
            return new Promise((resolve, reject) => {
                const idempotencyKey = payload?.idempotency_key
                    || crypto.randomUUID?.()
                    || (`pos-${Date.now()}-${Math.random().toString(36).slice(2)}`);
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 30000);
                const config = {
                    headers: { 'X-Idempotency-Key': idempotencyKey },
                    signal: controller.signal,
                };
                axios.post("admin/pos", payload, config).then((res) => {
                    clearTimeout(timeoutId);
                    resolve(res);
                }).catch((err) => {
                    clearTimeout(timeoutId);
                    if (err?.code === 'ERR_CANCELED' || err?.name === 'CanceledError') {
                        reject({ _paymentTimeout: true, message: 'Délai de paiement dépassé (30s). Ne relancez pas — vérifiez le statut.' });
                    } else if (err?.response?.status === 409 && err?.response?.headers?.['idempotency-key-conflict']) {
                        // [RED-R1 P2 / Q-13-2] Idempotency-Key conflict — same key, different payload.
                        // Cashier-friendly toast: order may already be in ticker, do NOT retry blindly.
                        reject({ _idempotencyConflict: true, message: 'Commande déjà enregistrée — vérifier le ticker avant de relancer.' });
                    } else {
                        reject(err);
                    }
                });
            });
        },
        show: function (context, payload) {
            return new Promise((resolve, reject) => {
                axios.get(`admin/pos-order/show/${payload}`).then((res) => {
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
        destroy: function (context, payload) {
            return new Promise((resolve, reject) => {
                // [PS-2 audit 2026-05-18] Idempotency-Key on DELETE — pairs with
                // server-side `idempotency` middleware on admin/pos-order/{order}
                // (routes/api.php:885). Defense-in-depth against double-tap.
                axios.delete(`admin/pos-order/${payload.id}`, {
                    headers: buildIdempotencyHeaders(payload),
                }).then((res) => {
                    context.dispatch('lists', payload.search).then().catch();
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        changeStatus: function (context, payload) {
            return new Promise((resolve, reject) => {
                // [PS-2 audit 2026-05-18] Idempotency-Key on POST change-status
                // (routes/api.php:892 has the middleware — wired but client did
                // not supply a key so replay protection was inert).
                axios.post(`admin/pos-order/change-status/${payload.id}`, payload, {
                    headers: buildIdempotencyHeaders(payload),
                }).then((res) => {
                    context.commit('show', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        changePaymentStatus: function (context, payload) {
            return new Promise((resolve, reject) => {
                // [PS-2 audit 2026-05-18] Idempotency-Key on POST change-payment-status
                // (routes/api.php:895).
                axios.post(`admin/pos-order/change-payment-status/${payload.id}`, payload, {
                    headers: buildIdempotencyHeaders(payload),
                }).then((res) => {
                    context.commit('show', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        selectDeliveryBoy: function (context, payload) {
            return new Promise((resolve, reject) => {
                // [PS-2 audit 2026-05-18] Idempotency-Key on POST select-delivery-boy
                // (routes/api.php:897).
                axios.post(`admin/pos-order/select-delivery-boy/${payload.id}`, payload, {
                    headers: buildIdempotencyHeaders(payload),
                }).then((res) => {
                    context.commit('show', res.data.data);
                    resolve(res);
                }).catch((err) => {
                    reject(err);
                });
            });
        },
        reset: function (context) {
            context.commit('reset');
        },
        export: function (context, payload) {
            return new Promise((resolve, reject) => {
                let url = 'admin/pos-order/export';
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
    },
    mutations: {
        lists: function (state, payload) {
            state.lists = payload
        },
        pagination: function (state, payload) {
            state.pagination = payload;
        },
        page: function (state, payload) {
            if (typeof payload !== "undefined" && payload !== null) {
                state.page = {
                    from: payload.from,
                    to: payload.to,
                    total: payload.total
                }
            }
        },
        show: function (state, payload) {
            state.show = payload;
        },
        orderItems: function (state, payload) {
            state.orderItems = payload;
        },
        orderBranch: function (state, payload) {
            state.orderBranch = payload;
        },
        orderUser: function (state, payload) {
            state.orderUser = payload;
        },
        orderAddress: function (state, payload) {
            state.orderAddress = payload;
        },
        reset: function (state) {
            state.temp.temp_id = null;
            state.temp.isEditing = false;
        },
        realtimeFallback: function (state, payload) {
            state.realtimeFallback = resolveRealtimeFallbackConfig(payload);
        }
    },
}
