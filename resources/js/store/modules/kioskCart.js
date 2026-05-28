import axios from "axios";
import { saveOrder, getPendingCount, startAutoSync } from "../../helpers/kioskOfflineQueue";
import { isSnapshotStale, loadSnapshot } from "../../helpers/kioskMenuCache";

// Source identique à sourceEnum.WEB (pas de valeur KIOSK côté frontend)
const SOURCE_KIOSK = 5;

const PAYMENT_METHOD_MAP = { cash: 1, card: 4, tr: 5, counter: 6 };
const MAX_ITEM_QTY = window.foodkingConfig?.maxItemQty ?? 20;

function sanitizeKioskOrderItem(item) {
    return {
        item_id: item.item_id,
        instruction: item.instruction || '',
        quantity: item.quantity,
        item_variations: Array.isArray(item.item_variations) ? item.item_variations : [],
        item_extras: Array.isArray(item.item_extras) ? item.item_extras : [],
    };
}

export function buildKioskOrderPayload(state, { orderType, paymentMethod } = {}) {
    return {
        // branch_id is intentionally omitted: kiosk orders resolve it server-side from KioskMachine.
        order_type: orderType || state.orderType || 25,
        loyalty_code: state.loyaltyCustomer?.loyalty_code || null,
        // Promo code remains non-financial metadata; the backend recomputes the discount.
        kiosk_promo_code: state.promoCode || null,
        is_advance_order: 0,
        source: SOURCE_KIOSK,
        payment_method: PAYMENT_METHOD_MAP[paymentMethod] ?? PAYMENT_METHOD_MAP.cash,
        items: JSON.stringify(state.items.map(sanitizeKioskOrderItem)),
    };
}

export const kioskCart = {
    namespaced: true,
    state: {
        items: [],
        orderRef: null,
        queueNumber: null,
        upsellShown: false,
        loyaltyCustomer: null,
        loyaltyDiscount: 0,
        // Kiosk Phase 9.1.6 — Code promo branch-scoped (kiosk_promos) ou
        // coupon global. Validé lecture-seule via POST /promo/validate, la
        // consommation réelle (increment uses_count) n'intervient qu'à la
        // création de commande côté serveur (SSOT).
        promoCode: null,
        promoDiscount: 0,
        promoMeta: null,      // { type: 'percent'|'amount', value, message, kind: 'kiosk'|'coupon' }
        promoError: null,     // string i18n key OU message serveur (affiché sous l'input)
        promoLoading: false,
        branchId: null,
        idempotencyKey: null,
        kioskToken: null,
        kioskMachineId: null,
        paymentMethod: null,
        // [GAP-22-1] Sur place (25=KIOSK) ou à emporter (10=TAKEAWAY)
        orderType: 25,
    },
    getters: {
        items: (state) => state.items,
        count: (state) => state.items.reduce((sum, i) => sum + i.quantity, 0),
        kioskToken: (state) => state.kioskToken,
        isAuthenticated: (state) => !!state.kioskToken,
        orderType: (state) => state.orderType,
        subtotal: (state) =>
            state.items.reduce((sum, i) => {
                const base = parseFloat(i.convert_price) || 0;
                const varExtra = parseFloat(i.item_variation_total) || 0;
                const extras   = parseFloat(i.item_extra_total) || 0;
                return sum + (base + varExtra + extras) * i.quantity;
            }, 0),
        orderRef: (state) => state.orderRef,
        queueNumber: (state) => state.queueNumber,
        upsellShown: (state) => state.upsellShown,
        loyaltyCustomer: (state) => state.loyaltyCustomer,
        loyaltyDiscount: (state) => state.loyaltyDiscount,
        promoCode: (state) => state.promoCode,
        promoDiscount: (state) => state.promoDiscount,
        promoMeta: (state) => state.promoMeta,
        promoError: (state) => state.promoError,
        promoLoading: (state) => state.promoLoading,
        branchId: (state) => state.branchId,
        isEmpty: (state) => state.items.length === 0,
        // Kiosk Phase 9.1.6 — Total cumule loyalty + promo, jamais négatif.
        // SSOT : ce total reste purement local (affichage). La vérité finale
        // est recalculée serveur par PricingService à POST /frontend/order.
        total: (state, getters) => Math.max(
            0,
            getters.subtotal - state.loyaltyDiscount - state.promoDiscount,
        ),
    },
    mutations: {
        ADD_ITEM(state, item) {
            const existing = state.items.findIndex(i =>
                i.item_id === item.item_id &&
                JSON.stringify(i.item_variations) === JSON.stringify(item.item_variations) &&
                JSON.stringify(i.item_extras) === JSON.stringify(item.item_extras) &&
                (i.instruction || '') === (item.instruction || '')
            );
            if (existing >= 0) {
                const qty = Math.min(state.items[existing].quantity + (item.quantity || 1), MAX_ITEM_QTY);
                state.items[existing].quantity = qty;
                // [KIOSK-17] Keep line total in sync when merging identical items
                const base = parseFloat(state.items[existing].convert_price) || 0;
                const varE = parseFloat(state.items[existing].item_variation_total) || 0;
                const ext  = parseFloat(state.items[existing].item_extra_total) || 0;
                state.items[existing].total = parseFloat(((base + varE + ext) * qty).toFixed(2));
            } else {
                // [PHASE9 W-P1-5 FIX] Clamp quantity even for new lines (previously
                // only merged lines were clamped, creating an asymmetric contract
                // where a fresh-line quantity=99 was shipped as-is to server).
                const rawQty = Number(item.quantity || 1);
                const newItem = {
                    ...item,
                    quantity: Math.max(1, Math.min(Number.isFinite(rawQty) ? Math.floor(rawQty) : 1, MAX_ITEM_QTY)),
                };
                // Ensure total is always present
                if (!newItem.total) {
                    const base = parseFloat(newItem.convert_price) || 0;
                    const varE = parseFloat(newItem.item_variation_total) || 0;
                    const ext  = parseFloat(newItem.item_extra_total) || 0;
                    newItem.total = parseFloat(((base + varE + ext) * newItem.quantity).toFixed(2));
                }
                state.items.push(newItem);
            }
        },
        REMOVE_ITEM(state, index) {
            state.items.splice(index, 1);
        },
        UPDATE_QUANTITY(state, { index, quantity }) {
            if (quantity <= 0) {
                state.items.splice(index, 1);
            } else {
                state.items[index].quantity = Math.min(quantity, MAX_ITEM_QTY);
                // [KIOSK-17] Keep line total in sync when quantity changes
                const base = parseFloat(state.items[index].convert_price) || 0;
                const varE = parseFloat(state.items[index].item_variation_total) || 0;
                const ext  = parseFloat(state.items[index].item_extra_total) || 0;
                state.items[index].total = parseFloat(((base + varE + ext) * quantity).toFixed(2));
            }
        },
        SET_ORDER_REF(state, { orderId, queueNumber }) {
            state.orderRef = orderId;
            state.queueNumber = queueNumber;
        },
        SET_UPSELL_SHOWN(state, val) {
            state.upsellShown = val;
        },
        SET_LOYALTY(state, { customer, discount }) {
            state.loyaltyCustomer = customer;
            state.loyaltyDiscount = discount || 0;
        },
        // Kiosk Phase 9.1.6 — Promo state mutations.
        SET_PROMO_LOADING(state, value) {
            state.promoLoading = !!value;
        },
        SET_PROMO(state, { code, discount, meta }) {
            state.promoCode = code || null;
            state.promoDiscount = Math.max(0, parseFloat(discount) || 0);
            state.promoMeta = meta || null;
            state.promoError = null;
        },
        SET_PROMO_ERROR(state, message) {
            state.promoError = message || null;
            state.promoDiscount = 0;
            state.promoMeta = null;
        },
        CLEAR_PROMO(state) {
            state.promoCode = null;
            state.promoDiscount = 0;
            state.promoMeta = null;
            state.promoError = null;
            state.promoLoading = false;
        },
        SET_BRANCH(state, branchId) {
            state.branchId = branchId;
        },
        SET_IDEMPOTENCY_KEY(state, key) {
            state.idempotencyKey = key;
        },
        SET_KIOSK_TOKEN(state, { token, machineId }) {
            state.kioskToken = token || null;
            // [GAP-34-2] Re-inject kiosk token into Echo auth headers after kiosk login.
            if (typeof window !== 'undefined' && typeof window._refreshEchoAuth === 'function') {
                window._refreshEchoAuth();
            }
            state.kioskMachineId = machineId || null;
        },
        CLEAR_KIOSK_TOKEN(state) {
            state.kioskToken = null;
            state.kioskMachineId = null;
        },
        SET_PAYMENT_METHOD(state, method) {
            state.paymentMethod = method || null;
        },
        // [GAP-22-1] Set order type: 25=KIOSK (sur place), 10=TAKEAWAY (à emporter)
        SET_ORDER_TYPE(state, orderType) {
            state.orderType = orderType || 25;
        },
        RESET(state) {
            state.items = [];
            state.orderRef = null;
            state.queueNumber = null;
            state.upsellShown = false;
            state.loyaltyCustomer = null;
            state.loyaltyDiscount = 0;
            state.idempotencyKey = null;
            state.paymentMethod = null;
            state.orderType = 25;
            // Kiosk Phase 9.1.6 — promo reset au retour idle (comme loyalty).
            state.promoCode = null;
            state.promoDiscount = 0;
            state.promoMeta = null;
            state.promoError = null;
            state.promoLoading = false;
        },
    },
    actions: {
        /**
         * Authenticate this kiosk machine against the backend.
         * Stores the Sanctum token in state (persisted via vuex-persistedstate).
         * The app.js interceptor will pick it up automatically.
         */
        async kioskLogin({ commit }, { username, password }) {
            const res = await axios.post('auth/kiosk-login', {
                username: String(username || '').trim(),
                password,
            });
            const token = res?.data?.token;
            const machineId = res?.data?.kiosk?.id || null;
            if (!token) throw new Error('No token received');
            commit('SET_KIOSK_TOKEN', { token, machineId });
            return res.data;
        },
        async kioskLogout({ commit, state }) {
            try {
                if (state.kioskToken) {
                    await axios.post('auth/kiosk-logout');
                }
            } catch (_) { /* best-effort */ }
            commit('CLEAR_KIOSK_TOKEN');
            commit('RESET');
        },
        addItem({ commit }, item) {
            commit('ADD_ITEM', item);
        },
        removeItem({ commit }, index) {
            commit('REMOVE_ITEM', index);
        },
        /**
         * Remove an item by index and return it (used to pre-populate wizard on edit).
         */
        popItem({ commit, state }, index) {
            const item = state.items[index];
            if (!item) return null;
            commit('REMOVE_ITEM', index);
            return { ...item };
        },
        updateQuantity({ commit }, payload) {
            commit('UPDATE_QUANTITY', payload);
        },
        markUpsellShown({ commit }) {
            commit('SET_UPSELL_SHOWN', true);
        },
        setBranch({ commit }, branchId) {
            commit('SET_BRANCH', branchId);
        },
        setLoyalty({ commit }, payload) {
            commit('SET_LOYALTY', payload);
        },
        // Kiosk Phase 9.1.6 — Valide un code promo en lecture-seule via
        // POST /api/frontend/promo/validate. Renvoie la meta côté appelant
        // pour affichage ; stocke dans state si valide, nettoie sinon.
        //
        // Invariants :
        //  - `branch_id` n'est JAMAIS envoyé (lu serveur via KioskMachine).
        //  - `cart_total` est envoyé pour permettre au serveur d'appliquer
        //    `min_cart` mais le montant de discount reste calculé serveur.
        //  - Aucune écriture DB ici (pas d'increment uses_count) : la
        //    consommation réelle intervient uniquement à /frontend/order.
        async validatePromo({ commit, getters }, rawCode) {
            const code = String(rawCode || '').trim();
            if (!code) {
                commit('SET_PROMO_ERROR', 'kiosk.promo.error.empty');
                return { valid: false, reason: 'empty' };
            }
            commit('SET_PROMO_LOADING', true);
            try {
                const cartTotal = getters.subtotal || 0;
                const res = await axios.post('frontend/promo/validate', {
                    code,
                    cart_total: cartTotal,
                });
                const data = res?.data?.data || {};
                const valid = !!(res?.data?.status);
                if (valid) {
                    commit('SET_PROMO', {
                        code,
                        discount: parseFloat(data.discount || 0) || 0,
                        meta: {
                            type: data.type || null,
                            value: data.value ?? null,
                            kind: data.kind || null,
                            message: res.data.message || null,
                        },
                    });
                    return { valid: true, data };
                }
                commit('SET_PROMO_ERROR', res?.data?.message || 'kiosk.promo.error.invalid');
                return { valid: false, message: res?.data?.message || null };
            } catch (err) {
                const message = err?.response?.data?.message || 'kiosk.promo.error.network';
                commit('SET_PROMO_ERROR', message);
                return { valid: false, message };
            } finally {
                commit('SET_PROMO_LOADING', false);
            }
        },
        clearPromo({ commit }) {
            commit('CLEAR_PROMO');
        },
        // [GAP-22-1] Store the order type chosen by the customer (sur place / à emporter)
        setOrderType({ commit }, orderType) {
            commit('SET_ORDER_TYPE', orderType);
        },
        reset({ commit }) {
            commit('RESET');
        },
        submitOrder({ commit, state }, { orderType, paymentMethod } = {}) {
            return new Promise((resolve, reject) => {
                loadSnapshot().then(snap => {
                    if (snap && isSnapshotStale(snap.savedAt)) {
                        console.warn('[Kiosk] Menu snapshot is stale (>4h). Server will recalculate prices at order time (SSOT).');
                    }
                }).catch(() => {});

                // Store payment method for receipt printing
                commit('SET_PAYMENT_METHOD', paymentMethod || 'cash');

                // [SPLASH SECURITY] Generate idempotency key once per session cart.
                // Stored in state so retries/double-tap reuse the same key.
                let idempotencyKey = state.idempotencyKey;
                if (!idempotencyKey) {
                    idempotencyKey = ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
                        (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
                    commit('SET_IDEMPOTENCY_KEY', idempotencyKey);
                }

                const orderPayload = buildKioskOrderPayload(state, {
                    orderType,
                    paymentMethod,
                });

                axios.post('frontend/order', orderPayload, {
                    headers: { 'X-Idempotency-Key': idempotencyKey },
                }).then((res) => {
                    const orderId = res.data?.data?.id || res.data?.id;
                    const queueNumber = res.data?.data?.queue_number || res.data?.queue_number;
                    commit('SET_ORDER_REF', { orderId, queueNumber });
                    resolve(res);
                }).catch((err) => {
                    // [SPLASH OFFLINE MODE] If network is unavailable, queue locally.
                    // The order will be synced automatically when connectivity returns.
                    const isNetworkError = !err.response || err.response?.status >= 500;
                    if (isNetworkError) {
                        // [FIX-54-3] Preserve original idempotency key for offline replay
                        const localKey = saveOrder(orderPayload, idempotencyKey);
                        // Start background sync so it retries when network comes back
                        // [AUDIT-P0] Pass config (headers) so syncQueue can send X-Idempotency-Key
                        startAutoSync((url, data, config) => axios.post(url, data, config || {}));
                        // Return a synthetic "offline" response so the UI can proceed
                        const offlineRes = {
                            data: {
                                data: {
                                    id: localKey,
                                    queue_number: '—',
                                    _offline: true,
                                },
                            },
                        };
                        commit('SET_ORDER_REF', { orderId: localKey, queueNumber: '—' });
                        resolve(offlineRes);
                    } else {
                        reject(err);
                    }
                });
            });
        },

        /**
         * Return how many orders are queued offline (for UI badge).
         */
        getOfflinePendingCount() {
            return getPendingCount();
        },
        fetchOrderStatus(_, orderId) {
            // Utiliser l'endpoint show comme frontendOrder/show
            return new Promise((resolve, reject) => {
                axios.get(`frontend/order/show/${orderId}`).then(resolve).catch(reject);
            });
        },
        fetchUpsellItems({ state }) {
            return new Promise((resolve) => {
                // [SPLASH MERCHANDISING] Use smart kiosk-upsell endpoint
                // Sends item IDs in cart so backend can suggest complementary items
                const itemIds = state.items.map(i => i.item_id).join(',');
                const url = itemIds
                    ? `frontend/item/kiosk-upsell?item_ids=${itemIds}&limit=6`
                    : 'frontend/item/kiosk-upsell?limit=6';
                axios
                    .get(url)
                    .then(resolve)
                    .catch(() => resolve({ data: { data: [] } }));
            });
        },
    },
};
